<?php
/**
 * ESS API — Team Monthly Summary (Attendance + Advances)
 * GET:                Fetch all employees in a unit with attendance + advance data
 * POST (save_advance): Save present/wo/advance amounts for one employee
 * POST (add_temp):     Add a temporary employee (name-only, valid for one month)
 * POST (del_temp):     Remove a temporary employee
 * POST (remove_emp):   Mark a regular employee as 'removed' (left)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGetSummary();
            break;
        case 'POST':
            $input = getInput();
            $action = $input['action'] ?? 'save_advance';
            switch ($action) {
                case 'add_temp':
                    _handleAddTempEmployee($input);
                    break;
                case 'del_temp':
                    _handleDeleteTempEmployee($input);
                    break;
                case 'remove_emp':
                    _handleRemoveEmployee($input);
                    break;
                case 'save_advance':
                default:
                    _handleSaveAdvance($input);
                    break;
            }
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    error_log('[api/ess/team-summary] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ─── Helper: Check if caller has access to a unit ─────────────────────────────
function _checkUnitAccess(mysqli $conn, string $employeeId, int $unitId, string $callerRole): bool
{
    if ($callerRole === 'admin') return true;

    $codeStmt = $conn->prepare('SELECT employee_code FROM ess_employee_cache WHERE employee_id = ?');
    $codeStmt->bind_param('s', $employeeId);
    $codeStmt->execute();
    $codeRow = $codeStmt->get_result()->fetch_assoc();
    $codeStmt->close();
    $employeeCode = trim($codeRow['employee_code'] ?? '');

    $nameStmt = $conn->prepare('SELECT name FROM units WHERE id = ?');
    $nameStmt->bind_param('i', $unitId);
    $nameStmt->execute();
    $unitName = trim($nameStmt->get_result()->fetch_assoc()['name'] ?? '');
    $nameStmt->close();

    if (empty($unitName)) return false;

    if (!empty($employeeCode)) {
        $accStmt = $conn->prepare("SELECT 1 FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?");
        $accStmt->bind_param('ss', $employeeCode, $unitName);
        $accStmt->execute();
        $hasAccess = $accStmt->get_result()->num_rows > 0;
        $accStmt->close();
        if ($hasAccess) return true;
    }

    $legacyStmt = $conn->prepare("SELECT 1 FROM employee_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' AND allocation_value = ?");
    $legacyStmt->bind_param('ss', $employeeId, $unitName);
    $legacyStmt->execute();
    $hasLegacy = $legacyStmt->get_result()->num_rows > 0;
    $legacyStmt->close();
    if ($hasLegacy) return true;

    $ownStmt = $conn->prepare('SELECT unit_id FROM ess_employee_cache WHERE employee_id = ?');
    $ownStmt->bind_param('s', $employeeId);
    $ownStmt->execute();
    $ownRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();
    return ($ownRow && (int)$ownRow['unit_id'] === $unitId);
}

// ─── Helper: Ensure employee_advances has present/wo columns ──────────────────
function _ensureAdvanceColumns(mysqli $conn): void
{
    // Add present and wo columns if they don't exist
    $col = $conn->query("SHOW COLUMNS FROM employee_advances LIKE 'present'");
    if ($col->num_rows === 0) {
        $conn->query("ALTER TABLE employee_advances ADD COLUMN `present` DECIMAL(5,2) DEFAULT NULL AFTER `year`");
    }
    $col->close();

    $col = $conn->query("SHOW COLUMNS FROM employee_advances LIKE 'wo'");
    if ($col->num_rows === 0) {
        $conn->query("ALTER TABLE employee_advances ADD COLUMN `wo` INT DEFAULT NULL AFTER `present`");
    }
    $col->close();
}

// ─── GET: Team Monthly Summary ──────────────────────────────────────────────

function _handleGetSummary(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $unitId  = (int)($_GET['unit_id'] ?? 0);
    $month   = (int)($_GET['month'] ?? date('m'));
    $year    = (int)($_GET['year'] ?? date('Y'));

    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'unit_id is required'], 400);
    }
    if ($month < 1 || $month > 12 || $year < 2000) {
        jsonOutput(['success' => false, 'error' => 'Invalid month or year'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // Ensure tables exist
    _ensureAdvanceColumns($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS temp_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            unit_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            month INT NOT NULL,
            year INT NOT NULL,
            created_by VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_unit_name_month (unit_id, name, month, year),
            KEY idx_unit_month (unit_id, month, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Fetch regular employees
    // Priority: employee_advances.present/wo (manager input) > attendance_summary (system)
    $stmt = $conn->prepare('
        SELECT
            e.id AS emp_id,
            e.employee_code,
            e.full_name,
            COALESCE(adv.present, att.total_present, 0) AS present,
            COALESCE(adv.wo, att.total_wo, 0) AS wo,
            COALESCE(adv.adv1, 0) AS adv1,
            COALESCE(adv.office_advance, 0) AS office_advance,
            COALESCE(adv.dress_advance, 0) AS dress_advance
        FROM employees e
        LEFT JOIN attendance_summary att
            ON att.employee_id = e.id AND att.unit_id = ? AND att.month = ? AND att.year = ?
        LEFT JOIN employee_advances adv
            ON adv.employee_id = CAST(e.id AS CHAR) AND adv.month = ? AND adv.year = ?
        WHERE e.unit_id = ? AND e.status IN (\'approved\', \'active\')
        ORDER BY e.employee_code
    ');

    $stmt->bind_param('iiiiii', $unitId, $month, $year, $month, $year, $unitId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $totals = ['present' => 0, 'wo' => 0, 'adv1' => 0, 'office_advance' => 0, 'dress_advance' => 0];

    while ($row = $result->fetch_assoc()) {
        $present       = (float)$row['present'];
        $wo            = (float)$row['wo'];
        $adv1          = (float)$row['adv1'];
        $officeAdv     = (float)$row['office_advance'];
        $dressAdv      = (float)$row['dress_advance'];

        $rows[] = [
            'employee_id'    => (string)$row['emp_id'],
            'employee_code'  => $row['employee_code'] ?? '',
            'full_name'      => $row['full_name'],
            'present'        => $present,
            'wo'             => $wo,
            'adv1'           => $adv1,
            'office_advance' => $officeAdv,
            'dress_advance'  => $dressAdv,
            'is_temp'        => false,
        ];

        $totals['present']        += $present;
        $totals['wo']             += $wo;
        $totals['adv1']           += $adv1;
        $totals['office_advance'] += $officeAdv;
        $totals['dress_advance']  += $dressAdv;
    }
    $stmt->close();

    // Fetch temp employees
    $tempStmt = $conn->prepare('
        SELECT t.id, t.name,
               COALESCE(adv.present, 0) AS present,
               COALESCE(adv.wo, 0) AS wo,
               COALESCE(adv.adv1, 0) AS adv1,
               COALESCE(adv.office_advance, 0) AS office_advance,
               COALESCE(adv.dress_advance, 0) AS dress_advance
        FROM temp_employees t
        LEFT JOIN employee_advances adv
            ON adv.employee_id = CONCAT("TEMP-", t.id) AND adv.unit_id = t.unit_id
            AND adv.month = t.month AND adv.year = t.year
        WHERE t.unit_id = ? AND t.month = ? AND t.year = ?
        ORDER BY t.name
    ');
    $tempStmt->bind_param('iii', $unitId, $month, $year);
    $tempStmt->execute();
    $tempResult = $tempStmt->get_result();

    while ($tRow = $tempResult->fetch_assoc()) {
        $tPresent   = (float)$tRow['present'];
        $tWo        = (float)$tRow['wo'];
        $tAdv1      = (float)$tRow['adv1'];
        $tOffAdv    = (float)$tRow['office_advance'];
        $tDressAdv  = (float)$tRow['dress_advance'];

        $rows[] = [
            'employee_id'    => 'TEMP-' . $tRow['id'],
            'employee_code'  => '',
            'full_name'      => $tRow['name'] . ' (Temp)',
            'present'        => $tPresent,
            'wo'             => $tWo,
            'adv1'           => $tAdv1,
            'office_advance' => $tOffAdv,
            'dress_advance'  => $tDressAdv,
            'is_temp'        => true,
        ];

        $totals['present']        += $tPresent;
        $totals['wo']             += $tWo;
        $totals['adv1']           += $tAdv1;
        $totals['office_advance'] += $tOffAdv;
        $totals['dress_advance']  += $tDressAdv;
    }
    $tempStmt->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'items'  => $rows,
            'totals' => $totals,
            'count'  => count($rows),
        ]
    ]);
}

// ─── POST: Save present/wo/advance for one employee ───────────────────────────

function _handleSaveAdvance(array $input): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $targetEmpId = trim($input['employee_id'] ?? '');
    $unitId      = (int)($input['unit_id'] ?? 0);
    $month       = (int)($input['month'] ?? 0);
    $year        = (int)($input['year'] ?? 0);
    $present     = ($input['present'] !== null && $input['present'] !== '') ? (float)$input['present'] : null;
    $wo          = ($input['wo'] !== null && $input['wo'] !== '') ? (int)$input['wo'] : null;
    $adv1        = (float)($input['adv1'] ?? 0);
    $officeAdv   = (float)($input['office_advance'] ?? 0);
    $dressAdv    = (float)($input['dress_advance'] ?? 0);

    if (empty($targetEmpId) || $unitId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        jsonOutput(['success' => false, 'error' => 'employee_id, unit_id, month, and year are required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    if ($adv1 < 0 || $officeAdv < 0 || $dressAdv < 0) {
        jsonOutput(['success' => false, 'error' => 'Advance amounts cannot be negative'], 400);
    }

    _ensureAdvanceColumns($conn);

    // Upsert: include present and wo if provided
    if ($present !== null && $wo !== null) {
        $stmt = $conn->prepare('
            INSERT INTO employee_advances
                (employee_id, unit_id, month, year, present, wo, adv1, office_advance, dress_advance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                present = VALUES(present),
                wo = VALUES(wo),
                adv1 = VALUES(adv1),
                office_advance = VALUES(office_advance),
                dress_advance = VALUES(dress_advance)
        ');
        $stmt->bind_param('siiiddidd',
            $targetEmpId, $unitId, $month, $year,
            $present, $wo, $adv1, $officeAdv, $dressAdv
        );
    } else {
        // Only advances, no attendance
        $stmt = $conn->prepare('
            INSERT INTO employee_advances
                (employee_id, unit_id, month, year, adv1, office_advance, dress_advance)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                adv1 = VALUES(adv1),
                office_advance = VALUES(office_advance),
                dress_advance = VALUES(dress_advance)
        ');
        $stmt->bind_param('siiiddd',
            $targetEmpId, $unitId, $month, $year,
            $adv1, $officeAdv, $dressAdv
        );
    }
    $stmt->execute();
    $stmt->close();

    jsonOutput([
        'success' => true,
        'message' => 'Saved successfully',
        'data' => [
            'employee_id'   => $targetEmpId,
            'present'       => $present,
            'wo'            => $wo,
            'adv1'          => $adv1,
            'office_advance' => $officeAdv,
            'dress_advance'  => $dressAdv,
        ]
    ]);
}

// ─── POST: Add Temporary Employee ────────────────────────────────────────────

function _handleAddTempEmployee(array $input): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $name    = trim($input['name'] ?? '');
    $unitId  = (int)($input['unit_id'] ?? 0);
    $month   = (int)($input['month'] ?? 0);
    $year    = (int)($input['year'] ?? 0);

    if (empty($name) || $unitId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        jsonOutput(['success' => false, 'error' => 'name, unit_id, month, and year are required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS temp_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            unit_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            month INT NOT NULL,
            year INT NOT NULL,
            created_by VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_unit_name_month (unit_id, name, month, year),
            KEY idx_unit_month (unit_id, month, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $stmt = $conn->prepare('INSERT INTO temp_employees (unit_id, name, month, year, created_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('isiis', $unitId, $name, $month, $year, $employeeId);
    $stmt->execute();

    if ($stmt->errno === 1062) {
        $stmt->close();
        jsonOutput(['success' => false, 'error' => 'A temp employee with this name already exists for this unit and month'], 409);
    }

    $tempId = (int)$stmt->insert_id;
    $stmt->close();

    jsonOutput([
        'success' => true,
        'message' => 'Temp employee added',
        'data' => [
            'temp_id'     => $tempId,
            'employee_id' => 'TEMP-' . $tempId,
            'name'        => $name,
        ]
    ]);
}

// ─── POST: Delete Temporary Employee ─────────────────────────────────────────

function _handleDeleteTempEmployee(array $input): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $tempId = (int)($input['temp_id'] ?? 0);
    $unitId = (int)($input['unit_id'] ?? 0);

    if ($tempId <= 0 || $unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'temp_id and unit_id are required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    $delAdv = $conn->prepare('DELETE FROM employee_advances WHERE employee_id = ? AND unit_id = ?');
    $delAdvId = 'TEMP-' . $tempId;
    $delAdv->bind_param('si', $delAdvId, $unitId);
    $delAdv->execute();
    $delAdv->close();

    $delStmt = $conn->prepare('DELETE FROM temp_employees WHERE id = ? AND unit_id = ?');
    $delStmt->bind_param('ii', $tempId, $unitId);
    $delStmt->execute();
    $affected = $delStmt->affected_rows;
    $delStmt->close();

    if ($affected === 0) {
        jsonOutput(['success' => false, 'error' => 'Temp employee not found'], 404);
    }

    jsonOutput(['success' => true, 'message' => 'Temp employee removed']);
}

// ─── POST: Remove Employee (mark as left) ────────────────────────────────────

function _handleRemoveEmployee(array $input): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
    }

    $targetEmpId = (int)($input['employee_id'] ?? 0);
    $unitId      = (int)($input['unit_id'] ?? 0);

    if ($targetEmpId <= 0 || $unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'employee_id and unit_id are required'], 400);
    }

    if (!_checkUnitAccess($conn, $employeeId, $unitId, $callerRole)) {
        jsonOutput(['success' => false, 'error' => 'Access denied to this unit'], 403);
    }

    // Mark employee as 'removed' — keeps record but removes from active lists
    $stmt = $conn->prepare("UPDATE employees SET status = 'removed' WHERE id = ? AND unit_id = ? AND status IN ('approved', 'active')");
    $stmt->bind_param('ii', $targetEmpId, $unitId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        jsonOutput(['success' => false, 'error' => 'Employee not found or already removed'], 404);
    }

    jsonOutput(['success' => true, 'message' => 'Employee marked as removed']);
}