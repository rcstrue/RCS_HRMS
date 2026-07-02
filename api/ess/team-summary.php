<?php
/**
 * ESS API — Team Monthly Summary (Attendance + Advances)
 * GET:  Fetch all employees in a unit with attendance summary + advance data
 * POST: Save advance amounts for one employee (manager only)
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
            _handleSaveAdvance();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    error_log('[api/ess/team-summary] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ─── GET: Team Monthly Summary ──────────────────────────────────────────────

function _handleGetSummary(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    // Role check — managers/supervisors/admins only
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

    // Verify caller has access to this unit (admin skips)
    if ($callerRole !== 'admin') {
        $uStmt = $conn->prepare('SELECT unit_id FROM ess_employee_cache WHERE employee_id = ?');
        $uStmt->bind_param('s', $employeeId);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
        $callerUnitId = (int)($uRow['unit_id'] ?? 0);
        if ($callerUnitId !== $unitId) {
            jsonOutput(['success' => false, 'error' => 'You can only view your own unit'], 403);
        }
    }

    // Fetch employees with attendance summary + advances in one query
    // employees.id is int, attendance_summary.employee_id is int, employee_advances.employee_id is varchar
    $stmt = $conn->prepare('
        SELECT
            e.id AS emp_id,
            e.employee_code,
            e.full_name,
            COALESCE(att.total_present, 0) AS present,
            COALESCE(att.total_wo, 0) AS wo,
            COALESCE(adv.adv1, 0) AS adv1,
            COALESCE(adv.office_advance, 0) AS office_advance,
            COALESCE(adv.dress_advance, 0) AS dress_advance
        FROM employees e
        LEFT JOIN attendance_summary att
            ON att.employee_id = e.id AND att.unit_id = ? AND att.month = ? AND att.year = ?
        LEFT JOIN employee_advances adv
            ON adv.employee_id = CAST(e.id AS CHAR) AND adv.month = ? AND adv.year = ?
        WHERE e.unit_id = ? AND e.status = \'approved\'
        ORDER BY e.employee_code
    ');

    // params: unit_id(i), month(i), year(i), month(i), year(i), unit_id(i)
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
        ];

        $totals['present']        += $present;
        $totals['wo']             += $wo;
        $totals['adv1']           += $adv1;
        $totals['office_advance'] += $officeAdv;
        $totals['dress_advance']  += $dressAdv;
    }
    $stmt->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'items'  => $rows,
            'totals' => $totals,
            'count'  => count($rows),
        ]
    ]);
}

// ─── POST: Save Advance for one employee ─────────────────────────────────────

function _handleSaveAdvance(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();

    // Role check
    $callerRole = getEmployeeRole($conn, $employeeId);
    if (!in_array($callerRole, ['manager', 'supervisor', 'admin'], true)) {
        jsonOutput(['success' => false, 'error' => 'Access denied — only managers can edit advances'], 403);
    }

    $input = getInput();

    $targetEmpId = trim($input['employee_id'] ?? '');
    $unitId      = (int)($input['unit_id'] ?? 0);
    $month       = (int)($input['month'] ?? 0);
    $year        = (int)($input['year'] ?? 0);
    $adv1        = (float)($input['adv1'] ?? 0);
    $officeAdv   = (float)($input['office_advance'] ?? 0);
    $dressAdv    = (float)($input['dress_advance'] ?? 0);

    if (empty($targetEmpId) || $unitId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        jsonOutput(['success' => false, 'error' => 'employee_id, unit_id, month, and year are required'], 400);
    }

    // Verify target employee is in manager's unit (admin skips)
    if ($callerRole !== 'admin') {
        $uStmt = $conn->prepare('SELECT unit_id FROM ess_employee_cache WHERE employee_id = ?');
        $uStmt->bind_param('s', $employeeId);
        $uStmt->execute();
        $callerUnitId = (int)($uStmt->get_result()->fetch_assoc()['unit_id'] ?? 0);
        $uStmt->close();

        $uStmt2 = $conn->prepare('SELECT unit_id FROM employees WHERE id = ?');
        $uStmt2->bind_param('s', $targetEmpId);
        $uStmt2->execute();
        $targetUnitId = (int)($uStmt2->get_result()->fetch_assoc()['unit_id'] ?? 0);
        $uStmt2->close();

        if ($callerUnitId !== $targetUnitId || $targetUnitId !== $unitId) {
            jsonOutput(['success' => false, 'error' => 'Employee not in your unit'], 403);
        }
    }

    // No negatives
    if ($adv1 < 0 || $officeAdv < 0 || $dressAdv < 0) {
        jsonOutput(['success' => false, 'error' => 'Advance amounts cannot be negative'], 400);
    }

    // employee_advances.employee_id is varchar(36), so bind as string
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
    $stmt->execute();
    $stmt->close();

    jsonOutput([
        'success' => true,
        'message' => 'Advance saved successfully',
        'data' => [
            'employee_id'   => $targetEmpId,
            'adv1'          => $adv1,
            'office_advance' => $officeAdv,
            'dress_advance'  => $dressAdv,
        ]
    ]);
}