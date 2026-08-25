<?php
/**
 * ESS API — Daily Attendance (Supervisor/Manager)
 *
 * Allows supervisors and managers to mark daily attendance
 * (Present/Absent/Half Day/Leave) for employees under their units.
 *
 * GET:  Fetch employees for a unit + date with existing attendance
 * POST: Bulk-save attendance records for a date
 *
 * Access control: mirrors access.php resolution exactly.
 * Allocation source: user_access table (user_id = employee_code, access_id = unit name or ID)
 * Fallback: employee_city_allocations, then own unit.
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
    jsonOutput(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
}

// ─── Schema notes (run once manually if needed) ───────────────────────────
// ALTER TABLE ess_attendance ADD COLUMN marked_by VARCHAR(20) DEFAULT NULL AFTER note;
// ALTER TABLE ess_attendance ADD INDEX idx_marked_by (marked_by);
// DELETE t1 FROM ess_attendance t1 INNER JOIN ess_attendance t2 ON t1.employee_id = t2.employee_id AND t1.date = t2.date AND t1.id < t2.id;
// ALTER TABLE ess_attendance ADD UNIQUE KEY uk_emp_date (employee_id, date);

// ─── GET: Fetch employees + their attendance for a date ──────────────────────

function _handleGet(): void
{
    $employeeId = requireRole(ESS_GUARD_ROLES_SUPERVISOR);
    $conn = getDbConnection();

    $date   = $_GET['date'] ?? date('Y-m-d');
    $unitId = (int)($_GET['unit_id'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
    }

    // Access check (same resolution as access.php)
    if (!_checkUnitAccess($employeeId, $unitId, $conn)) {
        jsonOutput(['success' => false, 'error' => 'Access denied for this unit'], 403);
    }

    // Fetch active employees in this unit
    $empStmt = $conn->prepare("
        SELECT e.id, e.employee_code, e.full_name, e.designation,
               e.worker_category,
               u.name AS unit_name
        FROM employees e
        JOIN units u ON u.id = e.unit_id
        WHERE e.unit_id = ?
          AND e.status IN ('approved', 'active')
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

    $date   = trim($input['date'] ?? '');
    $unitId = (int)($input['unit_id'] ?? 0);
    $records = $input['records'] ?? [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format'], 400);
    }
    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Unit ID is required'], 400);
    }
    if (!is_array($records) || empty($records)) {
        jsonOutput(['success' => false, 'error' => 'Records array is required'], 400);
    }

    // Access check (same resolution as access.php)
    if (!_checkUnitAccess($employeeId, $unitId, $conn)) {
        jsonOutput(['success' => false, 'error' => 'Access denied for this unit'], 403);
    }

    $validStatuses = ['present', 'absent', 'half_day', 'leave', 'weekly_off', 'holiday'];
    $saved = 0;
    $errors = [];

    // Single prepared statement for upsert (atomic, no race condition)
    $upsertStmt = $conn->prepare("
        INSERT INTO ess_attendance (employee_id, date, status, note, marked_by, check_in, created_at)
        VALUES (?, ?, ?, ?, ?, '00:00:00', NOW())
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            note = VALUES(note),
            marked_by = VALUES(marked_by),
            updated_at = NOW()
    ");

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

        $upsertStmt->bind_param('sssss', (string)$empId, $date, $status, $note, (string)$employeeId);
        $upsertStmt->execute();
        $saved++;
    }
    $upsertStmt->close();

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

// ─── Access check: mirrors access.php resolution exactly ─────────────────────
// Instead of resolving ALL allowed units and comparing, we verify
// that the specific requested unit is accessible by the user.
// This matches team-summary.php's _checkUnitAccess pattern.

function _checkUnitAccess(string $employeeId, int $unitId, mysqli $conn): bool
{
    if ($unitId <= 0) return false;

    // Get employee_code + role info
    $empStmt = $conn->prepare('SELECT employee_code, app_role, designation FROM employees WHERE id = ?');
    $intId = (int)$employeeId;
    $empStmt->bind_param('i', $intId);
    $empStmt->execute();
    $empRow = $empStmt->get_result()->fetch_assoc();
    $empStmt->close();

    $employeeCode = trim($empRow['employee_code'] ?? '');
    $role = strtolower(trim($empRow['app_role'] ?? ''));
    $designation = strtolower(trim($empRow['designation'] ?? ''));

    if (empty($employeeCode)) return false;

    // Get the unit name and client_id for the requested unit
    $unitStmt = $conn->prepare('SELECT name, client_id FROM units WHERE id = ? AND is_active = 1');
    $unitStmt->bind_param('i', $unitId);
    $unitStmt->execute();
    $unitRow = $unitStmt->get_result()->fetch_assoc();
    $unitStmt->close();

    $unitName = trim($unitRow['name'] ?? '');
    $unitClientId = (int)($unitRow['client_id'] ?? 0);
    if (empty($unitName)) return false;

    // Check 1: user_access by unit name (PRIMARY — same as access.php)
    $accStmt = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
    $accStmt->bind_param('ss', $employeeCode, $unitName);
    $accStmt->execute();
    $hasAccess = $accStmt->get_result()->num_rows > 0;
    $accStmt->close();
    if ($hasAccess) return true;

    // Check 2: user_access by unit ID (numeric string — e.g. access_id = '137')
    $accStmt2 = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
    $accStmt2->bind_param('ss', $employeeCode, (string)$unitId);
    $accStmt2->execute();
    $hasAccess2 = $accStmt2->get_result()->num_rows > 0;
    $accStmt2->close();
    if ($hasAccess2) return true;

    // Check 3: employee_city_allocations (legacy — same as access.php fallback)
    $legacyStmt = $conn->prepare("SELECT 1 FROM employee_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' AND allocation_value = ?");
    $legacyStmt->bind_param('ss', $employeeId, $unitName);
    $legacyStmt->execute();
    $hasLegacy = $legacyStmt->get_result()->num_rows > 0;
    $legacyStmt->close();
    if ($hasLegacy) return true;

    // Check 4: Client-level access (same as team-summary.php)
    // If manager has ANY unit under the same client → allow all client units
    if ($unitClientId > 0) {
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

    // Check 5: Own unit (for HK Supervisor / Forklift / manager with no allocations)
    $isManager = in_array($role, ['manager', 'supervisor', 'regional_manager'], true);
    $isAutoAssign = (strpos($designation, 'hk supervisor') !== false
                  || strpos($designation, 'forklift driver') !== false
                  || strpos($designation, 'fork lift driver') !== false);

    if ($isManager || $isAutoAssign) {
        $ownUnit = getEmployeeUnitId($employeeId, $conn);
        if ($ownUnit === $unitId) return true;
    }

    return false;
}
