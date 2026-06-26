<?php
/**
 * RCS HRMS Pro - LWF (Labour Welfare Fund) Report
 * State-wise grouping with employee and employer contribution
 */

$pageTitle = 'LWF Report';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$stateFilter = sanitize($_GET['state'] ?? '');
$clientFilter = (int)($_GET['client_id'] ?? 0);

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

// Get distinct states from employees and units
$states = [];
try {
    $stateData = $db->query("SELECT DISTINCT state FROM employees WHERE state IS NOT NULL AND state != '' ORDER BY state")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stateData as $s) {
        $states[] = $s['state'];
    }
} catch (Exception $e) {
    // Ignore
}

// Get LWF rates
$lwfRates = [];
try {
    $rateData = $db->query("SELECT state, employee_contribution, employer_contribution, effective_from 
                            FROM lwf_rates 
                            WHERE effective_from <= CONCAT(:year, '-', LPAD(:month,2,'0'), '-01')
                            ORDER BY state, effective_from DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rateData as $rate) {
        if (!isset($lwfRates[$rate['state']])) {
            $lwfRates[$rate['state']] = [
                'employee' => floatval($rate['employee_contribution']),
                'employer' => floatval($rate['employer_contribution'])
            ];
        }
    }
} catch (Exception $e) {
    // lwf_rates table might not exist - will use defaults
}

// Build query
$where = "pp.month = :month AND pp.year = :year";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}

// Only employees with LWF applicable
$where .= " AND (ess.lwf_applicable = 1 OR p.lwf_employee > 0)";

$sql = "SELECT 
            e.employee_code, e.full_name, e.state,
            u.name as unit_name, c.name as client_name,
            p.gross_salary, p.lwf_employee, p.lwf_employer,
            ess.lwf_applicable
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
        LEFT JOIN units u ON e.unit_id = u.id
        LEFT JOIN clients c ON e.client_id = c.id
        WHERE $where
        ORDER BY e.state, u.name, e.employee_code";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data = [];
    $error = $e->getMessage();
}

// Apply state filter
if ($stateFilter) {
    $data = array_filter($data, fn($row) => $row['state'] === $stateFilter);
}

// Group by state
$stateGroups = [];
$stateTotals = [];
$grandTotals = ['gross' => 0, 'lwf_emp' => 0, 'lwf_empr' => 0, 'count' => 0];

