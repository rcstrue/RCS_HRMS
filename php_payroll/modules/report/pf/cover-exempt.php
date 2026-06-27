<?php
/**
 * PF Cover & Exempt Report
 * Analysis of PF covered vs exempt employees based on wage ceiling and PF applicability
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Cover & Exempt Report';

// CSV Export
if (isset($_GET['export'])) {
    $clientId = sanitize($_GET['client_id'] ?? '');
    $unitId = sanitize($_GET['unit_id'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Cover_Exempt_' . date('dmY') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Cover & Exempt Report']);
    fputcsv($output, ['Generated: ' . date('d/m/Y H:i')]);
    fputcsv($output, []);

    // Covered section
    fputcsv($output, ['=== PF COVERED EMPLOYEES (Basic+DA ≤ ₹15,000 AND PF Applicable) ===']);
    $headers = ['Sr No', 'Emp Code', 'Name', 'Father Name', 'UAN', 'PF Acct No.', 'Basic+DA',
                'Client', 'Unit', 'DOJ', 'Gender', 'Status'];
    fputcsv($output, $headers);

    try {
        $sql = "SELECT e.employee_code, e.full_name, e.father_name, e.uan_number, e.esic_number,
                       e.date_of_joining, e.gender, e.status, e.client_id, e.unit_id,
                       ess.basic_da, ess.gross_salary, ess.pf_applicable,
                       c.name as client_name, u.name as unit_name
                FROM employees e
                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                    AND ess.effective_to IS NULL
                LEFT JOIN clients c ON c.id = e.client_id
                LEFT JOIN units u ON u.id = e.unit_id
                WHERE e.status IN ('active', 'inactive')
                  AND ess.pf_applicable = 1
                  AND ess.basic_da <= 15000";
        $params = [];
        if (!empty($clientId)) { $sql .= " AND e.client_id = :clientId"; $params['clientId'] = $clientId; }
        if (!empty($unitId)) { $sql .= " AND e.unit_id = :unitId"; $params['unitId'] = $unitId; }
        $sql .= " ORDER BY e.employee_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $covered = $stmt->fetchAll();

        $sr = 1;
        foreach ($covered as $emp) {
            fputcsv($output, [
                $sr++,
                $emp['employee_code'],
                $emp['full_name'],
                $emp['father_name'],
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '',
                formatCurrency($emp['basic_da'] ?? 0),
                $emp['client_name'] ?? '',
                $emp['unit_name'] ?? '',
                formatDate($emp['date_of_joining']),
                $emp['gender'] == 'M' ? 'Male' : 'Female',
                $emp['status'] === 'active' ? 'Active' : 'Inactive'
            ]);
        }

        fputcsv($output, []);
        fputcsv($output, ['=== PF EXEMPT EMPLOYEES (Basic+DA > ₹15,000 OR PF Not Applicable) ===']);
        fputcsv($output, $headers);

        $sql = "SELECT e.employee_code, e.full_name, e.father_name, e.uan_number, e.esic_number,
                       e.date_of_joining, e.gender, e.status, e.client_id, e.unit_id,
                       ess.basic_da, ess.gross_salary, ess.pf_applicable,
                       c.name as client_name, u.name as unit_name
                FROM employees e
                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                    AND ess.effective_to IS NULL
                LEFT JOIN clients c ON c.id = e.client_id
                LEFT JOIN units u ON u.id = e.unit_id
                WHERE e.status IN ('active', 'inactive')
                  AND (ess.pf_applicable = 0 OR ess.pf_applicable IS NULL OR ess.basic_da > 15000)";
        $params = [];
        if (!empty($clientId)) { $sql .= " AND e.client_id = :clientId"; $params['clientId'] = $clientId; }
        if (!empty($unitId)) { $sql .= " AND e.unit_id = :unitId"; $params['unitId'] = $unitId; }
        $sql .= " ORDER BY e.employee_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $exempt = $stmt->fetchAll();

        $sr = 1;
        foreach ($exempt as $emp) {
            fputcsv($output, [
                $sr++,
                $emp['employee_code'],
                $emp['full_name'],
                $emp['father_name'],
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '',
                formatCurrency($emp['basic_da'] ?? 0),
                $emp['client_name'] ?? '',
                $emp['unit_name'] ?? '',
                formatDate($emp['date_of_joining']),
                $emp['gender'] == 'M' ? 'Male' : 'Female',
                $emp['status'] === 'active' ? 'Active' : 'Inactive'
            ]);
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Get filter values
$clientId = sanitize($_GET['client_id'] ?? '');
$unitId = sanitize($_GET['unit_id'] ?? '');

// Fetch clients and units
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients WHERE status = 1 ORDER BY name");
    $units = [];
    if (!empty($clientId)) {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = :clientId AND status = 1 ORDER BY name", ['clientId' => $clientId]);
    }
} catch (Exception $e) {
    $clients = [];
    $units = [];
}

// Fetch PF rates for wage ceiling
try {
    $pfRates = $db->fetch("SELECT * FROM pf_rates ORDER BY id DESC LIMIT 1");
} catch (Exception $e) {
    $pfRates = ['wage_ceiling' => 15000];
}
$wageCeiling = $pfRates['wage_ceiling'] ?? 15000;

// Fetch covered employees
$coveredEmployees = [];
$coveredStats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'male' => 0,
    'female' => 0,
    'total_basic_da' => 0,
    'below_10k' => 0,
    'between_10k_15k' => 0,
];
try {
    $sql = "SELECT e.employee_code, e.full_name, e.father_name, e.uan_number, e.esic_number,
                   e.date_of_joining, e.date_of_leaving, e.gender, e.status, e.dob,
                   e.client_id, e.unit_id, e.department, e.designation,
                   ess.basic_da, ess.gross_salary, ess.pf_applicable,
                   c.name as client_name, u.name as unit_name
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_to IS NULL
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.status IN ('active', 'inactive')
              AND ess.pf_applicable = 1
              AND ess.basic_da <= :wageCeiling";
    $params = ['wageCeiling' => $wageCeiling];
    if (!empty($clientId)) { $sql .= " AND e.client_id = :clientId"; $params['clientId'] = $clientId; }
    if (!empty($unitId)) { $sql .= " AND e.unit_id = :unitId"; $params['unitId'] = $unitId; }
    $sql .= " ORDER BY e.employee_code ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $coveredEmployees = $stmt->fetchAll();

    foreach ($coveredEmployees as $emp) {
        $coveredStats['total']++;
        if ($emp['status'] === 'active') $coveredStats['active']++;
        else $coveredStats['inactive']++;
        if ($emp['gender'] === 'M') $coveredStats['male']++;
        else $coveredStats['female']++;
        $coveredStats['total_basic_da'] += $emp['basic_da'] ?? 0;
        if (($emp['basic_da'] ?? 0) < 10000) $coveredStats['below_10k']++;
        elseif (($emp['basic_da'] ?? 0) <= 15000) $coveredStats['between_10k_15k']++;
    }
} catch (Exception $e) {
    $errorMsg = 'Error fetching covered employees: ' . $e->getMessage();
}

// Fetch exempt employees
$exemptEmployees = [];
$exemptStats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'male' => 0,
    'female' => 0,
    'total_basic_da' => 0,
    'high_wage' => 0,       // basic > 15000
    'pf_not_applicable' => 0, // pf_applicable = 0
];
try {
    $sql = "SELECT e.employee_code, e.full_name, e.father_name, e.uan_number, e.esic_number,
                   e.date_of_joining, e.date_of_leaving, e.gender, e.status, e.dob,
                   e.client_id, e.unit_id, e.department, e.designation,
                   ess.basic_da, ess.gross_salary, ess.pf_applicable,
                   c.name as client_name, u.name as unit_name
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_to IS NULL
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.status IN ('active', 'inactive')
              AND (ess.pf_applicable = 0 OR ess.pf_applicable IS NULL OR ess.basic_da > :wageCeiling)";
    $params = ['wageCeiling' => $wageCeiling];
    if (!empty($clientId)) { $sql .= " AND e.client_id = :clientId"; $params['clientId'] = $clientId; }
    if (!empty($unitId)) { $sql .= " AND e.unit_id = :unitId"; $params['unitId'] = $unitId; }
    $sql .= " ORDER BY e.employee_code ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $exemptEmployees = $stmt->fetchAll();

    foreach ($exemptEmployees as $emp) {
        $exemptStats['total']++;
        if ($emp['status'] === 'active') $exemptStats['active']++;
        else $exemptStats['inactive']++;
        if ($emp['gender'] === 'M') $exemptStats['male']++;
        else $exemptStats['female']++;
        $exemptStats['total_basic_da'] += $emp['basic_da'] ?? 0;
        if (($emp['pf_applicable'] ?? 0) == 0 || ($emp['pf_applicable'] ?? 0) === null) {
            $exemptStats['pf_not_applicable']++;
        }
        if (($emp['basic_da'] ?? 0) > $wageCeiling) {
            $exemptStats['high_wage']++;
        }
    }
} catch (Exception $e) {
    $errorMsg = ($errorMsg ?? '') . ' Error fetching exempt employees: ' . $e->getMessage();
}

$totalEmployees = $coveredStats['total'] + $exemptStats['total'];
$coverPct = $totalEmployees > 0 ? round(($coveredStats['total'] / $totalEmployees) * 100, 1) : 0;
$exemptPct = $totalEmployees > 0 ? round(($exemptStats['total'] / $totalEmployees) * 100, 1) : 0;
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 10px; }
    body { font-size: 12px; }
}
.cover-header {
    background: linear-gradient(135deg, #004d40 0%, #00695c 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.stat-card-covered {
    border-top: 4px solid #2e7d32;
}
.stat-card-exempt {
    border-top: 4px solid #c62828;
}
.pie-indicator {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    margin-right: 6px;
    vertical-align: middle;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="cover-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">PF Coverage Analysis — Covered (Basic+DA ≤ ₹<?= number_format($wageCeiling) ?>) vs. Exempt Employees</p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
            </div>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block mb-3">
        <h5 class="text-center fw-bold mb-0">PF COVER & EXEMPT REPORT</h5>
        <p class="text-center mb-0">PF Wage Ceiling: ₹<?= number_format($wageCeiling) ?></p>
        <p class="text-center mb-0 small">Generated on: <?= date('d/m/Y') ?></p>
        <hr>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <input type="hidden" name="page" value="report/pf/cover-exempt">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Apply</button>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <a href="?page=report/pf/cover-exempt&client_id=<?= $clientId ?>&unit_id=<?= $unitId ?>&export=1" class="btn btn-success btn-sm flex-fill"><i class="bi bi-download me-1"></i>Export CSV</a>
                            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small mb-1">Total Employees</div>
                <div class="fs-4 fw-bold text-primary"><?= $totalEmployees ?></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card text-center p-3 stat-card-covered">
                <div class="text-muted small mb-1">PF Covered</div>
                <div class="fs-4 fw-bold text-success"><?= $coveredStats['total'] ?> <small>(<?= $coverPct ?>%)</small></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card text-center p-3 stat-card-exempt">
                <div class="text-muted small mb-1">PF Exempt</div>
                <div class="fs-4 fw-bold text-danger"><?= $exemptStats['total'] ?> <small>(<?= $exemptPct ?>%)</small></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card text-center p-3" style="border-top:4px solid #1565c0">
                <div class="text-muted small mb-1">Coverage Rate</div>
                <div class="fs-4 fw-bold <?= $coverPct >= 80 ? 'text-success' : ($coverPct >= 50 ? 'text-warning' : 'text-danger') ?>">
                    <?= $coverPct ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- Coverage Breakdown -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Coverage Statistics</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Covered Stats -->
                <div class="col-md-6 mb-3 mb-md-0">
                    <h6 class="text-success mb-3"><i class="bi bi-check-circle me-2"></i>PF Covered (Basic+DA ≤ ₹<?= number_format($wageCeiling) ?>)</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:200px">Total Employees</td>
                            <td class="text-end fw-bold"><?= $coveredStats['total'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Active</td>
                            <td class="text-end"><?= $coveredStats['active'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Inactive (Left)</td>
                            <td class="text-end"><?= $coveredStats['inactive'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Male</td>
                            <td class="text-end"><?= $coveredStats['male'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Female</td>
                            <td class="text-end"><?= $coveredStats['female'] ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="text-muted">Total Basic+DA</td>
                            <td class="text-end fw-bold"><?= formatCurrency($coveredStats['total_basic_da']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Avg. Basic+DA</td>
                            <td class="text-end"><?= $coveredStats['total'] > 0 ? formatCurrency($coveredStats['total_basic_da'] / $coveredStats['total']) : '—' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Below ₹10,000</td>
                            <td class="text-end"><?= $coveredStats['below_10k'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">₹10,000 – ₹<?= number_format($wageCeiling) ?></td>
                            <td class="text-end"><?= $coveredStats['between_10k_15k'] ?></td>
                        </tr>
                    </table>
                </div>
                <!-- Exempt Stats -->
                <div class="col-md-6">
                    <h6 class="text-danger mb-3"><i class="bi bi-x-circle me-2"></i>PF Exempt (Basic+DA > ₹<?= number_format($wageCeiling) ?> OR Not Applicable)</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:200px">Total Employees</td>
                            <td class="text-end fw-bold"><?= $exemptStats['total'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Active</td>
                            <td class="text-end"><?= $exemptStats['active'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Inactive (Left)</td>
                            <td class="text-end"><?= $exemptStats['inactive'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Male</td>
                            <td class="text-end"><?= $exemptStats['male'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Female</td>
                            <td class="text-end"><?= $exemptStats['female'] ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="text-muted">Total Basic+DA</td>
                            <td class="text-end fw-bold"><?= formatCurrency($exemptStats['total_basic_da']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Avg. Basic+DA</td>
                            <td class="text-end"><?= $exemptStats['total'] > 0 ? formatCurrency($exemptStats['total_basic_da'] / $exemptStats['total']) : '—' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Above Wage Ceiling</td>
                            <td class="text-end text-warning"><?= $exemptStats['high_wage'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">PF Not Applicable</td>
                            <td class="text-end text-danger"><?= $exemptStats['pf_not_applicable'] ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Visual Progress Bar -->
            <div class="mt-4">
                <div class="progress" style="height: 30px;">
                    <div class="progress-bar bg-success" role="progressbar"
                         style="width: <?= $coverPct ?>%"
                         aria-valuenow="<?= $coveredStats['total'] ?>"
                         aria-valuemin="0" aria-valuemax="<?= $totalEmployees ?>">
                        <span class="d-flex justify-content-center align-items-center w-100">
                            <span class="pie-indicator bg-success"></span>Covered: <?= $coveredStats['total'] ?>
                        </span>
                    </div>
                    <?php if ($exemptPct > 0): ?>
                    <div class="progress-bar bg-danger" role="progressbar"
                         style="width: <?= $exemptPct ?>%"
                         aria-valuenow="<?= $exemptStats['total'] ?>"
                         aria-valuemin="0" aria-valuemax="<?= $totalEmployees ?>">
                        <span class="d-flex justify-content-center align-items-center w-100">
                            <span class="pie-indicator bg-danger"></span>Exempt: <?= $exemptStats['total'] ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Covered Employees Table -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-check-circle me-2"></i>PF Covered Employees — <?= $coveredStats['total'] ?> Employees</h6>
            <span class="badge bg-light text-dark"><?= $coverPct ?>% of Total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th style="width:80px">Emp Code</th>
                            <th>Name</th>
                            <th>Father Name</th>
                            <th style="width:130px">UAN</th>
                            <th style="width:130px">PF Acct No.</th>
                            <th class="text-end" style="width:100px">Basic+DA</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th class="text-center" style="width:70px">Gender</th>
                            <th class="text-center" style="width:95px">DOJ</th>
                            <th class="text-center" style="width:70px">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($coveredEmployees)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No covered employees found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($coveredEmployees as $emp): ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['esic_number'] ?? '—') ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($emp['client_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['unit_name'] ?? '—') ?></td>
                                    <td class="text-center"><?= $emp['gender'] === 'M' ? 'Male' : 'Female' ?></td>
                                    <td class="text-center"><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-center">
                                        <?php if ($emp['status'] === 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($coveredEmployees)): ?>
                        <tfoot class="table-success">
                            <tr>
                                <th colspan="6" class="text-end">Total (<?= $coveredStats['total'] ?> Employees)</th>
                                <th class="text-end"><?= formatCurrency($coveredStats['total_basic_da']) ?></th>
                                <th colspan="5"></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Exempt Employees Table -->
    <div class="card mb-4">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-x-circle me-2"></i>PF Exempt Employees — <?= $exemptStats['total'] ?> Employees</h6>
            <span class="badge bg-light text-dark"><?= $exemptPct ?>% of Total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th style="width:80px">Emp Code</th>
                            <th>Name</th>
                            <th>Father Name</th>
                            <th style="width:130px">UAN</th>
                            <th style="width:130px">PF Acct No.</th>
                            <th class="text-end" style="width:100px">Basic+DA</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th class="text-center" style="width:70px">Gender</th>
                            <th class="text-center" style="width:95px">DOJ</th>
                            <th class="text-center" style="width:100px">Exemption Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($exemptEmployees)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No exempt employees found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($exemptEmployees as $emp):
                                $reason = [];
                                if (($emp['pf_applicable'] ?? 0) == 0 || ($emp['pf_applicable'] ?? 0) === null) {
                                    $reason[] = 'PF Not Applicable';
                                }
                                if (($emp['basic_da'] ?? 0) > $wageCeiling) {
                                    $reason[] = 'Wage > Ceiling';
                                }
                                $reasonText = !empty($reason) ? implode(', ', $reason) : 'N/A';
                            ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['esic_number'] ?? '—') ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($emp['client_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['unit_name'] ?? '—') ?></td>
                                    <td class="text-center"><?= $emp['gender'] === 'M' ? 'Male' : 'Female' ?></td>
                                    <td class="text-center"><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-center">
                                        <?php if (strpos($reasonText, 'Wage') !== false): ?>
                                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($reasonText) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($reasonText) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($exemptEmployees)): ?>
                        <tfoot class="table-danger">
                            <tr>
                                <th colspan="6" class="text-end">Total (<?= $exemptStats['total'] ?> Employees)</th>
                                <th class="text-end"><?= formatCurrency($exemptStats['total_basic_da']) ?></th>
                                <th colspan="5"></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Client-wise Coverage Summary -->
    <?php if (empty($clientId)): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-building me-2"></i>Client-wise Coverage Summary</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Client Name</th>
                            <th class="text-center">Total Emp.</th>
                            <th class="text-center text-success">Covered</th>
                            <th class="text-center text-danger">Exempt</th>
                            <th class="text-end">Coverage %</th>
                            <th class="text-end">Covered Wages</th>
                            <th class="text-end">Total Wages</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Aggregate by client
                        $clientSummary = [];
                        try {
                            $allEmps = $db->fetchAll(
                                "SELECT e.client_id, c.name as client_name,
                                        ess.pf_applicable, ess.basic_da
                                 FROM employees e
                                 LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                     AND ess.effective_to IS NULL
                                 LEFT JOIN clients c ON c.id = e.client_id
                                 WHERE e.status IN ('active', 'inactive')
                                   AND ess.basic_da IS NOT NULL
                                 ORDER BY c.name"
                            );

                            foreach ($allEmps as $emp) {
                                $cid = $emp['client_id'];
                                $cname = $emp['client_name'] ?? 'Unknown';
                                if (!isset($clientSummary[$cid])) {
                                    $clientSummary[$cid] = [
                                        'name' => $cname,
                                        'total' => 0, 'covered' => 0, 'exempt' => 0,
                                        'covered_wages' => 0, 'total_wages' => 0,
                                    ];
                                }
                                $clientSummary[$cid]['total']++;
                                $clientSummary[$cid]['total_wages'] += $emp['basic_da'] ?? 0;
                                if (($emp['pf_applicable'] ?? 0) == 1 && ($emp['basic_da'] ?? 0) <= $wageCeiling) {
                                    $clientSummary[$cid]['covered']++;
                                    $clientSummary[$cid]['covered_wages'] += $emp['basic_da'] ?? 0;
                                } else {
                                    $clientSummary[$cid]['exempt']++;
                                }
                            }
                        } catch (Exception $e) {
                            // Skip if query fails
                        }

                        if (empty($clientSummary)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-3">No data available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientSummary as $cs):
                                $csPct = $cs['total'] > 0 ? round(($cs['covered'] / $cs['total']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cs['name']) ?></strong></td>
                                    <td class="text-center"><?= $cs['total'] ?></td>
                                    <td class="text-center text-success"><?= $cs['covered'] ?></td>
                                    <td class="text-center text-danger"><?= $cs['exempt'] ?></td>
                                    <td class="text-end">
                                        <span class="badge <?= $csPct >= 80 ? 'bg-success' : ($csPct >= 50 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                            <?= $csPct ?>%
                                        </span>
                                    </td>
                                    <td class="text-end"><?= formatCurrency($cs['covered_wages']) ?></td>
                                    <td class="text-end"><?= formatCurrency($cs['total_wages']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Print Footer -->
    <div class="d-none d-print-block mt-4">
        <hr>
        <div class="row">
            <div class="col-6">
                <p class="mb-0 fw-bold">Prepared By</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-0 fw-bold">Authorized Signatory</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
                <p class="mb-0 small">Office Seal</p>
            </div>
        </div>
    </div>
</div>
