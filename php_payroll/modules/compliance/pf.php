<?php
/**
 * RCS HRMS Pro - PF ECR File Generator
 * Generates ECR (Electronic Challan-cum-Return) file for EPFO upload
 * 
 * ECR File Format as per EPFO specifications
 */

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'PF ECR Generator';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientFilter = isset($_GET['client']) ? sanitize($_GET['client']) : '';

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");

// Get clients for filter
$clients = $db->query(
    "SELECT DISTINCT c.name as client_name 
     FROM employees e 
     LEFT JOIN clients c ON e.client_id = c.id 
     WHERE c.name IS NOT NULL AND c.name != '' 
     ORDER BY c.name"
)->fetchAll(PDO::FETCH_ASSOC);

// Build query for PF data
$where = "pp.month = :month AND pp.year = :year AND ess.pf_applicable = 1";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND c.name = :client";
    $params[':client'] = $clientFilter;
}

// Get PF contribution data
$sql = "SELECT 
            e.employee_code,
            e.full_name,
            e.uan_number,
            e.aadhaar_number,
            e.father_name,
            e.gender,
            e.date_of_birth,
            e.date_of_joining,
            e.date_of_joining as pf_joining_date,
            p.basic_da,
            p.basic_da as epf_wages,
            p.basic_da as eps_wages,
            p.pf_employee,
            p.pf_employer,
            p.pf_employer as eps_contribution,
            0 as edli_wages,
            0 as edli_contribution,
            c.name as client_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND ess.pf_applicable = 1
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE {$where}
        ORDER BY e.employee_code";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$pfData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'total_employees' => count($pfData),
    'total_epf_wages' => array_sum(array_column($pfData, 'epf_wages')),
    'total_eps_wages' => array_sum(array_column($pfData, 'eps_wages')),
    'total_pf_employee' => array_sum(array_column($pfData, 'pf_employee')),
    'total_pf_employer' => array_sum(array_column($pfData, 'pf_employer')),
    'total_eps_contribution' => array_sum(array_column($pfData, 'eps_contribution'))
];

// Generate ECR file
$ecrContent = '';
$fileName = '';

