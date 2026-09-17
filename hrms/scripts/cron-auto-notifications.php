<?php
/**
 * RCS HRMS — Cron Worker: Auto-Notification Generator
 *
 * Run via cron every 15–30 minutes:
 *   cd /path/to/hrms && php scripts/cron-auto-notifications.php >> /var/log/auto-notif-cron.log 2>&1
 *
 * Architecture:
 *   - Generates notifications for 15 event types (attendance, leaves, birthdays, etc.)
 *   - Queues both push (push_notification_queue) and in-app (ess_notifications)
 *   - Per-type enable/disable via settings: auto_notif_{type}_enabled
 *   - Dedup via last-run tracking: auto_notif_{type}_last_run
 *   - One type failing does not prevent others from running
 *
 * Notification types:
 *   1.  attendance_missing    — no attendance entry for today
 *   2.  shift_starting        — 15 min before shift (requires shift table)
 *   3.  leave_status          — leave approved/rejected notification
 *   4.  late_attendance       — attendance after grace time
 *   5.  absent_alert          — absent 3+ consecutive days
 *   6.  ot_pending            — overtime awaiting approval
 *   7.  document_expiry       — documents expiring within 30 days
 *   8.  birthday              — birthday today
 *   9.  anniversary           — work anniversary today
 *   10. leave_balance         — quarterly leave balance reminder
 *   11. payslip_available     — payroll processed for month
 *   12. salary_credited       — payroll completed
 *   13. pf_esic_update        — compliance filing reminders
 *   14. announcements         — (manual only, skip)
 *   15. helpdesk_ticket       — ticket status change notification
 */

define('RCS_HRMS', true);
require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/database.php';

$now       = date('Y-m-d H:i:s');
$today     = date('Y-m-d');
$currentTime = date('H:i:s');
$month     = (int)date('n');
$year      = (int)date('Y');

$stats = ['types_run' => 0, 'push_queued' => 0, 'inapp_queued' => 0, 'errors' => 0];

echo "[auto-notif] $now — Starting auto-notification generator\n";

// ── Self-heal: ensure push_notification_queue has required columns ────────────
try {
    $cols = $db->fetchAll("SHOW COLUMNS FROM push_notification_queue");
    $colNames = array_column($cols, 'Field');
    $migrations = [
        'attempt_count'  => "INT UNSIGNED DEFAULT 0",
        'max_attempts'   => "TINYINT UNSIGNED DEFAULT 5",
        'next_retry_at'  => "DATETIME DEFAULT NULL",
        'last_error'     => "TEXT DEFAULT NULL",
    ];
    foreach ($migrations as $col => $def) {
        if (!in_array($col, $colNames)) {
            $db->exec("ALTER TABLE push_notification_queue ADD COLUMN `$col` $def");
            echo "[auto-notif] Migrated: added column `$col`\n";
        }
    }
} catch (\Throwable $e) {
    echo "[auto-notif] Migration check failed: " . $e->getMessage() . "\n";
}

// ── Global enable check ──────────────────────────────────────────────────────
$globalEnabled = getSetting('auto_notif_enabled', '1');
if ($globalEnabled !== '1') {
    exit("[auto-notif] $now — Auto-notifications disabled (auto_notif_enabled != 1). Exiting.\n");
}

// ── Helper: check if a table exists ──────────────────────────────────────────
function tableExistsSafe($table) {
    global $db;
    try {
        return $db->tableExists($table);
    } catch (\Throwable $e) {
        return false;
    }
}

// ── Helper: queue a push notification ────────────────────────────────────────
function queuePush($title, $body, $employeeIds, $url = '', $scheduledAt = null) {
    global $db, $stats;
    if (empty($employeeIds)) return 0;

    $empIdStr = is_array($employeeIds) ? implode(',', $employeeIds) : $employeeIds;
    try {
        $db->insert('push_notification_queue', [
            'title'        => $title,
            'body'         => $body,
            'url'          => $url ?: null,
            'target'       => 'selected',
            'employee_ids' => $empIdStr,
            'status'       => 'pending',
            'scheduled_at' => $scheduledAt ?: $now ?? date('Y-m-d H:i:s'),
            'created_by'   => 0, // system
            'created_at'   => date('Y-m-d H:i:s'),
            'attempt_count' => 0,
            'max_attempts'  => 5,
        ]);
        $stats['push_queued']++;
        return 1;
    } catch (\Throwable $e) {
        echo "[auto-notif] Push queue error: " . $e->getMessage() . "\n";
        return 0;
    }
}

