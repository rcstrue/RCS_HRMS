<?php
/**
 * ESS API — Daily Manpower Status
 * Tracks daily manpower budget vs actual for each unit.
 *
 * GET  (default)          — List entries filtered by date/client/unit
 * GET  view=dashboard     — Aggregated stats (daily/weekly/monthly/yearly)
 * POST                    — Upsert a daily manpower record
 * DELETE                  — Delete a record
 */

require_once __DIR__ . '/config.php';

try {
    validateApiKey();

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            _handleGet();
            break;
        case 'POST':
        case 'PUT':
            _handleSave();
            break;
        case 'DELETE':
            _handleDelete();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ─── Create table if not exists ────────────────────────────────────────────────

function ensureTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_manpower_daily (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            unit_id INT NOT NULL,
            client_id INT NOT NULL DEFAULT 0,
            report_date DATE NOT NULL,
            morning_worker_budget INT NOT NULL DEFAULT 0,
            morning_worker_actual INT NOT NULL DEFAULT 0,
            morning_supervisor_budget INT NOT NULL DEFAULT 0,
            morning_supervisor_actual INT NOT NULL DEFAULT 0,
            evening_worker_budget INT NOT NULL DEFAULT 0,
            evening_worker_actual INT NOT NULL DEFAULT 0,
            evening_supervisor_budget INT NOT NULL DEFAULT 0,
            evening_supervisor_actual INT NOT NULL DEFAULT 0,
            remarks TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unit_date (unit_id, report_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// ─── GET: List entries ─────────────────────────────────────────────────────────

function _handleGet(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();
    ensureTable($conn);

    $view = $_GET['view'] ?? '';

    if ($view === 'dashboard') {
        _handleDashboard($employeeId, $conn);
        return;
    }

    // List entries with filters
    $date = $_GET['date'] ?? '';
    $unitId = $_GET['unit_id'] ?? '';
    $clientId = $_GET['client_id'] ?? '';
    $unitIds = isset($_GET['unit_ids']) ? array_map('intval', explode(',', $_GET['unit_ids'])) : [];
    $unitIds = array_filter($unitIds, fn($v) => $v > 0);

    $query = "
        SELECT m.*,
               u.name AS unit_name,
               c.name AS client_name
        FROM ess_manpower_daily m
        LEFT JOIN units u ON u.id = m.unit_id
        LEFT JOIN clients c ON c.id = m.client_id
        WHERE 1=1
    ";
    $types = '';
    $params = [];

    if (!empty($unitIds)) {
        $query .= ' AND m.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
        $types .= str_repeat('i', count($unitIds));
        $params = array_merge($params, $unitIds);
    }

    if (!empty($date)) {
        $query .= ' AND m.report_date = ?';
        $types .= 's';
        $params[] = $date;
    }

    if (!empty($unitId)) {
        $query .= ' AND m.unit_id = ?';
        $types .= 'i';
        $params[] = (int)$unitId;
    }

    if (!empty($clientId)) {
        $query .= ' AND m.client_id = ?';
        $types .= 'i';
        $params[] = (int)$clientId;
    }

    $query .= ' ORDER BY m.report_date DESC, u.name ASC';

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        bindDynamicParams($stmt, $types, $params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $entries = [];
    while ($row = $result->fetch_assoc()) {
        $mwb = (int)$row['morning_worker_budget'];
        $mwa = (int)$row['morning_worker_actual'];
        $msb = (int)$row['morning_supervisor_budget'];
        $msa = (int)$row['morning_supervisor_actual'];
        $ewb = (int)$row['evening_worker_budget'];
        $ewa = (int)$row['evening_worker_actual'];
        $esb = (int)$row['evening_supervisor_budget'];
        $esa = (int)$row['evening_supervisor_actual'];

        $morningTotalBudget = $mwb + $msb;
        $morningTotalActual = $mwa + $msa;
        $eveningTotalBudget = $ewb + $esb;
        $eveningTotalActual = $ewa + $esa;

        $entries[] = [
            'id' => (int)$row['id'],
            'employee_id' => (int)$row['employee_id'],
            'unit_id' => (int)$row['unit_id'],
            'client_id' => (int)$row['client_id'],
            'unit_name' => $row['unit_name'] ?? '',
            'client_name' => $row['client_name'] ?? '',
            'report_date' => $row['report_date'],
            'morning' => [
                'worker_budget' => $mwb,
                'worker_actual' => $mwa,
                'supervisor_budget' => $msb,
                'supervisor_actual' => $msa,
                'total_budget' => $morningTotalBudget,
                'total_actual' => $morningTotalActual,
                'shortage' => $morningTotalBudget - $morningTotalActual,
            ],
            'evening' => [
                'worker_budget' => $ewb,
                'worker_actual' => $ewa,
                'supervisor_budget' => $esb,
                'supervisor_actual' => $esa,
                'total_budget' => $eveningTotalBudget,
                'total_actual' => $eveningTotalActual,
                'shortage' => $eveningTotalBudget - $eveningTotalActual,
            ],
            'total_budget' => $morningTotalBudget + $eveningTotalBudget,
            'total_actual' => $morningTotalActual + $eveningTotalActual,
            'overall_shortage' => ($morningTotalBudget + $eveningTotalBudget) - ($morningTotalActual + $eveningTotalActual),
            'remarks' => $row['remarks'] ?? '',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
    $stmt->close();
    $conn->close();

    jsonOutput(['success' => true, 'data' => $entries]);
}

// ─── GET: Dashboard Aggregation ────────────────────────────────────────────────

function _handleDashboard(string $employeeId, mysqli $conn): void
{
    $period = $_GET['period'] ?? 'daily';
    $unitIds = isset($_GET['unit_ids']) ? array_map('intval', explode(',', $_GET['unit_ids'])) : [];
    $unitIds = array_filter($unitIds, fn($v) => $v > 0);
    $clientId = $_GET['client_id'] ?? '';
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Determine date range based on period and offset
    $today = date('Y-m-d');
    $startDate = $today;
    $endDate = $today;
    $label = '';

    switch ($period) {
        case 'daily':
            $refDate = date('Y-m-d', strtotime("$today $offset days"));
            $startDate = $refDate;
            $endDate = $refDate;
            $label = date('d M Y', strtotime($refDate));
            break;
        case 'weekly':
            // Offset shifts the Monday of the week
            $baseMonday = date('Y-m-d', strtotime("Monday this week $offset weeks"));
            $startDate = $baseMonday;
            $endDate = date('Y-m-d', strtotime("Sunday this week $offset weeks"));
            $label = date('d M', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate));
            break;
        case 'monthly':
            $startDate = date('Y-m-01', strtotime("$today $offset months"));
            $endDate = date('Y-m-t', strtotime($startDate));
            $label = date('F Y', strtotime($startDate));
            break;
        case 'yearly':
            $year = (int)date('Y') + $offset;
            $startDate = "$year-01-01";
            $endDate = "$year-12-31";
            $label = (string)$year;
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Invalid period. Use daily, weekly, monthly, or yearly.'], 400);
            return;
    }

    // Build query with aggregations
    $query = "
        SELECT
            m.unit_id,
            u.name AS unit_name,
            c.name AS client_name,
            m.client_id,
            COUNT(DISTINCT m.report_date) AS days_reported,
            SUM(m.morning_worker_budget) AS morning_worker_budget,
            SUM(m.morning_worker_actual) AS morning_worker_actual,
            SUM(m.morning_supervisor_budget) AS morning_supervisor_budget,
            SUM(m.morning_supervisor_actual) AS morning_supervisor_actual,
            SUM(m.evening_worker_budget) AS evening_worker_budget,
            SUM(m.evening_worker_actual) AS evening_worker_actual,
            SUM(m.evening_supervisor_budget) AS evening_supervisor_budget,
            SUM(m.evening_supervisor_actual) AS evening_supervisor_actual
        FROM ess_manpower_daily m
        LEFT JOIN units u ON u.id = m.unit_id
        LEFT JOIN clients c ON c.id = m.client_id
        WHERE m.report_date BETWEEN ? AND ?
    ";
    $types = 'ss';
    $params = [$startDate, $endDate];

    if (!empty($unitIds)) {
        $query .= ' AND m.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
        $types .= str_repeat('i', count($unitIds));
        $params = array_merge($params, $unitIds);
    }

    if (!empty($clientId)) {
        $query .= ' AND m.client_id = ?';
        $types .= 'i';
        $params[] = (int)$clientId;
    }

    $query .= ' GROUP BY m.unit_id, u.name, c.name, m.client_id ORDER BY c.name, u.name';

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        bindDynamicParams($stmt, $types, $params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $units = [];
    $grandBudget = 0;
    $grandActual = 0;
    $totalDaysReported = 0;

    while ($row = $result->fetch_assoc()) {
        $mwb = (int)$row['morning_worker_budget'];
        $mwa = (int)$row['morning_worker_actual'];
        $msb = (int)$row['morning_supervisor_budget'];
        $msa = (int)$row['morning_supervisor_actual'];
        $ewb = (int)$row['evening_worker_budget'];
        $ewa = (int)$row['evening_worker_actual'];
        $esb = (int)$row['evening_supervisor_budget'];
        $esa = (int)$row['evening_supervisor_actual'];

        $morningBudget = $mwb + $msb;
        $morningActual = $mwa + $msa;
        $eveningBudget = $ewb + $esb;
        $eveningActual = $ewa + $esa;
        $totalBudget = $morningBudget + $eveningBudget;
        $totalActual = $morningActual + $eveningActual;
        $shortage = $totalBudget - $totalActual;

        $grandBudget += $totalBudget;
        $grandActual += $totalActual;
        $totalDaysReported += (int)$row['days_reported'];

        $units[] = [
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => $row['unit_name'] ?? '',
            'client_name' => $row['client_name'] ?? '',
            'client_id' => (int)$row['client_id'],
            'days_reported' => (int)$row['days_reported'],
            'morning' => [
                'total_budget' => $morningBudget,
                'total_actual' => $morningActual,
                'shortage' => $morningBudget - $morningActual,
            ],
            'evening' => [
                'total_budget' => $eveningBudget,
                'total_actual' => $eveningActual,
                'shortage' => $eveningBudget - $eveningActual,
            ],
            'total_budget' => $totalBudget,
            'total_actual' => $totalActual,
            'shortage' => $shortage,
        ];
    }
    $stmt->close();

    // Daily: also get day-by-day breakdown
    $dailyBreakdown = [];
    if ($period !== 'daily') {
        $dailyQuery = "
            SELECT
                m.report_date,
                SUM(m.morning_worker_budget + m.morning_supervisor_budget + m.evening_worker_budget + m.evening_supervisor_budget) AS total_budget,
                SUM(m.morning_worker_actual + m.morning_supervisor_actual + m.evening_worker_actual + m.evening_supervisor_actual) AS total_actual
            FROM ess_manpower_daily m
            WHERE m.report_date BETWEEN ? AND ?
        ";
        $dailyTypes = 'ss';
        $dailyParams = [$startDate, $endDate];

        if (!empty($unitIds)) {
            $dailyQuery .= ' AND m.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
            $dailyTypes .= str_repeat('i', count($unitIds));
            $dailyParams = array_merge($dailyParams, $unitIds);
        }

        if (!empty($clientId)) {
            $dailyQuery .= ' AND m.client_id = ?';
            $dailyTypes .= 'i';
            $dailyParams[] = (int)$clientId;
        }

        $dailyQuery .= ' GROUP BY m.report_date ORDER BY m.report_date ASC';

        $dstmt = $conn->prepare($dailyQuery);
        if (!empty($dailyParams)) {
            bindDynamicParams($dstmt, $dailyTypes, $dailyParams);
        }
        $dstmt->execute();
        $dresult = $dstmt->get_result();

        while ($drow = $dresult->fetch_assoc()) {
            $budget = (int)$drow['total_budget'];
            $actual = (int)$drow['total_actual'];
            $dailyBreakdown[] = [
                'date' => $drow['report_date'],
                'total_budget' => $budget,
                'total_actual' => $actual,
                'shortage' => $budget - $actual,
            ];
        }
        $dstmt->close();
    }

    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'period' => $period,
            'label' => $label,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_units' => count($units),
            'days_in_period' => $period === 'daily' ? 1 :
                (int)(date_diff(date_create($startDate), date_create($endDate))->format('%a') + 1),
            'days_reported' => $totalDaysReported,
            'grand_total' => [
                'total_budget' => $grandBudget,
                'total_actual' => $grandActual,
                'shortage' => $grandBudget - $grandActual,
            ],
            'units' => $units,
            'daily_breakdown' => $dailyBreakdown,
        ]
    ]);
}

// ─── POST/PUT: Save (Upsert) ──────────────────────────────────────────────────

function _handleSave(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();
    ensureTable($conn);

    $input = getInput();

    $unitId = (int)($input['unit_id'] ?? 0);
    $clientId = (int)($input['client_id'] ?? 0);
    $reportDate = trim($input['report_date'] ?? '');
    $remarks = trim($input['remarks'] ?? '');

    // Validate required fields
    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Unit is required'], 400);
    }
    if (empty($reportDate)) {
        jsonOutput(['success' => false, 'error' => 'Date is required'], 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        jsonOutput(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
    }
    // Don't allow future dates
    if ($reportDate > date('Y-m-d')) {
        jsonOutput(['success' => false, 'error' => 'Cannot enter manpower status for future dates'], 400);
    }

    // Extract shift data with defaults
    $morningWorkerBudget = max(0, (int)($input['morning_worker_budget'] ?? 0));
    $morningWorkerActual = max(0, (int)($input['morning_worker_actual'] ?? 0));
    $morningSupervisorBudget = max(0, (int)($input['morning_supervisor_budget'] ?? 0));
    $morningSupervisorActual = max(0, (int)($input['morning_supervisor_actual'] ?? 0));
    $eveningWorkerBudget = max(0, (int)($input['evening_worker_budget'] ?? 0));
    $eveningWorkerActual = max(0, (int)($input['evening_worker_actual'] ?? 0));
    $eveningSupervisorBudget = max(0, (int)($input['evening_supervisor_budget'] ?? 0));
    $eveningSupervisorActual = max(0, (int)($input['evening_supervisor_actual'] ?? 0));

    // Upsert: INSERT ... ON DUPLICATE KEY UPDATE
    $stmt = $conn->prepare("
        INSERT INTO ess_manpower_daily
            (employee_id, unit_id, client_id, report_date,
             morning_worker_budget, morning_worker_actual, morning_supervisor_budget, morning_supervisor_actual,
             evening_worker_budget, evening_worker_actual, evening_supervisor_budget, evening_supervisor_actual,
             remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            client_id = VALUES(client_id),
            morning_worker_budget = VALUES(morning_worker_budget),
            morning_worker_actual = VALUES(morning_worker_actual),
            morning_supervisor_budget = VALUES(morning_supervisor_budget),
            morning_supervisor_actual = VALUES(morning_supervisor_actual),
            evening_worker_budget = VALUES(evening_worker_budget),
            evening_worker_actual = VALUES(evening_worker_actual),
            evening_supervisor_budget = VALUES(evening_supervisor_budget),
            evening_supervisor_actual = VALUES(evening_supervisor_actual),
            remarks = VALUES(remarks)
    ");

    $stmt->bind_param('iiisiiiiiiiis',
        $employeeId, $unitId, $clientId, $reportDate,
        $morningWorkerBudget, $morningWorkerActual, $morningSupervisorBudget, $morningSupervisorActual,
        $eveningWorkerBudget, $eveningWorkerActual, $eveningSupervisorBudget, $eveningSupervisorActual,
        $remarks
    );
    $stmt->execute();

    $insertId = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'message' => $insertId > 0 ? 'Manpower status saved successfully' : 'Manpower status updated successfully',
        'data' => ['id' => $insertId > 0 ? $insertId : 'updated']
    ]);
}

// ─── DELETE ────────────────────────────────────────────────────────────────────

function _handleDelete(): void
{
    $employeeId = requireAuth();
    $conn = getDbConnection();
    ensureTable($conn);

    $input = getInput();
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        jsonOutput(['success' => false, 'error' => 'Record ID is required'], 400);
    }

    // Only allow deleting own records (or supervisor/manager for their units)
    $role = getEmployeeRole($conn, $employeeId);
    $isSupervisorOrAbove = in_array($role, ['supervisor', 'manager', 'admin']);

    if ($isSupervisorOrAbove) {
        $stmt = $conn->prepare('DELETE FROM ess_manpower_daily WHERE id = ?');
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $conn->prepare('DELETE FROM ess_manpower_daily WHERE id = ? AND employee_id = ?');
        $stmt->bind_param('ii', $id, $employeeId);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($affected > 0) {
        jsonOutput(['success' => true, 'message' => 'Record deleted']);
    } else {
        jsonOutput(['success' => false, 'error' => 'Record not found or you do not have permission'], 404);
    }
}