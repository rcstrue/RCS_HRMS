<?php
/**
 * RCS HRMS Pro - Gratuity Form F
 * Gratuity provisions per employee with service calculation
 * Gratuity = (Basic+DA × 15 days × Years of Service) / 26
 * Monthly Provision = Gratuity / 12
 * Rate: 4.81% of Basic+DA per month
 */

$pageTitle = 'Gratuity Form F';

$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

$units = [];
if ($clientFilter) {
    try {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$clientFilter]);
    } catch (Exception $e) {
        $units = [];
    }
}

// Build query - active employees who have completed 5+ years or are eligible
$where = "e.status = 1";
$params = [];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}
if ($unitFilter) {
    $where .= " AND e.unit_id = :uid";
    $params[':uid'] = $unitFilter;
}

$sql = "SELECT 
            e.id, e.employee_code, e.full_name, e.father_name, e.designation, 
            e.date_of_joining, e.date_of_leaving, e.gender,
            u.name as unit_name, c.name as client_name,
            ess.basic_da, ess.gratuity_applicable,
            p.gratuity_provision, p.gratuity_provision as last_provision,
            p.basic_da as last_basic_da
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
        LEFT JOIN units u ON e.unit_id = u.id
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN payroll p ON e.employee_code = p.employee_id
            AND p.payroll_period_id = (
                SELECT id FROM payroll_periods WHERE year = :year ORDER BY month DESC LIMIT 1
            )
        WHERE $where
        ORDER BY e.employee_code";

$params[':year'] = $year;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $employees = [];
    $error = $e->getMessage();
}

// Process gratuity calculations
$gratuityData = [];
$grandTotals = [
    'basic_da' => 0, 'monthly_provision' => 0, 'annual_provision' => 0, 'count' => 0,
    'eligible_count' => 0
];

$today = new DateTime();

foreach ($employees as $emp) {
    $basicDa = floatval($emp['basic_da'] ?? $emp['last_basic_da'] ?? 0);
    $doj = $emp['date_of_joining'];
    
    if (!$doj || $basicDa <= 0) continue;
    
    $dojDate = new DateTime($doj);
    $dolDate = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : $today;
    
    // Calculate service
    $interval = $dojDate->diff($dolDate);
    $totalMonths = $interval->y * 12 + $interval->m;
    $serviceYears = $interval->y;
    $serviceMonths = $interval->m;
    
    // Gratuity formula: (Basic+DA × 15 × Years) / 26
    // For partial year: (Basic+DA × 15 × TotalMonths) / (26 × 12)
    $gratuityAmount = ($basicDa * 15 * $totalMonths) / (26 * 12);
    $gratuityAmount = round($gratuityAmount, 0);
    
    // Monthly provision at 4.81% rate
    $gratuityRate = 0.0481;
    $monthlyProvision = round($basicDa * $gratuityRate, 0);
    $annualProvision = $monthlyProvision * 12;
    
    // Check if gratuity is applicable (5 years completed, excluding death/disability)
    $isEligible = $serviceYears >= 5;
    
    // If gratuity_applicable field exists and is set
    if (isset($emp['gratuity_applicable'])) {
        $isEligible = $emp['gratuity_applicable'] == 1 || $serviceYears >= 5;
    }
    
    $gratuityData[] = [
        'employee' => $emp,
        'basic_da' => $basicDa,
        'service_years' => $serviceYears,
        'service_months' => $serviceMonths,
        'service_display' => $serviceYears . ' Y - ' . $serviceMonths . ' M',
        'total_months' => $totalMonths,
        'gratuity_amount' => $gratuityAmount,
        'gratuity_rate' => $gratuityRate,
        'monthly_provision' => $monthlyProvision,
        'annual_provision' => $annualProvision,
        'is_eligible' => $isEligible
    ];
    
    $grandTotals['basic_da'] += $basicDa;
    $grandTotals['monthly_provision'] += $monthlyProvision;
    $grandTotals['annual_provision'] += $annualProvision;
    $grandTotals['count']++;
    if ($isEligible) $grandTotals['eligible_count']++;
}