// ── Helper: queue in-app notifications ───────────────────────────────────────
function queueInApp($employeeIds, $title, $message, $type = 'info', $link = '', $senderId = 0, $targetType = 'individual') {
    global $db, $stats;
    if (empty($employeeIds)) return 0;

    $count = 0;
    foreach ($employeeIds as $empId) {
        try {
            $db->insert('ess_notifications', [
                'employee_id'  => (int)$empId,
                'title'        => $title,
                'message'      => $message,
                'type'         => $type,
                'link'         => $link ?: null,
                'is_read'      => 0,
                'created_at'   => date('Y-m-d H:i:s'),
                'sender_id'    => $senderId,
                'target_type'  => $targetType,
            ]);
            $count++;
            $stats['inapp_queued']++;
        } catch (\Throwable $e) {
            // skip this one, continue with others
        }
    }
    return $count;
}

// ── Helper: check if a type should run based on frequency ────────────────────
// Returns true if the type hasn't run within the given cooldown period
function shouldRun($type, $minIntervalSeconds = 300) {
    $lastRun = getSetting("auto_notif_{$type}_last_run", '');
    if (empty($lastRun)) return true;
    $diff = time() - strtotime($lastRun);
    return $diff >= $minIntervalSeconds;
}

// ── Helper: mark type as run ─────────────────────────────────────────────────
function markRun($type) {
    updateSetting("auto_notif_{$type}_last_run", date('Y-m-d H:i:s'));
}

