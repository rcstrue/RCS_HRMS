<?php
/**
 * RCS HRMS Pro - Compliance Reports
 */

$pageTitle = 'Compliance Reports';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$reportType = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : 'pf';

// Get filter options - use clients table
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get report data
$reportData = [];

if ($reportType === 'pf') {
    // PF Report - use JOINs for client_name and unit_name
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                e.uan_number as pf_number,
                c.name as client_name,
                u.name as unit_name,
                ess.pf_applicable,
                p.basic_da as epf_wages,
                p.basic_da as eps_wages,
                p.pf_employee as epf_contribution,
                p.eps_employer as eps_contribution,
                p.pf_employer as er_contribution
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            JOIN employee_salary_structures ess ON e.id = ess.employee_id
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            WHERE pp.month = :month AND pp.year = :year
            AND ess.pf_applicable = 1";
    
    $params = ['month' => $month, 'year' => $year];
    
    if ($clientId) {
        $sql .= " AND e.client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    
    $sql .= " ORDER BY e.full_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($reportType === 'esi') {
    // ESI Report - use JOINs for client_name and unit_name
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                e.esic_number as esi_number,
                c.name as client_name,
                u.name as unit_name,
                ess.esi_applicable,
                ess.gross_salary,
                p.gross_earnings,
                p.esi_employee as employee_contribution,
                p.esi_employer as employer_contribution
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            JOIN employee_salary_structures ess ON e.id = ess.employee_id
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            WHERE pp.month = :month AND pp.year = :year
            AND ess.esi_applicable = 1
            AND ess.gross_salary <= 21000";
    
    $params = ['month' => $month, 'year' => $year];
    
    if ($clientId) {
        $sql .= " AND e.client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    
    $sql .= " ORDER BY e.full_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($reportType === 'pt') {
    // Professional Tax Report - use JOINs for client_name and unit_name
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                c.name as client_name,
                u.name as unit_name,
                p.gross_earnings,
                p.professional_tax
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            WHERE pp.month = :month AND pp.year = :year
            AND p.professional_tax > 0";
    
    $params = ['month' => $month, 'year' => $year];
    
    if ($clientId) {
        $sql .= " AND e.client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    
    $sql .= " ORDER BY e.full_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($reportType === 'minimum_wages') {
    // Minimum Wages Compliance Check - use JOINs for client_name and unit_name
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                c.name as client_name,
                u.name as unit_name,
                e.designation,
                ess.gross_salary,
                ess.basic_da as total_basic,
                e.state
            FROM employees e
            JOIN employee_salary_structures ess ON e.id = ess.employee_id
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE e.status = 'approved'";
    
    $params = [];
    
    if ($clientId) {
        $sql .= " AND e.client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    
    $sql .= " ORDER BY e.full_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $reportType . '_report_' . $month . '_' . $year . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [strtoupper($reportType) . ' Report - ' . date('F Y', mktime(0, 0, 0, $month, 1, $year))], ',', '"', '\\');
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
                <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i>Compliance Reports</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="report/compliance">
                    
                    <div class="col-md-2">
                        <label class="form-label">Report Type</label>
                        <select class="form-select" name="report_type">
                            <option value="pf" <?php echo $reportType === 'pf' ? 'selected' : ''; ?>>PF Report</option>
                            <option value="esi" <?php echo $reportType === 'esi' ? 'selected' : ''; ?>>ESI Report</option>
                            <option value="pt" <?php echo $reportType === 'pt' ? 'selected' : ''; ?>>PT Report</option>
                            <option value="minimum_wages" <?php echo $reportType === 'minimum_wages' ? 'selected' : ''; ?>>Min Wages Check</option>
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
                        <select class="form-select" name="client_id">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['id']); ?>"
                                    <?php echo $clientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
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
                        <?php if ($reportType === 'pf'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>PF Number</th>
                                <th>Client</th>
                                <th class="text-end">EPF Wages</th>
                                <th class="text-end">EPS Wages</th>
                                <th class="text-end">EE Contribution</th>
                                <th class="text-end">ER Contribution</th>
                                <th class="text-end">EPS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalEpfWages = 0;
                            $totalEpsWages = 0;
                            $totalEE = 0;
                            $totalER = 0;
                            $totalEPS = 0;
                            ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['pf_number']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['epf_wages']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['eps_wages']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['epf_contribution']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['er_contribution']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['eps_contribution']); ?></td>
                            </tr>
                            <?php 
                            $totalEpfWages += $row['epf_wages'];
                            $totalEpsWages += $row['eps_wages'];
                            $totalEE += $row['epf_contribution'];
                            $totalER += $row['er_contribution'];
                            $totalEPS += $row['eps_contribution'];
                            ?>
                            <?php endforeach; ?>
                            <tr class="table-dark">
                                <td colspan="4"><strong>Total</strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalEpfWages); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalEpsWages); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalEE); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalER); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalEPS); ?></strong></td>
                            </tr>
                        </tbody>
                        
                        <?php elseif ($reportType === 'esi'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>ESI Number</th>
                                <th>Client</th>
                                <th class="text-end">Gross Salary</th>
                                <th class="text-end">EE Contribution</th>
                                <th class="text-end">ER Contribution</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['esi_number']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['gross_earnings']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employee_contribution']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['employer_contribution']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($row['employee_contribution'] + $row['employer_contribution']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <?php elseif ($reportType === 'pt'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['gross_earnings']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['professional_tax']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <?php elseif ($reportType === 'minimum_wages'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Client</th>
                                <th>Designation</th>
                                <th class="text-end">Basic+DA</th>
                                <th class="text-end">Gross</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <?php 
                            // Simple minimum wage check (Rs. 17800 basic for skilled workers)
                            $minWage = 17800;
                            $isCompliant = $row['total_basic'] >= $minWage;
                            ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td><?php echo sanitize($row['designation']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_basic']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['gross_salary']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $isCompliant ? 'success' : 'danger'; ?>">
                                        <?php echo $isCompliant ? 'Compliant' : 'Non-Compliant'; ?>
                                    </span>
                                </td>
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