// Sort: eligible first
usort($gratuityData, fn($a, $b) => ($b['is_eligible'] <=> $a['is_eligible']));

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'gratuity_form_f_' . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Gratuity Form F - Year ' . $year]);
    fputcsv($output, ['#','Emp Code','Name','Father Name','Designation','DOJ','Current Basic+DA',
                      'Total Service (Y-M)','Gratuity Rate','Monthly Provision','Annual Provision']);
    
    foreach ($gratuityData as $i => $gd) {
        $emp = $gd['employee'];
        fputcsv($output, [
            $i+1, $emp['employee_code'], $emp['full_name'], $emp['father_name'],
            $emp['designation'], $emp['date_of_joining'], $gd['basic_da'],
            $gd['service_display'], ($gd['gratuity_rate'] * 100) . '%',
            $gd['monthly_provision'], $gd['annual_provision']
        ]);
    }
    fputcsv($output, ['','TOTAL','','','','', $grandTotals['basic_da'], '', '', 
        $grandTotals['monthly_provision'], $grandTotals['annual_provision']]);
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-award me-2"></i>Gratuity Form F</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" onclick="window.location.href+='&export=csv'">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
                <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/gratuity-form-f">
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Generate</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Info Banner -->
        <div class="alert alert-info py-2 mb-3">
            <small><i class="bi bi-info-circle me-1"></i>
            Gratuity provision calculated at <strong>4.81%</strong> of Basic+DA per month.
            Gratuity = (Basic+DA × 15 × Total Months) / (26 × 12). 
            Eligibility: 5 years of continuous service.</small>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Employees</small>
                        <div class="h5 mb-0"><?php echo $grandTotals['count']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Eligible (5+ Yrs)</small>
                        <div class="h5 mb-0 text-success"><?php echo $grandTotals['eligible_count']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Monthly Provision</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($grandTotals['monthly_provision']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Annual Provision</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($grandTotals['annual_provision']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">GRATUITY FORM F - PROVISION REGISTER</h5>
            <small class="text-muted">Year: <?php echo $year; ?></small>
        </div>

        <!-- Data Table -->
        <?php if (empty($gratuityData)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No employees found with gratuity data.
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:30px;">#</th>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Father Name</th>
                                <th>Designation</th>
                                <th>DOJ</th>
                                <th class="text-end">Current Basic+DA</th>
                                <th class="text-center">Total Service</th>
                                <th class="text-center">Gratuity Rate</th>
                                <th class="text-end">Monthly Provision</th>
                                <th class="text-end">Annual Provision</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gratuityData as $i => $gd):
                                $emp = $gd['employee'];
                                $rowClass = $gd['is_eligible'] ? '' : 'table-secondary opacity-75';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="text-center"><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['father_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td><?php echo $emp['date_of_joining'] ? formatDate($emp['date_of_joining']) : '-'; ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($gd['basic_da'],0); ?></td>
                                <td class="text-center">
                                    <span class="fw-bold"><?php echo $gd['service_display']; ?></span>
                                    <small class="text-muted d-block">(<?php echo $gd['total_months']; ?> months)</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info">4.81%</span>
                                </td>
                                <td class="text-end fw-bold text-danger"><?php echo number_format($gd['monthly_provision'],0); ?></td>
                                <td class="text-end fw-bold text-primary"><?php echo number_format($gd['annual_provision'],0); ?></td>
                                <td class="text-center">
                                    <?php if ($gd['is_eligible']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Eligible</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-hourglass me-1"></i>Not Yet</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="6"><strong>TOTAL (<?php echo $grandTotals['count']; ?> Employees, 
                                    <?php echo $grandTotals['eligible_count']; ?> Eligible)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['basic_da'],0); ?></strong></td>
                                <td colspan="2"></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['monthly_provision'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['annual_provision'],0); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Gratuity Calculation Reference -->
        <div class="card mt-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-calculator me-1"></i>Gratuity Calculation Reference</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0" style="font-size:0.82rem;">
                            <tr><td><strong>Formula:</strong></td><td>(Basic+DA × 15 × Total Months) / (26 × 12)</td></tr>
                            <tr><td><strong>Provision Rate:</strong></td><td>4.81% of Basic+DA per month</td></tr>
                            <tr><td><strong>Eligibility:</strong></td><td>5 years of continuous service</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0" style="font-size:0.82rem;">
                            <tr><td><strong>Total Monthly Provision:</strong></td><td class="text-end fw-bold text-danger"><?php echo formatCurrency($grandTotals['monthly_provision']); ?></td></tr>
                            <tr><td><strong>Total Annual Provision:</strong></td><td class="text-end fw-bold text-primary"><?php echo formatCurrency($grandTotals['annual_provision']); ?></td></tr>
                            <tr><td><strong>As on Date:</strong></td><td class="text-end"><?php echo date('d-M-Y'); ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form, .alert-info { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: none !important; box-shadow: none !important; }
    .opacity-75 { opacity: 1 !important; }
}
</style>
