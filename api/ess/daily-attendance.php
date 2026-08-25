<?php
/**
 * ESS API — Daily Attendance (Supervisor/Manager)
 *
 * Allows supervisors and managers to mark daily attendance
 * (Present/Absent/Half Day/Leave) for employees under their units.
 *
 * GET:  Fetch employees for a unit + date with existing attendance
 * POST: Bulk-save attendance records for a date
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/auth-guard.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGet();
            break;
        case 'POST':
            _handleSave();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    error_log('[ESS daily-attendance] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error'], 500);
}

// ─── Ensure table has marked_by column ───────────────────────────────────────

function ensureMarkedByColumn(mysqli $conn): void
{
    $col = $conn->query("SHOW COLUMNS FROM ess_attendance LIKE 'marked_by'");
    if ($col->num_rows === 0) {
        $conn->query("ALTER TABLE ess_attendance ADD COLUMN marked_by VARCHAR(20) DEFAULT NULL AFTER note");
        $conn->query("ALTER TABLE ess_attendance ADD INDEX idx_marked_by (marked_by)");
    }
    $col->close();
}

// ─── GET: Fetch employees + their attendance for a date ──────────────────────

function _handleGet(): void
{
    $employeeId = requireRole(ESS_GUARD_ROLES_SUPERVISOR);
    $conn = getDbConnection();
    ensureMarkedByColumn($conn);

    $date   = $_GET['date'] ?? date('Y-m-d');
    $unitId = (int)($_GET['unit_id'] ?? 0);

    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
    }

    // Access: determine which units this user can see
    $allowedUnitIds = _getAllowedUnitIds($employeeId, $conn);
    if (empty($allowedUnitIds)) {
        jsonOutput(['success' => false, 'error' => 'No unit allocation found'], 403);
    }

    // If a specific unit_id is requested, verify it's in allowed list
    if ($unitId > 0 && !in_array($unitId, $allowedUnitIds, true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied for this unit'], 403);
    }

    // If no unit specified, use first allowed unit
    if ($unitId === 0) {
        $unitId = $allowedUnitIds[0];
    }

    // Fetch active employees in this unit
    $empStmt = $conn->prepare("
        SELECT e.id, e.employee_code, e.full_name, e.designation,
               e.worker_category,
               u.name AS unit_name
        FROM employees e
        JOIN units u ON u.id = e.unit_id
        WHERE e.unit_id = ?
          AND e.status = 'active'
          AND e.approved = 1
        ORDER BY e.full_name ASC
    ");
    $empStmt->bind_param('i', $unitId);
    $empStmt->execute();
    $employees = [];
    while ($row = $empStmt->get_result()->fetch_assoc()) {
        $employees[] = [
            'id'             => (int)$row['id'],
            'employee_code'  => $row['employee_code'] ?? '',
            'full_name'      => $row['full_name'] ?? '',
            'designation'    => $row['designation'] ?? '',
            'worker_category'=> $row['worker_category'] ?? '',
            'unit_name'      => $row['unit_name'] ?? '',
        ];
    }
    $empStmt->close();

    // Fetch existing attendance for these employees on this date
    $empIds = array_map(fn($e) => $e['id'], $employees);
    $attendanceMap = [];
    if (!empty($empIds)) {
        $placeholders = implode(',', array_fill(0, count($empIds), '?'));
        $attStmt = $conn->prepare("
            SELECT id, employee_id, status, note, check_in, marked_by
            FROM ess_attendance
            WHERE employee_id IN ($placeholders) AND date = ?
        ");
        $params = array_map('strval', $empIds);
        $params[] = $date;
        $types = str_repeat('s', count($empIds)) . 's';
        $attStmt->bind_param($types, ...$params);
        $attStmt->execute();
        while ($row = $attStmt->get_result()->fetch_assoc()) {
            $attendanceMap[$row['employee_id']] = [
                'id'        => (int)$row['id'],
                'status'    => $row['status'] ?? '',
                'note'      => $row['note'] ?? '',
                'check_in'  => $row['check_in'] ?? '',
                'marked_by'=> $row['marked_by'] ?? '',
            ];
        }
        $attStmt->close();
    }

    // Merge: attach attendance to each employee
    $items = [];
    foreach ($employees as $emp) {
        $att = $attendanceMap[(string)$emp['id']] ?? null;
        $items[] = [
            'employee_id'     => $emp['id'],
            'employee_code'   => $emp['employee_code'],
            'full_name'       => $emp['full_name'],
            'designation'     => $emp['designation'],
            'worker_category' => $emp['worker_category'],
            'unit_name'       => $emp['unit_name'],
            'status'          => $att ? $att['status'] : '',
            'note'            => $att ? $att['note'] : '',
            'attendance_id'   => $att ? $att['id'] : null,
            'marked_by'       => $att ? $att['marked_by'] : '',
        ];
    }

    // Summary counts
    $summary = ['present' => 0, 'absent' => 0, 'half_day' => 0, 'leave' => 0, 'unmarked' => 0];
    foreach ($items as $item) {
        switch ($item['status']) {
            case 'present': case 'late': $summary['present']++; break;
            case 'absent':                   $summary['absent']++; break;
            case 'half_day':                 $summary['half_day']++; break;
            case 'leave':                    $summary['leave']++; break;
            default:                         $summary['unmarked']++; break;
        }
    }

    jsonOutput([
        'success' => true,
        'data' => [
            'date'        => $date,
            'unit_id'     => $unitId,
            'unit_name'   => $items[0]['unit_name'] ?? '',
            'items'       => $items,
            'summary'     => $summary,
        ]
    ]);
}

// ─── POST: Bulk save attendance records ──────────────────────────────────────

function _handleSave(): void
{
    $employeeId = requireRole(ESS_GUARD_ROLES_SUPERVISOR);
    $input = getInput();
    $conn = getDbConnection();
    ensureMarkedByColumn($conn);

    $date   = trim($input['date'] ?? '');
    $unitId = (int)($input['unit_id'] ?? 0);
    $records = $input['records'] ?? [];

    // Validate
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format'], 400);
    }
    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Unit ID is required'], 400);
    }
    if (!is_array($records) || empty($records)) {
        jsonOutput(['success' => false, 'error' => 'Records array is required'], 400);
    }

    // Access: verify unit is in allowed list
    $allowedUnitIds = _getAllowedUnitIds($employeeId, $conn);
    if (!in_array($unitId, $allowedUnitIds, true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied for this unit'], 403);
    }

    $validStatuses = ['present', 'absent', 'half_day', 'leave', 'weekly_off', 'holiday'];
    $saved = 0;
    $errors = [];

    foreach ($records as $idx => $rec) {
        $empId  = (int)($rec['employee_id'] ?? 0);
        $status = trim($rec['status'] ?? '');
        $note   = trim($rec['note'] ?? '');

        if ($empId <= 0) {
            $errors[] = "Row $idx: missing employee_id";
            continue;
        }
        if (!in_array($status, $validStatuses, true)) {
            $errors[] = "Row $idx: invalid status '$status'";
            continue;
        }

        // Check if a record already exists for this employee+date
        $checkStmt = $conn->prepare("
            SELECT id FROM ess_attendance
            WHERE employee_id = ? AND date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $checkStmt->bind_param('ss', (string)$empId, $date);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            // Update existing record (only if it was supervisor-marked or no check_in)
            $attId = (int)$existing['id'];
            $updateStmt = $conn->prepare("
                UPDATE ess_attendance
                SET status = ?, note = ?, marked_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->bind_param('sssi', $status, $note, (string)$employeeId, $attId);
            $updateStmt->execute();
            $updateStmt->close();
            $saved++;
        } else {
            // Insert new record
            $insertStmt = $conn->prepare("
                INSERT INTO ess_attendance (employee_id, date, status, note, marked_by, check_in, created_at)
                VALUES (?, ?, ?, ?, ?, '00:00:00', NOW())
            ");
            $insertStmt->bind_param('sssss', (string)$empId, $date, $status, $note, (string)$employeeId);
            $insertStmt->execute();
            $insertStmt->close();
            $saved++;
        }
    }

    jsonOutput([
        'success' => true,
        'data' => [
            'saved'  => $saved,
            'total'  => count($records),
            'errors' => $errors,
            'date'   => $date,
            'unit_id'=> $unitId,
        ]
    ]);
}

// ─── Helper: Get allowed unit IDs for a user ──────────────────────────────────

function _getAllowedUnitIds(string $employeeId, mysqli $conn): array
{
    $unitIds = [];

    // From user_access table
    $stmt = $conn->prepare("
        SELECT access_id FROM user_access
        WHERE user_id = ? AND access_type = 'unit'
    ");
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    while ($row = $stmt->get_result()->fetch_assoc()) {
        $uid = (int)$row['access_id'];
        if ($uid > 0) $unitIds[] = $uid;
    }
    $stmt->close();

    // Also add own unit
    $ownUnit = getEmployeeUnitId($employeeId, $conn);
    if ($ownUnit > 0 && !in_array($ownUnit, $unitIds, true)) {
        $unitIds[] = $ownUnit;
    }

    return array_values(array_unique($unitIds));
}
