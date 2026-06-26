<?php
/**
 * RCS HRMS Pro - Payroll Reports
 */

$pageTitle = 'Payroll Reports';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientName = isset($_GET['client_name']) ? sanitize($_GET['client_name']) : '';
$reportType = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : 'summary';

// Build query with proper JOINs
$where = "pp.month = :month AND pp.year = :year";
$params = ['month' => $month, 'year' => $year];

if ($clientName) {
    $where .= " AND c.name = :client_name";
    $params['client_name'] = $clientName;
}

// Get data based on report type
if ($reportType === 'summary') {
    $sql = "SELECT 
                c.name as client_name,
                u.name as unit_name,
                COUNT(*) as total_employees,
                SUM(p.basic_da) as total_basic_da,
                SUM(p.hra) as total_hra,
                SUM(p.washing_allowance) as total_washing,
                SUM(p.gross_earnings) as total_gross,
                SUM(p.pf_employee) as total_pf_employee,
                SUM(p.pf_employer) as total_pf_employer,
                SUM(p.esi_employee) as total_esi_employee,
                SUM(p.esi_employer) as total_esi_employer,
                SUM(p.professional_tax) as total_pt,
                SUM(p.total_deductions) as total_deductions,
                SUM(p.net_pay) as total_net
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$where}
            GROUP BY c.name, u.name
            ORDER BY c.name, u.name";
} elseif ($reportType === 'detailed') {
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                e.designation,
                c.name as client_name,
                u.name as unit_name,
                p.basic_da,
                p.hra,
                p.washing_allowance,
                p.gross_earnings,
                p.pf_employee,
                p.esi_employee,
                p.professional_tax,
                p.total_deductions,
                p.net_pay
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$where}
            ORDER BY c.name, u.name, e.full_name";
} elseif ($reportType === 'statutory') {
    $sql = "SELECT 
                c.name as client_name,
                COUNT(*) as pf_members,
                SUM(CASE WHEN p.esi_employee > 0 THEN 1 ELSE 0 END) as esi_members,
                SUM(p.pf_employee) as employee_pf,
                SUM(p.pf_employer) as employer_pf,
                SUM(p.eps_employer) as employer_eps,
                SUM(p.esi_employee) as employee_esi,
                SUM(p.esi_employer) as employer_esi,
                SUM(p.professional_tax) as total_pt
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            LEFT JOIN clients c ON e.client_id = c.id
            WHERE {$where}
            GROUP BY c.name
            ORDER BY c.name";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$clients = $db->query("SELECT DISTINCT c.name as client_name FROM employees e LEFT JOIN clients c ON e.client_id = c.id WHERE c.name IS NOT NULL ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payroll_report_' . $month . '_' . $year . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Payroll Report - ' . date('F Y', mktime(0, 0, 0, $month, 1, $year))], ',', '"', '\\');
    fputcsv($output, [], ',', '"', '\\');
    
    if (!empty($reportData)) {
        fputcsv($output, array_keys($reportData[0]), ',', '"', '\\');
        foreach ($reportData as $row) {
            fputcsv($output, $row, ',', '"', '\\');
        }
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-cash-stack me-2"></i>Payroll Reports</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="report/payroll">
                    
                    <div class="col-md-2">
                        <label class="form-label">Report Type</label>
                        <select class="form-select" name="report_type">
                            <option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Summary</option>
                            <option value="detailed" <?php echo $reportType === 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                            <option value="statutory" <?php echo $reportType === 'statutory' ? 'selected' : ''; ?>>Statutory</option>
                        </select>
                    </div>
                    
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
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_name">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['client_name']); ?>"
                                    <?php echo $clientName === $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Generate
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportExcel()">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </form>
                
                <!-- Report Data -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <?php if ($reportType === 'summary'): ?>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-end">Employees</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF (EE)</th>
                                <th class="text-end">ESI (EE)</th>
                                <th class="text-end">PT</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grandGross = 0;
                            $grandPF = 0;
                            $grandESI = 0;
                            $grandPT = 0;
                            $grandDed = 0;
                            $grandNet = 0;
                            ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-end"><?php echo $row['total_employees']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_gross']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_pf_employee']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_esi_employee']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_pt']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_deductions']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($row['total_net']); ?></strong></td>
                            </tr>
                            <?php 
                            $grandGross += $row['total_gross'];
                            $grandPF += $row['total_pf_employee'];
                            $grandESI += $row['total_esi_employee'];
                            $grandPT += $row['total_pt'];
                            $grandDed += $row['total_deductions'];
                            $grandNet += $row['total_net'];
                            ?>
                            <?php endforeach; ?>
                            <tr class="table-dark">
                                <td colspan="3"><strong>Total</strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandGross); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandPF); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandESI); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandPT); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandDed); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandNet); ?></strong></td>
                            </tr>
                        </tbody>
                        
                        <?php elseif ($reportType === 'detailed'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Client</th>
                                <th class="text-end">Basic</th>
                                <th class="text-end">DA</th>
                                <th class="text-end">HRA</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF</th>
                                <th class="text-end">ESI</th>
                                <th class="text-end">PT</th>
                                <th class="text-end">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['basic']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['da']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['hra']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['gross_earnings']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['pf_employee']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['esi_employee']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['professional_tax']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($row['net_pay']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <?php elseif ($reportType === 'statutory'): ?>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-end">PF Members</th>
                                <th class="text-end">ESI Members</th>
                                <th class="text-end">PF (EE)</th>
                                <th class="text-end">PF (ER)</th>
                                <th class="text-end">EPS (ER)</th>
                                <th class="text-end">ESI (EE)</th>
                                <th class="text-end">ESI (ER)</th>
                                <th class="text-end">PT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td class="text-end"><?php echo $row['pf_members']; ?></td>
                                <td class="text-end"><?php echo $row['esi_members']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employee_pf']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employer_pf']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employer_eps']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employee_esi']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employer_esi']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_pt']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportExcel() {
    var params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'index.php?' + params.toString();
}
</script>
