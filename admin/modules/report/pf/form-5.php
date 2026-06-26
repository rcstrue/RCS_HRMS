<?php
/**
 * PF Form 5 - New Employee List
 * Lists employees who joined during selected month and have PF applicable
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 5 - New Employee List';

// CSV Export
if (isset($_GET['export'])) {
    $month = sanitize($_GET['month'] ?? date('m'));
    $year = sanitize($_GET['year'] ?? date('Y'));
    $clientId = sanitize($_GET['client_id'] ?? '');
    $unitId = sanitize($_GET['unit_id'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_5_' . $month . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');

    // BOM for UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Form 5 - New Employee List']);
    fputcsv($output, ['Month: ' . $month . '/' . $year]);
    fputcsv($output, []);

    $headers = ['Sr No', 'UAN No.', 'PF Account No.', 'Name', 'Father/Husband Name', 'DOB', 'Gender', 'Date of Joining', 'Wages (Basic+DA)', 'EPF Contribution (12%)', 'EPS Contribution (8.33%)'];
    fputcsv($output, $headers);

    try {
        $startDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $sql = "SELECT e.employee_code, e.full_name, e.father_name, e.date_of_joining, 
                       e.gender, e.dob, e.uan_number, e.esic_number, e.department,
                       ess.basic_da, ess.pf_applicable, ess.effective_from
                FROM employees e
                JOIN employee_salary_structures ess ON ess.employee_id = e.id
                WHERE e.status = 'active'
                  AND ess.pf_applicable = 1
                  AND DATE(e.date_of_joining) >= :startDate
                  AND DATE(e.date_of_joining) <= :endDate";
        $params = ['startDate' => $startDate, 'endDate' => $endDate];

        if (!empty($clientId)) {
            $sql .= " AND e.client_id = :clientId";
            $params['clientId'] = $clientId;
        }
        if (!empty($unitId)) {
            $sql .= " AND e.unit_id = :unitId";
            $params['unitId'] = $unitId;
        }

        $sql .= " ORDER BY e.employee_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        try {
            $pfRates = $db->fetch("SELECT * FROM pf_rates ORDER BY id DESC LIMIT 1");
        } catch (Exception $e) {
            $pfRates = ['employee_share' => 12, 'employer_share_eps' => 8.33];
        }

        $sr = 1;
        foreach ($employees as $emp) {
            $basicDa = $emp['basic_da'] ?? 0;
            $epfContribution = round($basicDa * ($pfRates['employee_share'] / 100), 2);
            $epsContribution = round($basicDa * ($pfRates['employer_share_eps'] / 100), 2);

            fputcsv($output, [
                $sr++,
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '', // placeholder for PF acct no.
                $emp['full_name'],
                $emp['father_name'],
                formatDate($emp['dob']),
                $emp['gender'] == 'M' ? 'Male' : ($emp['gender'] == 'F' ? 'Female' : 'Other'),
                formatDate($emp['date_of_joining']),
                formatCurrency($basicDa),
                formatCurrency($epfContribution),
                formatCurrency($epsContribution)
            ]);
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error: ' . $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Handle form submission tracking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS pf_form5_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            month VARCHAR(2) NOT NULL,
            year VARCHAR(4) NOT NULL,
            client_id INT DEFAULT NULL,
            unit_id INT DEFAULT NULL,
            submitted_by VARCHAR(100),
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'submitted',
            remarks TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($_POST['action'] === 'submit') {
            $month = sanitize($_POST['month']);
            $year = sanitize($_POST['year']);
            $clientId = sanitize($_POST['client_id'] ?? '');
            $unitId = sanitize($_POST['unit_id'] ?? '');
            $remarks = sanitize($_POST['remarks'] ?? '');

            // Check for duplicate
            $existing = $db->fetch("SELECT id FROM pf_form5_submissions 
                                    WHERE month = :month AND year = :year 
                                    AND (client_id = :clientId OR :clientId = '')
                                    AND (unit_id = :unitId OR :unitId = '')",
                ['month' => $month, 'year' => $year, 'clientId' => $clientId, 'unitId' => $unitId]);

            if (!$existing) {
                $db->query("INSERT INTO pf_form5_submissions (month, year, client_id, unit_id, submitted_by, remarks)
                           VALUES (:month, :year, :clientId, :unitId, :submittedBy, :remarks)",
                    [
                        'month' => $month,
                        'year' => $year,
                        'clientId' => $clientId ?: null,
                        'unitId' => $unitId ?: null,
                        'submittedBy' => $_SESSION['user_name'] ?? 'Admin',
                        'remarks' => $remarks
                    ]);
                $successMsg = 'Form 5 submitted successfully for ' . $month . '/' . $year;
            } else {
                $errorMsg = 'Form 5 already submitted for ' . $month . '/' . $year;
            }
        } elseif ($_POST['action'] === 'revoke') {
            $id = sanitize($_POST['submission_id']);
            $db->query("UPDATE pf_form5_submissions SET status = 'revoked' WHERE id = :id", ['id' => $id]);
            $successMsg = 'Submission revoked successfully.';
        }
    } catch (Exception $e) {
        $errorMsg = 'Database error: ' . $e->getMessage();
    }
}

// Get filter values
$month = sanitize($_GET['month'] ?? date('m'));
$year = sanitize($_GET['year'] ?? date('Y'));
$clientId = sanitize($_GET['client_id'] ?? '');
$unitId = sanitize($_GET['unit_id'] ?? '');

// Fetch PF rates
try {
    $pfRates = $db->fetch("SELECT * FROM pf_rates ORDER BY id DESC LIMIT 1");
} catch (Exception $e) {
    $pfRates = ['employee_share' => 12, 'employer_share_pf' => 3.67, 'employer_share_eps' => 8.33, 'edlis_employer' => 0.5, 'epf_admin_charges' => 0.5, 'wage_ceiling' => 15000];
}

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

// Fetch employees
$employees = [];
$totalEmployees = 0;
$totalBasicDa = 0;
$totalEpf = 0;
$totalEps = 0;

try {
    $startDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_joining,
                   e.gender, e.dob, e.uan_number, e.esic_number, e.department,
                   ess.basic_da, ess.pf_applicable, ess.effective_from,
                   c.name as client_name, u.name as unit_name
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id 
                AND ess.effective_from <= :endDate
                AND (ess.effective_to IS NULL OR ess.effective_to >= :startDate)
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.status IN ('active', 'inactive')
              AND ess.pf_applicable = 1
              AND DATE(e.date_of_joining) >= :startDate
              AND DATE(e.date_of_joining) <= :endDate";
    $params = ['startDate' => $startDate, 'endDate' => $endDate];

    if (!empty($clientId)) {
        $sql .= " AND e.client_id = :clientId";
        $params['clientId'] = $clientId;
    }
    if (!empty($unitId)) {
        $sql .= " AND e.unit_id = :unitId";
        $params['unitId'] = $unitId;
    }

    $sql .= " ORDER BY e.employee_code ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    $totalEmployees = count($employees);
    foreach ($employees as $emp) {
        $basicDa = $emp['basic_da'] ?? 0;
        $totalBasicDa += $basicDa;
        $totalEpf += round($basicDa * ($pfRates['employee_share'] / 100), 2);
        $totalEps += round($basicDa * ($pfRates['employer_share_eps'] / 100), 2);
    }
} catch (Exception $e) {
    $errorMsg = $errorMsg ?? 'Error fetching employees: ' . $e->getMessage();
}

// Fetch submission history
$submissions = [];
try {
    $submissions = $db->fetchAll("SELECT s.*, c.name as client_name, u.name as unit_name
                                   FROM pf_form5_submissions s
                                   LEFT JOIN clients c ON c.id = s.client_id
                                   LEFT JOIN units u ON u.id = s.unit_id
                                   ORDER BY s.submitted_at DESC
                                   LIMIT 20");
} catch (Exception $e) {
    // Table may not exist yet
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 11px; }
    body { font-size: 12px; }
}
.form-5-header {
    background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="form-5-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">Employees' Provident Fund Organisation — New Employee Declaration</p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
            </div>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block mb-3">
        <h5 class="text-center fw-bold">FORM 5</h5>
        <p class="text-center mb-0"><strong>Return of Employees qualifying for coverage for the first time during the wage month</strong></p>
        <p class="text-center mb-0">[See Regulation 14(2)]</p>
        <p class="text-center mb-0">Month: <?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?></p>
        <hr>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <input type="hidden" name="page" value="report/pf/form-5">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
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
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search me-1"></i>Apply</button>
                            <a href="?page=report/pf/form-5&month=<?= $month ?>&year=<?= $year ?>&client_id=<?= $clientId ?>&unit_id=<?= $unitId ?>&export=1" class="btn btn-success btn-sm flex-fill"><i class="bi bi-download me-1"></i>CSV</a>
                            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show no-print">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Table -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>Employee List — <?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?></h6>
            <span class="badge bg-light text-dark">Total: <?= $totalEmployees ?> Employees</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">Sr No</th>
                            <th style="width:130px">UAN No.</th>
                            <th style="width:130px">PF Account No.</th>
                            <th>Name</th>
                            <th>Father/Husband Name</th>
                            <th class="text-center" style="width:95px">DOB</th>
                            <th class="text-center" style="width:70px">Gender</th>
                            <th class="text-center" style="width:95px">Date of Joining</th>
                            <th class="text-end" style="width:110px">Wages (Basic+DA)</th>
                            <th class="text-end" style="width:110px">EPF Contribution</th>
                            <th class="text-end" style="width:110px">EPS Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No new employees with PF applicable found for <?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($employees as $emp):
                                $basicDa = $emp['basic_da'] ?? 0;
                                $epfContribution = round($basicDa * ($pfRates['employee_share'] / 100), 2);
                                $epsContribution = round($basicDa * ($pfRates['employer_share_eps'] / 100), 2);
                            ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['esic_number'] ?? '—') ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td class="text-center"><?= formatDate($emp['dob']) ?></td>
                                    <td class="text-center"><?= $emp['gender'] == 'M' ? 'Male' : ($emp['gender'] == 'F' ? 'Female' : 'Other') ?></td>
                                    <td class="text-center"><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-end"><?= formatCurrency($basicDa) ?></td>
                                    <td class="text-end"><?= formatCurrency($epfContribution) ?></td>
                                    <td class="text-end"><?= formatCurrency($epsContribution) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($employees)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="8" class="text-end">Total (<?= $totalEmployees ?> Employees)</th>
                                <th class="text-end"><?= formatCurrency($totalBasicDa) ?></th>
                                <th class="text-end"><?= formatCurrency($totalEpf) ?></th>
                                <th class="text-end"><?= formatCurrency($totalEps) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Submit Section -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-send me-2"></i>Submit Form 5</h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-12">
                        <p class="text-muted small mb-2">Submit this Form 5 for the selected period. Submitted records can be tracked below.</p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Any remarks for this submission..."></textarea>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" name="action" value="submit" class="btn btn-primary btn-sm" onclick="return confirm('Submit Form 5 for <?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?> with <?= $totalEmployees ?> employees?')">
                            <i class="bi bi-send me-1"></i>Submit Form 5
                        </button>
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <input type="hidden" name="client_id" value="<?= $clientId ?>">
                        <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Submission History -->
    <?php if (!empty($submissions)): ?>
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Submission History</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month/Year</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th>Submitted By</th>
                            <th>Submitted At</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $sub): ?>
                            <tr>
                                <td><?= str_pad($sub['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $sub['year'] ?></td>
                                <td><?= htmlspecialchars($sub['client_name'] ?? 'All') ?></td>
                                <td><?= htmlspecialchars($sub['unit_name'] ?? 'All') ?></td>
                                <td><?= htmlspecialchars($sub['submitted_by']) ?></td>
                                <td><?= formatDate($sub['submitted_at']) ?></td>
                                <td>
                                    <?php if ($sub['status'] === 'submitted'): ?>
                                        <span class="badge bg-success">Submitted</span>
                                    <?php elseif ($sub['status'] === 'revoked'): ?>
                                        <span class="badge bg-danger">Revoked</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($sub['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($sub['remarks'] ?? '—') ?></td>
                                <td class="text-center">
                                    <?php if ($sub['status'] === 'submitted'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Revoke this submission?')">
                                                <i class="bi bi-arrow-counterclockwise"></i> Revoke
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
                <p class="mb-0"><strong>Signature of Employer/Authorized Signatory</strong></p>
                <p class="mb-0 small">Name: _________________________</p>
                <p class="mb-0 small">Designation: ___________________</p>
                <p class="mb-0 small">Date: __________________________</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-0"><strong>Office Seal</strong></p>
                <div class="border border-dark d-inline-block" style="width:120px;height:80px;margin-top:20px;"></div>
            </div>
        </div>
    </div>
</div>