foreach ($data as $row) {
    $state = $row['state'] ?: 'Unknown';
    if (!isset($stateGroups[$state])) {
        $stateGroups[$state] = [];
        $stateTotals[$state] = ['gross' => 0, 'lwf_emp' => 0, 'lwf_empr' => 0, 'count' => 0];
    }
    
    $stateGroups[$state][] = $row;
    
    $gross = floatval($row['gross_salary']);
    $lwfEmp = floatval($row['lwf_employee']);
    $lwfEmpr = floatval($row['lwf_employer']);
    
    $stateTotals[$state]['gross'] += $gross;
    $stateTotals[$state]['lwf_emp'] += $lwfEmp;
    $stateTotals[$state]['lwf_empr'] += $lwfEmpr;
    $stateTotals[$state]['count']++;
    
    $grandTotals['gross'] += $gross;
    $grandTotals['lwf_emp'] += $lwfEmp;
    $grandTotals['lwf_empr'] += $lwfEmpr;
    $grandTotals['count']++;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'lwf_report_' . date('M_Y', mktime(0,0,0,$month,1,$year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['LWF Report - ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
    fputcsv($output, ['#','Emp Code','Name','Unit','State','Gross Salary','LWF Employee Share','LWF Employer Share']);
    
    foreach ($stateGroups as $state => $rows) {
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            fputcsv($output, [$i, $row['employee_code'], $row['full_name'], $row['unit_name'],
                $row['state'], $row['gross_salary'], $row['lwf_employee'], $row['lwf_employer']]);
        }
        fputcsv($output, ['', '', '', 'Subtotal: ' . $state, '', 
            $stateTotals[$state]['gross'], $stateTotals[$state]['lwf_emp'], $stateTotals[$state]['lwf_empr']]);
        fputcsv($output, []);
    }
    
    fputcsv($output, ['', '', '', 'GRAND TOTAL', '', 
        $grandTotals['gross'], $grandTotals['lwf_emp'], $grandTotals['lwf_empr']]);
    fclose($output);
    exit;
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>LWF Report</h4>
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
                    <input type="hidden" name="page" value="report/lwf-report">
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>><?php echo date('M', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">State</label>
                        <select name="state" class="form-select form-select-sm">
                            <option value="">All States</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?php echo sanitize($s); ?>" <?php echo $stateFilter===$s?'selected':''; ?>><?php echo sanitize($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Generate</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Employees</small>
                        <div class="h5 mb-0"><?php echo $grandTotals['count']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">States</small>
                        <div class="h5 mb-0"><?php echo count($stateGroups); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">LWF Employee</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($grandTotals['lwf_emp']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">LWF Employer</small>
                        <div class="h5 mb-0 text-warning"><?php echo formatCurrency($grandTotals['lwf_empr']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total LWF</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($grandTotals['lwf_emp'] + $grandTotals['lwf_empr']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">LWF (LABOUR WELFARE FUND) REPORT</h5>
            <small class="text-muted"><?php echo strtoupper($monthName) . ' ' . $year; ?></small>
        </div>

        <!-- Data Table -->
        <?php if (empty($data)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No LWF-applicable employees found for selected period.
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
                                <th>Unit</th>
                                <th>State</th>
                                <th class="text-end">Gross Salary</th>
                                <th class="text-end" style="background:#dc3545;">LWF Employee</th>
                                <th class="text-end" style="background:#fd7e14;">LWF Employer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stateGroups as $state => $rows): ?>
                            <!-- State Header -->
                            <tr class="table-secondary">
                                <td colspan="8" class="fw-bold">
                                    <i class="bi bi-geo-alt me-1"></i>
                                    <?php echo sanitize($state); ?>
                                    <span class="text-muted">(<?php echo $stateTotals[$state]['count']; ?> Employees)</span>
                                    <?php if (isset($lwfRates[$state])): ?>
                                    <span class="badge bg-info ms-2">
                                        EE: <?php echo $lwfRates[$state]['employee']; ?> | ER: <?php echo $lwfRates[$state]['employer']; ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $stateIdx = 0; ?>
                            <?php foreach ($rows as $row):
                                $stateIdx++;
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $stateIdx; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['unit_name']); ?></td>
                                <td><?php echo sanitize($row['state']); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['gross_salary']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['lwf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['lwf_employer']),0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- State Subtotal -->
                            <tr class="table-warning">
                                <td colspan="5" class="text-end fw-bold">Subtotal - <?php echo sanitize($state); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($stateTotals[$state]['gross'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($stateTotals[$state]['lwf_emp'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($stateTotals[$state]['lwf_empr'],0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <!-- Grand Total -->
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="5"><strong>GRAND TOTAL (<?php echo $grandTotals['count']; ?> Employees, <?php echo count($stateGroups); ?> States)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['gross'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['lwf_emp'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['lwf_empr'],0); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- LWF Rates Reference -->
        <?php if (!empty($lwfRates)): ?>
        <div class="card mt-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>LWF Rate Reference</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">
                        <thead class="table-light">
                            <tr>
                                <th>State</th>
                                <th class="text-end">Employee Contribution (₹)</th>
                                <th class="text-end">Employer Contribution (₹)</th>
                                <th class="text-end">Total (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lwfRates as $state => $rate): ?>
                            <tr>
                                <td><?php echo sanitize($state); ?></td>
                                <td class="text-end"><?php echo number_format($rate['employee'],2); ?></td>
                                <td class="text-end"><?php echo number_format($rate['employer'],2); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($rate['employee'] + $rate['employer'],2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
