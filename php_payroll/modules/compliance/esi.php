<?php
/**
 * RCS HRMS Pro - ESI Returns Generator
 * Generates ESI return file for ESIC portal upload
 * 
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOIN with clients and units tables to get client/unit names.
 */

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'ESI Returns';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientFilter = isset($_GET['client']) ? sanitize($_GET['client']) : '';

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");

// Get clients for filter - use clients table directly
$clients = $db->query(
    "SELECT DISTINCT c.name as client_name 
     FROM clients c 
     WHERE c.is_active = 1 
     ORDER BY client_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Build query for ESI data
$where = "pp.month = :month AND pp.year = :year AND ess.esi_applicable = 1";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND c.name = :client";
    $params[':client'] = $clientFilter;
}

// Get ESI contribution data
$sql = "SELECT 
            e.employee_code,
            e.full_name,
            e.esic_number as ip_number,
            e.aadhaar_number,
            e.father_name,
            e.gender,
            e.date_of_birth,
            e.date_of_joining,
            p.basic_da as esi_wages,
            p.esi_employee,
            p.esi_employer,
            p.present_days,
            c.name as client_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND ess.esi_applicable = 1
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE {$where}
        ORDER BY e.employee_code";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$esiData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'total_employees' => count($esiData),
    'total_wages' => array_sum(array_column($esiData, 'esi_wages')),
    'total_esi_employee' => array_sum(array_column($esiData, 'esi_employee')),
    'total_esi_employer' => array_sum(array_column($esiData, 'esi_employer'))
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-hospital me-2"></i>ESI Returns
            </h1>
            <p class="text-muted">Generate ESI return for ESIC portal</p>
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
                        <div class="text-white-50 small">Total ESI Members</div>
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
                        <div class="text-white-50 small">Employee ESI (0.75%)</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_esi_employee'], 2); ?></div>
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
                        <div class="text-white-50 small">Employer ESI (3.25%)</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_esi_employer'], 2); ?></div>
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
                        <div class="text-black-50 small">Total ESI Liability</div>
                        <div class="h4 mb-0"><?php echo '₹' . number_format($totals['total_esi_employee'] + $totals['total_esi_employer'], 2); ?></div>
                    </div>
                    <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-filter me-2"></i>Generate ESI Return</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="compliance/esi">
                    
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
                
                <?php if (!empty($esiData)): ?>
                <!-- Company Details -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <h6 class="mb-3"><i class="bi bi-building me-2"></i>Establishment Details</h6>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Company:</div>
                                <div class="col-8"><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">ESI Estb ID:</div>
                                <div class="col-8"><code><?php echo sanitize($company['esi_establishment_id'] ?? 'XXXXXXXXXX'); ?></code></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Period:</div>
                                <div class="col-8"><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <h6 class="mb-3"><i class="bi bi-calculator me-2"></i>Contribution Summary</h6>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">Total Wages:</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_wages'], 2); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">EE Share (0.75%):</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_esi_employee'], 2); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6 text-muted">ER Share (3.25%):</div>
                                <div class="col-6 text-end"><?php echo '₹' . number_format($totals['total_esi_employer'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="d-flex gap-2 mb-4">
                    <a href="index.php?page=compliance/esi-export&month=<?php echo $month; ?>&year=<?php echo $year; ?>&client=<?php echo urlencode($clientFilter); ?>" 
                       class="btn btn-success">
                        <i class="bi bi-download me-1"></i>Export ESI Data
                    </a>
                    <button type="button" class="btn btn-outline-info" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print Summary
                    </button>
                </div>
                
                <!-- ESI Details Table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="esiTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>IP Number</th>
                                <th>DOJ</th>
                                <th>Days</th>
                                <th class="text-end">ESI Wages</th>
                                <th class="text-end">EE ESI</th>
                                <th class="text-end">ER ESI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($esiData as $row): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><small><?php echo sanitize($row['ip_number'] ?? '-'); ?></small></td>
                                <td><?php echo formatDate($row['date_of_joining']); ?></td>
                                <td><?php echo $row['present_days'] ?? 30; ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['esi_wages'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['esi_employee'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($row['esi_employer'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-dark">
                                <td colspan="6"><strong>TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_wages'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_esi_employee'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($totals['total_esi_employer'], 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No ESI data found for the selected period.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#esiTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[1, 'asc']]
    });
});
</script>