if (isset($_POST['generate_ecr']) && !empty($pfData)) {
    $estbId = $company['pf_establishment_id'] ?? 'GJXXX123456789';
    $wageMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
    $wageYear = $year;
    $discriminatingNo = '01'; // Default for principal employer
    
    // ECR Header Record
    $ecrContent .= "#ERS:" . $estbId . "~" . $discriminatingNo . "~" . $wageMonth . "~" . $wageYear . "\r\n";
    
    // ECR Detail Records
    foreach ($pfData as $row) {
        $uan = str_pad(preg_replace('/[^0-9]/', '', $row['uan_number'] ?? ''), 12, '0', STR_PAD_LEFT);
        $memberId = !empty($uan) ? $uan : str_pad(preg_replace('/[^0-9]/', '', $row['employee_code']), 12, '0', STR_PAD_LEFT);
        
        // Format as per EPFO ECR format
        $line = [
            $memberId,                                           // UAN/Member ID
            $row['employee_code'],                               // Member Name (using code as placeholder)
            substr(strtoupper(preg_replace('/[^A-Za-z\s]/', '', $row['full_name'] ?? '')), 0, 50), // Name
            '01',                                                // Relationship (01=Father)
            substr(strtoupper(preg_replace('/[^A-Za-z\s]/', '', $row['father_name'] ?? '')), 0, 50), // Father's Name
            ($row['gender'] ?? 'M') == 'male' ? 'M' : 'F',       // Gender
            date('d/m/Y', strtotime($row['date_of_birth'] ?? '01/01/1990')), // DOB
            in_array($row['pf_joining_date'] ?? '', ['', '0000-00-00']) ? 
                date('d/m/Y', strtotime($row['date_of_joining'])) : 
                date('d/m/Y', strtotime($row['pf_joining_date'])), // DOJ PF
            'EPS',                                               // EPF/EPS Status
            'N',                                                 // Reason for leaving
            '',                                                  // DOL
            number_format($row['epf_wages'] ?? 0, 0, '', ''),   // EPF Wages
            number_format($row['eps_wages'] ?? 0, 0, '', ''),   // EPS Wages
            number_format($row['pf_employee'] ?? 0, 0, '', ''), // EPF Contribution
            number_format($row['eps_contribution'] ?? 0, 0, '', ''), // EPS Contribution
            number_format($row['epf_wages'] ?? 0, 0, '', ''),   // EDLI Wages
            '0',                                                 // EDLI Contribution
            'N',                                                 // NCP Days
            '0'                                                  // Refund
        ];
        
        $ecrContent .= implode('~', $line) . "\r\n";
    }
    
    // ECR Footer Record
    $ecrContent .= "#ERS:" . $estbId . "~" . $wageMonth . "~" . $wageYear . "~" . 
                   $totals['total_employees'] . "~" .
                   number_format($totals['total_epf_wages'], 0, '', '') . "~" .
                   number_format($totals['total_eps_wages'], 0, '', '') . "~" .
                   number_format($totals['total_pf_employee'], 0, '', '') . "~" .
                   number_format($totals['total_eps_contribution'], 0, '', '') . "\r\n";
    
    $fileName = 'ECR_' . $estbId . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . $year . '.txt';
    
    // Force download
    if (isset($_POST['download_ecr'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($ecrContent));
        echo $ecrContent;
        exit;
    }
}

// Get PF summary from compliance class
$pfSummary = [
    'total_pf_liability' => $totals['total_pf_employee'] + $totals['total_pf_employer'],
    'pending_filings' => 0
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-piggy-bank me-2"></i>PF ECR Generator
            </h1>
            <p class="text-muted">Generate Electronic Challan-cum-Return file for EPFO</p>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total PF Members</div>
                        <div class="h3 mb-0"><?php echo number_format($totals['total_employees']); ?></div>
                    </div>
                    <i class="bi bi-people fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Employee PF Contribution</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_pf_employee'], 2); ?></div>
                    </div>
                    <i class="bi bi-person-check fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Employer PF Contribution</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_pf_employer'], 2); ?></div>
                    </div>
                    <i class="bi bi-building fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Total PF Liability</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_pf_employee'] + $totals['total_pf_employer'], 2); ?></div>
                    </div>
                    <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Generate -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-filter me-2"></i>Generate ECR File</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="compliance/pf">
                    
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo sanitize($c['client_name']); ?>" <?php echo $clientFilter == $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Load Data
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($pfData)): ?>
                <!-- Company Details for ECR -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <h6 class="mb-3"><i class="bi bi-building me-2"></i>Establishment Details</h6>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Company:</div>
                                <div class="col-8"><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">PF Estb ID:</div>
                                <div class="col-8"><code><?php echo sanitize($company['pf_establishment_id'] ?? 'GJXXX123456789'); ?></code></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Period:</div>
                                <div class="col-8"><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <h6 class="mb-3"><i class="bi bi-calculator me-2"></i>Summary</h6>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">EPF Wages:</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_epf_wages'], 2); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">EPS Wages:</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_eps_wages'], 2); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">EE Share:</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_pf_employee'], 2); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">ER Share:</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_pf_employer'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ECR Actions -->
                <div class="d-flex gap-2 mb-4">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="generate_ecr" value="1">
                        <button type="submit" name="download_ecr" class="btn btn-success">
                            <i class="bi bi-download me-1"></i>Download ECR File
                        </button>
                    </form>
                    <button type="button" class="btn btn-outline-primary" onclick="previewECR()">
                        <i class="bi bi-eye me-1"></i>Preview ECR
                    </button>
                    <button type="button" class="btn btn-outline-info" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print Summary
                    </button>
                </div>
                
                <!-- Employee PF Details Table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="pfTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>UAN</th>
                                <th>DOJ</th>
                                <th class="text-end">EPF Wages</th>
                                <th class="text-end">EPS Wages</th>
                                <th class="text-end">EE PF</th>
                                <th class="text-end">ER PF</th>
                                <th class="text-end">EPS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($pfData as $row): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><small><?php echo sanitize($row['uan_number'] ?? '-'); ?></small></td>
                                <td><?php echo formatDate($row['date_of_joining']); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['epf_wages'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['eps_wages'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['pf_employee'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['pf_employer'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['eps_contribution'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-dark">
                                <td colspan="5"><strong>TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_epf_wages'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_eps_wages'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_pf_employee'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_pf_employer'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_eps_contribution'], 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No PF data found for the selected period. 
                    Please ensure payroll has been processed for <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ECR Preview Modal -->
<div class="modal fade" id="ecrPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ECR File Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="ecrPreviewContent" class="bg-dark text-light p-3" style="max-height: 500px; overflow: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
// Global function for onclick handler
window.previewECR = function() {
    // Generate preview via AJAX or show from current data
    let previewContent = `#ERS:<?php echo $company['pf_establishment_id'] ?? 'GJXXX123456789'; ?>~01~<?php echo str_pad($month, 2, '0', STR_PAD_LEFT); ?>~<?php echo $year; ?>

<?php foreach ($pfData as $row): ?>
UAN: <?php echo $row['uan_number'] ?? $row['employee_code']; ?> | Name: <?php echo $row['full_name']; ?> | EPF Wages: <?php echo $row['epf_wages']; ?> | EE Share: <?php echo $row['pf_employee']; ?> | ER Share: <?php echo $row['pf_employer']; ?>

<?php endforeach; ?>`;

    document.getElementById('ecrPreviewContent').textContent = previewContent;
    new bootstrap.Modal('#ecrPreviewModal').show();
};

$(document).ready(function() {
    $('#pfTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[1, 'asc']]
    });
});
</script>
<style>
@media print {
    .btn, form, .modal { display: none !important; }
    .card { border: none !important; }
    .table { font-size: 10pt; }
}
</style>
JS;
