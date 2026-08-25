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
 * Pattern: mirrors team-summary.php exactly (auth, access check, response shape).
 * Auto-creates/alters ess_attendance table as needed (no manual SQL required).
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';

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
    $errMsg = $e->getMessage();
    error_log('[api/ess/daily-attendance] ' . $errMsg . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => $errMsg . ' (' . $e->getFile() . ':' . $e->getLine() . ')'], 500);
}

// ─── Helper: Ensure ess_attendance table has required schema ────────────────
// The ess_attendance table already exists (used by attendance.php for check-in/check-out).
// We only need to add: marked_by column, and UNIQUE KEY on (employee_id, date).
// This is safe to run on every request — it's idempotent (IF NOT EXISTS / column checks).

function _ensureSchema(mysqli $conn): void
{
    // 1. Add marked_by column if missing
    $col = $conn->query("SHOW COLUMNS FROM ess_attendance LIKE 'marked_by'");
    if ($col->num_rows === 0) {
        $conn->query("ALTER TABLE ess_attendance ADD COLUMN marked_by VARCHAR(50) DEFAULT NULL AFTER note");
    }
    $col->free();

       // 2. Add UNIQUE KEY on (employee_id, date) if missing
    //    First, dedupe any existing duplicates (keep latest row)
    $idx = $conn->query("SHOW INDEX FROM ess_attendance WHERE Key_name = 'uk_emp_date'");
    if ($idx->num_rows === 0) {
        // Delete older duplicates, keeping the row with highest id
        $conn->query("
            DELETE t1 FROM ess_attendance t1
            INNER JOIN ess_attendance t2
            ON t1.employee_id = t2.employee_id AND t1.date = t2.date AND t1.id < t2.id
        ");
        $conn->query("ALTER TABLE ess_attendance ADD UNIQUE KEY uk_emp_date (employee_id, date)");
    }
    $idx->free();
}

// ─── Helper: Check if caller has access to a unit ──────────────────────────
// Exact copy of team-summary.php's _checkUnitAccess (proven working pattern).

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

    // Check 1: user_access by unit name (existing)
    if (!empty($employeeCode)) {
        $accStmt = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
        $accStmt->bind_param('ss', $employeeCode, $unitName);
        $accStmt->execute();
        $hasAccess = $accStmt->get_result()->num_rows > 0;
        $accStmt->close();
        if ($hasAccess) return true;

        // Check 1b: user_access by unit ID (numeric string)
        $accStmt2 = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
        $accStmt2->bind_param('ss', $employeeCode, (string)$unitId);
        $accStmt2->execute();
        $hasAccess2 = $accStmt2->get_result()->num_rows > 0;
        $accStmt2->close();
        if ($hasAccess2) return true;
    }

    // Check 2: employee_city_allocations (legacy)
    $legacyStmt = $conn->prepare("SELECT 1 FROM employee_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' AND allocation_value = ?");
    $legacyStmt->bind_param('ss', $employeeId, $unitName);
    $legacyStmt->execute();
    $hasLegacy = $legacyStmt->get_result()->num_rows > 0;
    $legacyStmt->close();
    if ($hasLegacy) return true;

    // Check 3: Manager has access to ANY unit under the same client → allow all client units
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

// ─── GET: Fetch employees + their attendance for a date ──────────────────────

function _handleGet(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    // Auth check (same as team-summary)
    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'regional_manager', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $date   = $_GET['date'] ?? date('Y-m-d');
    $unitId = (int)($_GET['unit_id'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
    }
    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'unit_id is required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // Ensure schema is ready
    _ensureSchema($conn);

    // Fetch active employees in this unit (same query pattern as team-summary)
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
            SELECT id, employee_id, status, note, marked_by
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
                'marked_by' => $row['marked_by'] ?? '',
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
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    // Auth check (same as team-summary)
    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'regional_manager', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

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

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // Ensure schema is ready
    _ensureSchema($conn);

    $validStatuses = ['present', 'absent', 'half_day', 'leave', 'weekly_off', 'holiday'];
    $saved = 0;
    $errors = [];

    // Upsert using INSERT ... ON DUPLICATE KEY UPDATE
    // (requires UNIQUE KEY uk_emp_date on (employee_id, date) — ensured by _ensureSchema)
    $upsertStmt = $conn->prepare("
        INSERT INTO ess_attendance (employee_id, date, status, note, marked_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
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