// ── Helper: get approved employees query fragment ────────────────────────────
// employees.status = 'approved' is the only valid "active" status
function approvedEmployeeCondition() {
    return "e.status = 'approved'";
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 1: Attendance Missing
// ════════════════════════════════════════════════════════════════════════════
function processAttendanceMissing() {
    global $db, $today;
    $type = 'attendance_missing';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 1800)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    // Find approved employees with no attendance record for today
    // Try both `attendance` table and `attendance_summary` depending on schema
    $empIds = [];

    try {
        // Check if daily attendance table exists
        if (tableExistsSafe('attendance')) {
            $rows = $db->fetchAll("
                SELECT e.id
                FROM employees e
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.date = ?
                WHERE " . approvedEmployeeCondition() . "
                AND a.id IS NULL
            ", [$today]);
        } else {
            // Fallback: no daily table — cannot detect, skip
            echo "[auto-notif] $type — no 'attendance' table, skipping\n";
            markRun($type);
            return;
        }

        $empIds = array_column($rows, 'id');
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — query error: " . $e->getMessage() . "\n";
        return;
    }

    if (empty($empIds)) {
        echo "[auto-notif] $type — no missing attendance found\n";
        markRun($type);
        return;
    }

    $count = count($empIds);
    echo "[auto-notif] $type — found $count employee(s) with missing attendance\n";

    queuePush(
        'Attendance Reminder',
        'You have not marked your attendance for today. Please mark it now.',
        $empIds,
        'index.php?page=attendance'
    );
    queueInApp(
        $empIds,
        'Attendance Reminder',
        'You have not marked your attendance for today. Please mark it now.',
        'warning',
        'index.php?page=attendance'
    );

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 2: Shift Starting Soon
// ════════════════════════════════════════════════════════════════════════════
function processShiftStarting() {
    global $db, $currentTime;
    $type = 'shift_starting';
    // Default disabled — requires shift data that may not exist
    $enabled = getSetting("auto_notif_{$type}_enabled", '0');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 900)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    if (!tableExistsSafe('employee_shifts') && !tableExistsSafe('shifts')) {
        echo "[auto-notif] $type — no shift tables found, skipping\n";
        markRun($type);
        return;
    }

    try {
        // Find shifts starting within 15 minutes for approved employees
        $shiftTable = tableExistsSafe('employee_shifts') ? 'employee_shifts' : 'shifts';

        $rows = $db->fetchAll("
            SELECT DISTINCT es.employee_id
            FROM {$shiftTable} es
            INNER JOIN employees e ON e.id = es.employee_id
            WHERE " . approvedEmployeeCondition() . "
            AND es.shift_date = CURDATE()
            AND es.shift_start BETWEEN CURTIME() AND DATE_ADD(CURTIME(), INTERVAL 15 MINUTE)
        ");

        $empIds = array_column($rows, 'id');
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — query error: " . $e->getMessage() . "\n";
        return;
    }

    if (empty($empIds)) {
        echo "[auto-notif] $type — no upcoming shifts\n";
        markRun($type);
        return;
    }

    echo "[auto-notif] $type — found " . count($empIds) . " employee(s) with shifts starting soon\n";

    queuePush(
        'Shift Starting Soon',
        'Your shift starts in 15 minutes. Please prepare to mark attendance.',
        $empIds,
        'index.php?page=attendance'
    );
    queueInApp(
        $empIds,
        'Shift Starting Soon',
        'Your shift starts in 15 minutes. Please prepare to mark attendance.',
        'info',
        'index.php?page=attendance'
    );

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 3: Leave Approved/Rejected
// ════════════════════════════════════════════════════════════════════════════
function processLeaveStatus() {
    global $db;
    $type = 'leave_status';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 300)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        $lastRun = getSetting("auto_notif_{$type}_last_run", date('Y-m-d H:i:s', strtotime('-1 hour')));

        // Find leaves that were approved/rejected since last run
        // Check if ess_leaves has updated_at column
        $leaveCols = $db->fetchAll("SHOW COLUMNS FROM ess_leaves");
        $leaveColNames = array_column($leaveCols, 'Field');

        if (in_array('updated_at', $leaveColNames)) {
            $rows = $db->fetchAll("
                SELECT id, employee_id, leave_type, start_date, end_date, status
                FROM ess_leaves
                WHERE status IN ('approved', 'rejected')
                AND updated_at >= ?
                AND updated_at IS NOT NULL
                ORDER BY updated_at ASC
            ", [$lastRun]);
        } else {
            // Fallback: check leaves approved/rejected recently by approved_by being set
            $rows = $db->fetchAll("
                SELECT id, employee_id, leave_type, start_date, end_date, status
                FROM ess_leaves
                WHERE status IN ('approved', 'rejected')
                AND approved_by IS NOT NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY id ASC
            ");
        }

        foreach ($rows as $leave) {
            $empId = (int)$leave['employee_id'];
            $status = ucfirst($leave['status']);
            $leaveType = $leave['leave_type'] ?? 'Leave';
            $title = "Leave {$status}";
            $message = "Your {$leaveType} request ({$leave['start_date']} to {$leave['end_date']}) has been {$status}.";

            queuePush($title, $message, [$empId], 'index.php?page=portal/leaves');
            queueInApp([$empId], $title, $message, $leave['status'] === 'approved' ? 'success' : 'warning', 'index.php?page=portal/leaves');
        }

        if (!empty($rows)) {
            echo "[auto-notif] $type — processed " . count($rows) . " leave status change(s)\n";
        } else {
            echo "[auto-notif] $type — no recent leave status changes\n";
        }
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 4: Late Attendance
// ════════════════════════════════════════════════════════════════════════════
function processLateAttendance() {
    global $db, $today;
    $type = 'late_attendance';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 1800)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        if (!tableExistsSafe('attendance')) {
            echo "[auto-notif] $type — no 'attendance' table, skipping\n";
            markRun($type);
            return;
        }

        // Get grace time from settings (default 9:15 AM for Indian offices)
        $graceTime = getSetting('attendance_grace_time', '09:15:00');

        // Find employees who marked attendance after grace time today
        $attCols = $db->fetchAll("SHOW COLUMNS FROM attendance");
        $attColNames = array_column($attCols, 'Field');

        if (in_array('check_in', $attColNames)) {
            $rows = $db->fetchAll("
                SELECT a.employee_id
                FROM attendance a
                INNER JOIN employees e ON e.id = a.employee_id
                WHERE " . approvedEmployeeCondition() . "
                AND a.date = ?
                AND a.check_in > ?
            ", [$today, $graceTime]);
        } elseif (in_array('in_time', $attColNames)) {
            $rows = $db->fetchAll("
                SELECT a.employee_id
                FROM attendance a
                INNER JOIN employees e ON e.id = a.employee_id
                WHERE " . approvedEmployeeCondition() . "
                AND a.date = ?
                AND a.in_time > ?
            ", [$today, $graceTime]);
        } else {
            echo "[auto-notif] $type — no recognized time column in attendance, skipping\n";
            markRun($type);
            return;
        }

        $empIds = array_column($rows, 'employee_id');
        $empIds = array_unique($empIds);
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — query error: " . $e->getMessage() . "\n";
        return;
    }

    if (empty($empIds)) {
        echo "[auto-notif] $type — no late arrivals found\n";
        markRun($type);
        return;
    }

    $count = count($empIds);
    echo "[auto-notif] $type — found $count late arrival(s)\n";

    queuePush(
        'Late Attendance',
        'You have marked attendance after the grace time today. This will be recorded as a late entry.',
        $empIds,
        'index.php?page=attendance'
    );
    queueInApp(
        $empIds,
        'Late Attendance',
        'You have marked attendance after the grace time today. This will be recorded as a late entry.',
        'warning',
        'index.php?page=attendance'
    );

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 5: Absent Alert (3+ consecutive days)
// ════════════════════════════════════════════════════════════════════════════
function processAbsentAlert() {
    global $db, $today;
    $type = 'absent_alert';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 3600)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Use attendance_summary for absent tracking if available
        if (tableExistsSafe('attendance_summary')) {
            // Employees with 3+ absent days in current month
            $rows = $db->fetchAll("
                SELECT e.id, e.full_name, a.absent_days
                FROM employees e
                INNER JOIN attendance_summary a ON a.employee_id = e.id
                WHERE " . approvedEmployeeCondition() . "
                AND a.month = ?
                AND a.year = ?
                AND a.absent_days >= 3
            ", [$month, $year]);
        } elseif (tableExistsSafe('attendance')) {
            // Count absent days in last 7 working days from daily attendance
            $rows = $db->fetchAll("
                SELECT e.id, e.full_name, COUNT(*) as absent_days
                FROM employees e
                INNER JOIN attendance a ON a.employee_id = e.id
                WHERE " . approvedEmployeeCondition() . "
                AND a.date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
                AND a.status = 'absent'
                GROUP BY e.id
                HAVING COUNT(*) >= 3
            ");
        } else {
            echo "[auto-notif] $type — no attendance/summary tables, skipping\n";
            markRun($type);
            return;
        }

        $empIds = array_column($rows, 'id');
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — query error: " . $e->getMessage() . "\n";
        return;
    }

    if (empty($empIds)) {
        echo "[auto-notif] $type — no employees with 3+ absent days\n";
        markRun($type);
        return;
    }

    $count = count($empIds);
    echo "[auto-notif] $type — found $count employee(s) with 3+ absent days\n";

    // Notify HR/admin as well as the employee
    queuePush(
        'Absent Alert',
        'You have been absent for 3 or more consecutive days. Please contact HR if this is incorrect.',
        $empIds,
        'index.php?page=attendance'
    );
    queueInApp(
        $empIds,
        'Absent Alert',
        'You have been absent for 3 or more consecutive days. Please contact HR if this is incorrect.',
        'warning',
        'index.php?page=attendance'
    );

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 6: OT Pending Approval
// ════════════════════════════════════════════════════════════════════════════
function processOtPending() {
    global $db;
    $type = 'ot_pending';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 3600)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Check for overtime entries table
        if (!tableExistsSafe('overtime_entries') && !tableExistsSafe('ot_entries')) {
            echo "[auto-notif] $type — no overtime table found, skipping\n";
            markRun($type);
            return;
        }

        $otTable = tableExistsSafe('overtime_entries') ? 'overtime_entries' : 'ot_entries';

        // Find OT entries pending approval
        $rows = $db->fetchAll("
            SELECT ot.employee_id, e.full_name, COUNT(*) as pending_count
            FROM {$otTable} ot
            INNER JOIN employees e ON e.id = ot.employee_id
            WHERE " . approvedEmployeeCondition() . "
            AND ot.status = 'pending'
            GROUP BY ot.employee_id
        ");

        foreach ($rows as $row) {
            $empId = (int)$row['employee_id'];
            $pendingCount = (int)$row['pending_count'];
            $title = 'Overtime Pending Approval';
            $message = "You have {$pendingCount} overtime entry/entries pending approval.";

            queuePush($title, $message, [$empId], 'index.php?page=entry/overtime-entry');
            queueInApp([$empId], $title, $message, 'info', 'index.php?page=entry/overtime-entry');
        }

        if (!empty($rows)) {
            echo "[auto-notif] $type — found " . count($rows) . " employee(s) with pending OT\n";
        } else {
            echo "[auto-notif] $type — no pending overtime entries\n";
        }
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 7: Document Expiry
// ════════════════════════════════════════════════════════════════════════════
function processDocumentExpiry() {
    global $db;
    $type = 'document_expiry';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 86400)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        if (!tableExistsSafe('employee_documents')) {
            echo "[auto-notif] $type — no 'employee_documents' table, skipping\n";
            markRun($type);
            return;
        }

        // Find documents expiring within 30 days
        $rows = $db->fetchAll("
            SELECT ed.employee_id, ed.document_type, ed.expiry_date,
                   DATEDIFF(ed.expiry_date, CURDATE()) as days_remaining
            FROM employee_documents ed
            INNER JOIN employees e ON e.id = ed.employee_id
            WHERE " . approvedEmployeeCondition() . "
            AND ed.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");

        foreach ($rows as $row) {
            $empId = (int)$row['employee_id'];
            $docType = $row['document_type'] ?? 'Document';
            $days = (int)$row['days_remaining'];
            $title = 'Document Expiry Reminder';
            $message = "Your {$docType} expires in {$days} day(s) (on {$row['expiry_date']}). Please renew it.";

            queuePush($title, $message, [$empId], 'index.php?page=profile');
            queueInApp([$empId], $title, $message, 'warning', 'index.php?page=profile');
        }

        if (!empty($rows)) {
            echo "[auto-notif] $type — found " . count($rows) . " expiring document(s)\n";
        } else {
            echo "[auto-notif] $type — no documents expiring within 30 days\n";
        }
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 8: Birthday
// ════════════════════════════════════════════════════════════════════════════
function processBirthday() {
    global $db;
    $type = 'birthday';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 86400)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Find employees with birthday today
        $rows = $db->fetchAll("
            SELECT id, full_name
            FROM employees e
            WHERE " . approvedEmployeeCondition() . "
            AND DATE_FORMAT(e.date_of_birth, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
        ");

        if (empty($rows)) {
            echo "[auto-notif] $type — no birthdays today\n";
            markRun($type);
            return;
        }

        $birthdayEmpIds = array_column($rows, 'id');

        // Notify the birthday person
        queuePush(
            'Happy Birthday! 🎂',
            'Wishing you a very happy birthday! Have a wonderful day ahead.',
            $birthdayEmpIds,
            'index.php?page=dashboard'
        );
        queueInApp(
            $birthdayEmpIds,
            'Happy Birthday! 🎂',
            'Wishing you a very happy birthday! Have a wonderful day ahead.',
            'success',
            'index.php?page=dashboard'
        );

        // Notify all other employees about birthday(s)
        $allEmpRows = $db->fetchAll("
            SELECT id FROM employees WHERE " . approvedEmployeeCondition() . "
        ");
        $allEmpIds = array_column($allEmpRows, 'id');
        $otherEmpIds = array_diff($allEmpIds, $birthdayEmpIds);

        if (!empty($otherEmpIds) && count($birthdayEmpIds) <= 5) {
            // Only broadcast if a reasonable number of birthdays
            foreach ($rows as $bdayEmp) {
                $name = $bdayEmp['full_name'];
                queuePush(
                    'Birthday Alert 🎂',
                    "It's {$name}'s birthday today! Wish them well.",
                    $otherEmpIds,
                    'index.php?page=dashboard'
                );
                queueInApp(
                    $otherEmpIds,
                    'Birthday Alert 🎂',
                    "It's {$name}'s birthday today! Wish them well.",
                    'info',
                    'index.php?page=dashboard'
                );
            }
        }

        echo "[auto-notif] $type — " . count($birthdayEmpIds) . " birthday(s) today\n";
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 9: Work Anniversary
// ════════════════════════════════════════════════════════════════════════════
function processAnniversary() {
    global $db;
    $type = 'anniversary';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 86400)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Find employees whose work anniversary is today (date_of_joining matches today's month-day)
        // Must have joined at least 1 year ago
        $rows = $db->fetchAll("
            SELECT id, full_name, date_of_joining,
                   YEAR(CURDATE()) - YEAR(e.date_of_joining) as years
            FROM employees e
            WHERE " . approvedEmployeeCondition() . "
            AND DATE_FORMAT(e.date_of_joining, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
            AND e.date_of_joining < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
        ");

        if (empty($rows)) {
            echo "[auto-notif] $type — no work anniversaries today\n";
            markRun($type);
            return;
        }

        $anniversaryEmpIds = array_column($rows, 'id');

        foreach ($rows as $row) {
            $empId = (int)$row['id'];
            $years = (int)$row['years'];
            $name = $row['full_name'];
            $title = 'Work Anniversary! 🎉';
            $message = "Congratulations on completing {$years} year(s) with us! Thank you for your dedication.";

            queuePush($title, $message, [$empId], 'index.php?page=dashboard');
            queueInApp([$empId], $title, $message, 'success', 'index.php?page=dashboard');
        }

        // Notify all other employees
        $allEmpRows = $db->fetchAll("
            SELECT id FROM employees WHERE " . approvedEmployeeCondition() . "
        ");
        $allEmpIds = array_column($allEmpRows, 'id');
        $otherEmpIds = array_diff($allEmpIds, $anniversaryEmpIds);

        if (!empty($otherEmpIds) && count($anniversaryEmpIds) <= 5) {
            foreach ($rows as $annEmp) {
                $name = $annEmp['full_name'];
                $years = (int)$annEmp['years'];
                queuePush(
                    'Work Anniversary 🎉',
                    "{$name} completed {$years} year(s) with us today!",
                    $otherEmpIds,
                    'index.php?page=dashboard'
                );
                queueInApp(
                    $otherEmpIds,
                    'Work Anniversary 🎉',
                    "{$name} completed {$years} year(s) with us today!",
                    'info',
                    'index.php?page=dashboard'
                );
            }
        }

        echo "[auto-notif] $type — " . count($anniversaryEmpIds) . " anniversary(ies) today\n";
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 10: Leave Balance Reminder (Quarterly)
// ════════════════════════════════════════════════════════════════════════════
function processLeaveBalance() {
    global $db, $month;
    $type = 'leave_balance';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }

    // Only run quarterly: month 1 (Jan), 4 (Apr), 7 (Jul), 10 (Oct)
    $quarterlyMonths = [1, 4, 7, 10];
    if (!in_array($month, $quarterlyMonths)) {
        echo "[auto-notif] $type — not a quarterly month, skipping\n";
        return;
    }
    // Run once per quarter: 24-hour cooldown effectively ensures once/day,
    // but the quarterly check ensures it only fires in the right months
    if (!shouldRun($type, 86400)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Get all approved employees
        $rows = $db->fetchAll("
            SELECT id, full_name FROM employees WHERE " . approvedEmployeeCondition() . "
        ");

        if (empty($rows)) {
            echo "[auto-notif] $type — no employees found\n";
            markRun($type);
            return;
        }

        $empIds = array_column($rows, 'id');
        $quarter = 'Q' . ceil($month / 3);

        queuePush(
            'Leave Balance Reminder',
            "This is your quarterly leave balance reminder for {$quarter} {$year}. Check your leave balances.",
            $empIds,
            'index.php?page=portal/leaves'
        );
        queueInApp(
            $empIds,
            'Leave Balance Reminder',
            "This is your quarterly leave balance reminder for {$quarter} {$year}. Check your leave balances.",
            'info',
            'index.php?page=portal/leaves'
        );

        echo "[auto-notif] $type — notified " . count($empIds) . " employee(s)\n";
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 11: Payslip Available
// ════════════════════════════════════════════════════════════════════════════
function processPayslipAvailable() {
    global $db, $month, $year;
    $type = 'payslip_available';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 3600)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        if (!tableExistsSafe('payroll_periods')) {
            echo "[auto-notif] $type — no 'payroll_periods' table, skipping\n";
            markRun($type);
            return;
        }

        // Find recently completed payroll periods (status = 'completed' or 'processed')
        $lastRun = getSetting("auto_notif_{$type}_last_run", date('Y-m-d H:i:s', strtotime('-2 hours')));

        $periods = $db->fetchAll("
            SELECT id, month, year
            FROM payroll_periods
            WHERE status IN ('completed', 'processed')
            AND updated_at >= ?
        ", [$lastRun]);

        if (empty($periods)) {
            // Try without updated_at
            $ppCols = $db->fetchAll("SHOW COLUMNS FROM payroll_periods");
            $ppColNames = array_column($ppCols, 'Field');

            if (in_array('updated_at', $ppColNames)) {
                echo "[auto-notif] $type — no recently completed payroll periods\n";
                markRun($type);
                return;
            }

            // Check if there's a completed period for previous month that we haven't notified about
            $prevMonth = $month - 1;
            $prevYear = $year;
            if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

            $notifiedKey = "auto_notif_payslip_notified_{$prevMonth}_{$prevYear}";
            $alreadyNotified = getSetting($notifiedKey, '0');

            if ($alreadyNotified === '1') {
                echo "[auto-notif] $type — already notified for {$prevMonth}/{$prevYear}\n";
                markRun($type);
                return;
            }

            $periods = $db->fetchAll("
                SELECT id, month, year
                FROM payroll_periods
                WHERE status IN ('completed', 'processed')
                AND month = ? AND year = ?
            ", [$prevMonth, $prevYear]);
        }

        if (empty($periods)) {
            echo "[auto-notif] $type — no completed payroll periods to notify about\n";
            markRun($type);
            return;
        }

        foreach ($periods as $period) {
            $pMonth = (int)$period['month'];
            $pYear = (int)$period['year'];
            $monthName = date('F', mktime(0, 0, 0, $pMonth, 1));

            $rows = $db->fetchAll("
                SELECT id FROM employees WHERE " . approvedEmployeeCondition() . "
            ");
            $empIds = array_column($rows, 'id');

            if (empty($empIds)) continue;

            $title = 'Payslip Available';
            $message = "Your payslip for {$monthName} {$pYear} is now available. View it from the portal.";

            queuePush($title, $message, $empIds, 'index.php?page=portal/payslips');
            queueInApp($empIds, $title, $message, 'success', 'index.php?page=portal/payslips');

            // Mark this period as notified
            updateSetting("auto_notif_payslip_notified_{$pMonth}_{$pYear}", '1');

            echo "[auto-notif] $type — payslip for {$monthName} {$pYear}: notified " . count($empIds) . " employee(s)\n";
        }
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 12: Salary Credited
// ════════════════════════════════════════════════════════════════════════════
function processSalaryCredited() {
    global $db, $month, $year;
    $type = 'salary_credited';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 3600)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        if (!tableExistsSafe('payroll_periods')) {
            echo "[auto-notif] $type — no 'payroll_periods' table, skipping\n";
            markRun($type);
            return;
        }

        // Find completed payroll for previous month that hasn't been salary-notified yet
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

        $notifiedKey = "auto_notif_salary_notified_{$prevMonth}_{$prevYear}";
        $alreadyNotified = getSetting($notifiedKey, '0');

        if ($alreadyNotified === '1') {
            echo "[auto-notif] $type — already notified for {$prevMonth}/{$prevYear}\n";
            markRun($type);
            return;
        }

        $period = $db->fetch("
            SELECT id, month, year
            FROM payroll_periods
            WHERE status IN ('completed', 'processed')
            AND month = ? AND year = ?
        ", [$prevMonth, $prevYear]);

        if (!$period) {
            echo "[auto-notif] $type — no completed payroll for previous month\n";
            markRun($type);
            return;
        }

        $monthName = date('F', mktime(0, 0, 0, $prevMonth, 1));

        $rows = $db->fetchAll("
            SELECT id FROM employees WHERE " . approvedEmployeeCondition() . "
        ");
        $empIds = array_column($rows, 'id');

        if (empty($empIds)) {
            echo "[auto-notif] $type — no employees found\n";
            markRun($type);
            return;
        }

        $title = 'Salary Credited';
        $message = "Your salary for {$monthName} {$prevYear} has been credited to your bank account.";

        queuePush($title, $message, $empIds, 'index.php?page=portal/payslips');
        queueInApp($empIds, $title, $message, 'success', 'index.php?page=portal/payslips');

        updateSetting($notifiedKey, '1');
        echo "[auto-notif] $type — notified " . count($empIds) . " employee(s) for {$monthName} {$prevYear}\n";
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 13: PF/ESIC Update (Compliance Filing Reminder)
// ════════════════════════════════════════════════════════════════════════════
function processPfEsicUpdate() {
    global $db, $month, $year;
    $type = 'pf_esic_update';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    // Only run on 1st-5th of each month (filing deadline reminder)
    $dayOfMonth = (int)date('j');
    if ($dayOfMonth > 5) {
        echo "[auto-notif] $type — past filing reminder window (day $dayOfMonth), skipping\n";
        return;
    }
    if (!shouldRun($type, 86400)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Notify admin/HR about PF/ESIC filing
        $rows = $db->fetchAll("
            SELECT id FROM employees
            WHERE status = 'approved'
            AND designation IN ('admin', 'HR Manager', 'HR Executive', 'Compliance Officer')
        ");

        // If no compliance-specific roles found, notify all admin-role employees
        if (empty($rows)) {
            $rows = $db->fetchAll("
                SELECT id FROM employees
                WHERE status = 'approved'
                AND designation = 'admin'
            ");
        }

        $empIds = array_column($rows, 'id');

        if (empty($empIds)) {
            echo "[auto-notif] $type — no admin/HR employees found for compliance reminder\n";
            markRun($type);
            return;
        }

        $monthName = date('F');
        $deadline = date('jS F Y', strtotime('last day of this month'));

        $title = 'PF/ESIC Filing Reminder';
        $message = "Reminder: PF and ESIC returns for {$monthName} {$year} need to be filed. Deadline: {$deadline}.";

        queuePush($title, $message, $empIds, 'index.php?page=compliance');
        queueInApp($empIds, $title, $message, 'warning', 'index.php?page=compliance');

        echo "[auto-notif] $type — notified " . count($empIds) . " admin/HR employee(s)\n";
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 14: Announcements — handled manually by admin, skip
// ════════════════════════════════════════════════════════════════════════════
function processAnnouncements() {
    echo "[auto-notif] announcements — handled manually by admin, skipping\n";
}

// ════════════════════════════════════════════════════════════════════════════
// NOTIFICATION TYPE 15: Helpdesk Ticket Update
// ════════════════════════════════════════════════════════════════════════════
function processHelpdeskTicket() {
    global $db;
    $type = 'helpdesk_ticket';
    $enabled = getSetting("auto_notif_{$type}_enabled", '1');
    if ($enabled !== '1') { echo "[auto-notif] $type — disabled, skipping\n"; return; }
    if (!shouldRun($type, 300)) { echo "[auto-notif] $type — cooldown not elapsed, skipping\n"; return; }

    echo "[auto-notif] $type — checking...\n";

    try {
        // Check for helpdesk tickets table
        if (!tableExistsSafe('helpdesk_tickets')) {
            echo "[auto-notif] $type — no 'helpdesk_tickets' table, skipping\n";
            markRun($type);
            return;
        }

        $lastRun = getSetting("auto_notif_{$type}_last_run", date('Y-m-d H:i:s', strtotime('-1 hour')));

        // Check if table has updated_at column
        $ticketCols = $db->fetchAll("SHOW COLUMNS FROM helpdesk_tickets");
        $ticketColNames = array_column($ticketCols, 'Field');

        if (in_array('updated_at', $ticketColNames)) {
            $rows = $db->fetchAll("
                SELECT id, employee_id, subject, status
                FROM helpdesk_tickets
                WHERE updated_at >= ?
                AND status IN ('in_progress', 'resolved', 'closed', 'reopened')
                ORDER BY updated_at ASC
            ", [$lastRun]);
        } else {
            // Fallback: can't detect recent changes, skip
            echo "[auto-notif] $type — no 'updated_at' column on helpdesk_tickets, skipping\n";
            markRun($type);
            return;
        }

        foreach ($rows as $ticket) {
            $empId = (int)$ticket['employee_id'];
            $status = ucfirst(str_replace('_', ' ', $ticket['status']));
            $subject = $ticket['subject'] ?? 'your ticket';
            $title = 'Ticket Update';
            $message = "Your helpdesk ticket \"{$subject}\" has been updated to: {$status}.";

            queuePush($title, $message, [$empId], 'index.php?page=portal');
            queueInApp([$empId], $title, $message, 'info', 'index.php?page=portal');
        }

        if (!empty($rows)) {
            echo "[auto-notif] $type — processed " . count($rows) . " ticket update(s)\n";
        } else {
            echo "[auto-notif] $type — no recent ticket updates\n";
        }
    } catch (\Throwable $e) {
        echo "[auto-notif] $type — error: " . $e->getMessage() . "\n";
        return;
    }

    markRun($type);
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN: Execute all notification types
// ════════════════════════════════════════════════════════════════════════════

$processors = [
    'attendance_missing',
    'shift_starting',
    'leave_status',
    'late_attendance',
    'absent_alert',
    'ot_pending',
    'document_expiry',
    'birthday',
    'anniversary',
    'leave_balance',
    'payslip_available',
    'salary_credited',
    'pf_esic_update',
    'announcements',
    'helpdesk_ticket',
];

$processorMap = [
    'attendance_missing' => 'processAttendanceMissing',
    'shift_starting'     => 'processShiftStarting',
    'leave_status'       => 'processLeaveStatus',
    'late_attendance'    => 'processLateAttendance',
    'absent_alert'       => 'processAbsentAlert',
    'ot_pending'         => 'processOtPending',
    'document_expiry'    => 'processDocumentExpiry',
    'birthday'           => 'processBirthday',
    'anniversary'        => 'processAnniversary',
    'leave_balance'      => 'processLeaveBalance',
    'payslip_available'  => 'processPayslipAvailable',
    'salary_credited'    => 'processSalaryCredited',
    'pf_esic_update'     => 'processPfEsicUpdate',
    'announcements'      => 'processAnnouncements',
    'helpdesk_ticket'    => 'processHelpdeskTicket',
];

foreach ($processors as $type) {
    try {
        $func = $processorMap[$type];
        $func();
        $stats['types_run']++;
    } catch (\Throwable $e) {
        $stats['errors']++;
        echo "[auto-notif] FATAL: {$type} threw unhandled exception: " . $e->getMessage() . "\n";
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
$end = date('Y-m-d H:i:s');
echo "[auto-notif] $end — Complete. Types run: {$stats['types_run']}, Push queued: {$stats['push_queued']}, In-app queued: {$stats['inapp_queued']}, Errors: {$stats['errors']}\n";
