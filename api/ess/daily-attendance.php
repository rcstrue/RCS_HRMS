<?php
/**
 * ESS API — Daily Attendance (Supervisor/Manager)
 *
 * Allows supervisors and managers to mark daily attendance
 * (Present/Absent/Half Day/Leave/Weekly Off/Holiday) for employees in their units.
 *
 * GET:  Fetch employees for a unit + date with existing attendance
 * POST: Bulk-save attendance records for a date
 *
 * IMPORTANT: ess_attendance is a SHARED table with employee clock-in/check-out.
 * Clock-in/out (attendance.php) may create MULTIPLE records per employee per day.
 * We identify supervisor-marked records by marked_by IS NOT NULL.
 * This coexists safely with employee self-attendance records.
 *
 * Schema requirement: marked_by column must exist. Run migration separately:
 *   ALTER TABLE ess_attendance ADD COLUMN marked_by VARCHAR(50) DEFAULT NULL AFTER note;
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':  _handleGet();  break;
        case 'POST': _handleSave(); break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    // Log full technical details server-side only
    error_log('[daily-attendance] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Return safe user-facing error — never expose paths or stack traces
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ─── Helper: Check if caller has access to a unit ──────────────────────────
// Exact copy of team-summary.php's _checkUnitAccess (proven working pattern).
// Preserves all 4 access tiers: user_access (name + id), legacy, client-level, own-unit.

function _checkUnitAccess(mysqli $conn, string $employeeId, int $unitId, string $callerRole): bool
{
    if ($callerRole === 'admin' || $callerRole === 'regional_manager') return true;

    $codeStmt = $conn->prepare('SELECT employee_code FROM ess_employee_cache WHERE employee_id = ?');
    $codeStmt->bind_param('s', $employeeId);
    $codeStmt->execute();
    $codeRow = $codeStmt->get_result()->fetch_assoc();
    $codeStmt->close();
    $employeeCode = trim($codeRow['employee_code'] ?? '');

    $nameStmt = $conn->prepare('SELECT id, name, client_id FROM units WHERE id = ?');
    $nameStmt->bind_param('i', $unitId);
    $nameStmt->execute();
    $unitRow = $nameStmt->get_result()->fetch_assoc();
    $unitName = trim($unitRow['name'] ?? '');
    $unitClientId = (int)($unitRow['client_id'] ?? 0);
    $nameStmt->close();

    if (empty($unitName)) return false;

    // Check 1: user_access by unit name
    if (!empty($employeeCode)) {
        $accStmt = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
        $accStmt->bind_param('ss', $employeeCode, $unitName);
        $accStmt->execute();
        $hasAccess = $accStmt->get_result()->num_rows > 0;
        $accStmt->close();
        if ($hasAccess) return true;

        // Check 1b: user_access by unit ID (numeric string)
        $accStmt2 = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
        $unitIdStr = (string)$unitId;
        $accStmt2->bind_param('ss', $employeeCode, $unitIdStr);
        $accStmt2->execute();
        $hasAccess2 = $accStmt2->get_result()->num_rows > 0;
        $accStmt2->close();
        if ($hasAccess2) return true;
    }

    // Check 2: emp_city_allocations (legacy)
    $legacyStmt = $conn->prepare("SELECT 1 FROM emp_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' AND allocation_value = ?");
    $legacyStmt->bind_param('ss', $employeeId, $unitName);
    $legacyStmt->execute();
    $hasLegacy = $legacyStmt->get_result()->num_rows > 0;
    $legacyStmt->close();
    if ($hasLegacy) return true;

    // Check 3: Client-level access
    if ($unitClientId > 0 && !empty($employeeCode)) {
        $clientStmt = $conn->prepare("
            SELECT 1 FROM units u
            INNER JOIN user_access ua ON ua.access_type = 'unit' AND (ua.access_id = u.name OR ua.access_id = CAST(u.id AS CHAR))
            WHERE u.client_id = ? AND ua.user_id = ?
            LIMIT 1
        ");
        $clientStmt->bind_param('is', $unitClientId, $employeeCode);
        $clientStmt->execute();
        $hasClientAccess = $clientStmt->get_result()->num_rows > 0;
        $clientStmt->close();
        if ($hasClientAccess) return true;
    }

    // Check 4: Own unit
    $ownStmt = $conn->prepare('SELECT unit_id FROM ess_employee_cache WHERE employee_id = ?');
    $ownStmt->bind_param('s', $employeeId);
    $ownStmt->execute();
    $ownRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();
    return ($ownRow && (int)$ownRow['unit_id'] === $unitId);
}

// ─── Helper: Validate date string and check business rules ────────────────

function _validateDate(string $date, bool $isPost): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        return null;
    }

    $parts = explode('-', $date);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        jsonOutput(['success' => false, 'error' => 'Invalid date — not a real calendar date'], 400);
        return null;
    }

    // Block future dates for marking attendance
    $today = date('Y-m-d');
    if ($date > $today) {
        jsonOutput(['success' => false, 'error' => 'Cannot mark attendance for a future date'], 400);
        return null;
    }

    return $date;
}

// ─── GET: Fetch employees + their attendance for a date ──────────────────────

function _handleGet(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'regional_manager', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $rawDate = $_GET['date'] ?? date('Y-m-d');
    $unitId  = (int)($_GET['unit_id'] ?? 0);

    $date = _validateDate($rawDate, false);
    if ($date === null) return;

    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'unit_id is required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // Single JOIN query: employees + attendance in one round-trip.
    $stmt = $conn->prepare("
        SELECT
            e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
            u.name AS unit_name,
            att.id    AS att_id,
            att.status AS att_status,
            att.note   AS att_note,
            att.marked_by AS att_marked_by
        FROM employees e
        JOIN units u ON u.id = e.unit_id
        LEFT JOIN ess_attendance att
            ON att.employee_id = e.id
            AND att.date = ?
            AND att.marked_by IS NOT NULL
        WHERE e.unit_id = ?
          AND e.status = 'approved'
        ORDER BY e.full_name ASC
    ");
    $stmt->bind_param('si', $date, $unitId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    $summary = ['present' => 0, 'absent' => 0, 'half_day' => 0, 'leave' => 0, 'weekly_off' => 0, 'holiday' => 0, 'unmarked' => 0];

    while ($row = $result->fetch_assoc()) {
        $status = $row['att_status'] ?? '';
        $items[] = [
            'employee_id'     => (int)$row['id'],
            'employee_code'   => $row['employee_code'] ?? '',
            'full_name'       => $row['full_name'] ?? '',
            'designation'     => $row['designation'] ?? '',
            'worker_category' => $row['worker_category'] ?? '',
            'unit_name'       => $row['unit_name'] ?? '',
            'status'          => $status,
            'note'            => $row['att_note'] ?? '',
            'attendance_id'   => $row['att_id'] !== null ? (int)$row['att_id'] : null,
            'marked_by'       => $row['att_marked_by'] ?? '',
        ];

        switch ($status) {
            case 'present': case 'late': $summary['present']++;     break;
            case 'absent':                   $summary['absent']++;     break;
            case 'half_day':                 $summary['half_day']++;   break;
            case 'leave':                    $summary['leave']++;      break;
            case 'weekly_off':               $summary['weekly_off']++; break;
            case 'holiday':                  $summary['holiday']++;    break;
            default:                         $summary['unmarked']++;   break;
        }
    }
    $stmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'date'      => $date,
            'unit_id'   => $unitId,
            'unit_name' => $items[0]['unit_name'] ?? '',
            'items'     => $items,
            'summary'   => $summary,
        ]
    ]);
}

// ─── POST: Bulk save attendance records ──────────────────────────────────────

function _handleSave(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'regional_manager', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $rawDate = trim($input['date'] ?? '');
    $unitId  = (int)($input['unit_id'] ?? 0);
    $records = $input['records'] ?? [];

    $date = _validateDate($rawDate, true);
    if ($date === null) return;

    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Unit ID is required'], 400);
    }
    if (!is_array($records) || empty($records)) {
        jsonOutput(['success' => false, 'error' => 'Records array is required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // ── Per-employee validation + transactional save ──
    $saved  = 0;
    $errors = [];

    error_log('[daily-attendance] POST input: date=' . $date . ' unit_id=' . $unitId . ' records_count=' . count($records));

    try {
        $conn->begin_transaction();

        foreach ($records as $idx => $rec) {
            $empId  = (int)($rec['employee_id'] ?? 0);
            $status = trim($rec['status'] ?? '');
            $note   = trim($rec['note'] ?? '');

            if ($empId <= 0) {
                $errors[] = "Row $idx: missing employee_id";
                continue;
            }
            if (!in_array($status, ['present', 'absent', 'half_day', 'leave', 'weekly_off', 'holiday'], true)) {
                $errors[] = "Row $idx: invalid status '$status'";
                continue;
            }

            // ── Verify employee belongs to the requested unit ──
            error_log("[daily-attendance] Row $idx: verifying emp=$empId unit=$unitId");
            $vStmt = $conn->prepare('SELECT 1 FROM employees WHERE id = ? AND unit_id = ? AND status = \'approved\' LIMIT 1');
            $vStmt->bind_param('ii', $empId, $unitId);
            $vStmt->execute();
            $belongs = $vStmt->get_result()->num_rows > 0;
            $vStmt->close();

            if (!$belongs) {
                $errors[] = "Row $idx: employee_id $empId does not belong to unit $unitId";
                continue;
            }

            // ── Upsert: check for ANY existing record for this employee+date ──
            // We must check all records (not just marked_by IS NOT NULL) because
            // ess_attendance may have a UNIQUE constraint on (employee_id, date).
            // If employee already self-checked in (marked_by IS NULL), we UPDATE
            // that record to add supervisor marking rather than INSERTing a new one.
            error_log("[daily-attendance] Row $idx: finding existing record emp=$empId date=$date");
            $empIdStr = (string)$empId;
            $fStmt = $conn->prepare('SELECT id, marked_by FROM ess_attendance WHERE employee_id = ? AND date = ? ORDER BY marked_by IS NULL LIMIT 1');
            $fStmt->bind_param('ss', $empIdStr, $date);
            $fStmt->execute();
            $existing = $fStmt->get_result()->fetch_assoc();
            $fStmt->close();

            if ($existing) {
                error_log("[daily-attendance] Row $idx: updating att_id=" . (int)$existing['id'] . ' existing_marked_by=' . ($existing['marked_by'] ?? 'NULL'));
                // Update only daily-attendance-owned fields.
                // Do NOT touch check_in, check_out, latitude, longitude.
                $uStmt = $conn->prepare('UPDATE ess_attendance SET status = ?, note = ?, marked_by = ?, updated_at = NOW() WHERE id = ?');
                $markerId = (string)$employeeId;
                $existId = (int)$existing['id'];
                $uStmt->bind_param('sssi', $status, $note, $markerId, $existId);
                $uStmt->execute();
                $uStmt->close();
            } else {
                error_log("[daily-attendance] Row $idx: inserting new record emp=$empId date=$date status=$status");
                // Insert new supervisor-marked record.
                // Does NOT set check_in/check_out — those remain NULL.
                $iStmt = $conn->prepare('INSERT INTO ess_attendance (employee_id, date, status, note, marked_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                $empIdStr2 = (string)$empId;
                $markerId2 = (string)$employeeId;
                $iStmt->bind_param('sssss', $empIdStr2, $date, $status, $note, $markerId2);
                $iStmt->execute();
                $iStmt->close();
            }
            $saved++;
        }

        $conn->commit();
    } catch (\Throwable $e) {
        // Attempt rollback — ignore errors during rollback itself
        try { $conn->rollback(); } catch (\Throwable $rbErr) {
            error_log('[daily-attendance rollback] ' . $rbErr->getMessage());
        }
        error_log('[daily-attendance save] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        // TEMP DEBUG: expose actual error to find root cause — REMOVE AFTER FIX
        jsonOutput(['success' => false, 'error' => 'Failed to save attendance. Please try again.', 'debug_exception' => $e->getMessage(), 'debug_file' => $e->getFile(), 'debug_line' => $e->getLine()], 500);
    }

    $conn->close();

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
