<?php
/**
 * RCS HRMS Pro - ESI Reports Hub
 * Form 7 (Register), Summary (Employee + Company wise), Challan, Excel Export
 */

$pageTitle = 'ESI Reports';

$tab = sanitize($_GET['tab'] ?? 'register');
$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$monthName = date('F', mktime(0,0,0,$month,1,$year));

// Base WHERE for ESI applicable
$baseWhere = "pp.month = :month AND pp.year = :year AND ess.esi_applicable = 1";
$baseParams = [':month' => $month, ':year' => $year];
if ($clientFilter) { $baseWhere .= " AND e.client_id = :cid"; $baseParams[':cid'] = $clientFilter; }

// Get ESI data
$esiData = $db->fetchAll(
    "SELECT e.employee_code, e.full_name, e.father_name, e.gender, e.date_of_joining,
            e.date_of_leaving, e.esi_number, e.uan_number, e.aadhaar_number,
            ess.gross_salary as monthly_gross,
            p.gross_salary, p.gross_earnings,
            p.paid_days, p.total_days,
            p.esi_employee, p.esi_employer, p.pf_employee,
            c.name as client_name, u.name as unit_name
     FROM payroll p
     JOIN employees e ON p.employee_id = e.employee_code
     JOIN payroll_periods pp ON p.payroll_period_id = pp.id
     LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE $baseWhere
     ORDER BY c.name, e.employee_code",
    $baseParams
);

// Totals
$esiTotals = ['gross' => 0, 'ee_esi' => 0, 'er_esi' => 0, 'count' => count($esiData)];
foreach ($esiData as $r) {
    $esiTotals['gross'] += floatval($r['gross_salary'] ?? $r['gross_earnings'] ?? 0);
    $esiTotals['ee_esi'] += floatval($r['esi_employee']);
    $esiTotals['er_esi'] += floatval($r['esi_employer']);
}
$esiTotals['total'] = $esiTotals['ee_esi'] + $esiTotals['er_esi'];

// Company-wise summary
$companyWise = [];
foreach ($esiData as $r) {
    $cn = $r['client_name'] ?? 'Unknown';
    if (!isset($companyWise[$cn])) $companyWise[$cn] = ['count'=>0,'gross'=>0,'ee'=>0,'er'=>0];
    $companyWise[$cn]['count']++;
    $companyWise[$cn]['gross'] += floatval($r['gross_salary'] ?? $r['gross_earnings'] ?? 0);
    $companyWise[$cn]['ee'] += floatval($r['esi_employee']);
    $companyWise[$cn]['er'] += floatval($r['esi_employer']);
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'esi_report_' . $tab . '_' . $monthName . '_' . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ESI Report - ' . str_replace('_',' ',ucfirst($tab)) . ' - ' . $monthName . ' ' . $year]);
    fputcsv($output, ['#','Code','Name','Father Name','Gender','DOJ','ESI No','UAN','Monthly Gross','PD','Gross Earned','EE ESI (0.75%)','ER ESI (3.25%)']);
    foreach ($esiData as $i => $r) {
        fputcsv($output, [$i+1, $r['employee_code'], $r['full_name'], $r['father_name'] ?? '',
            $r['gender'] ?? '', $r['date_of_joining'] ?? '', $r['esi_number'] ?? '',
            $r['uan_number'] ?? '', $r['monthly_gross'], $r['paid_days'],
            $r['gross_salary'] ?? $r['gross_earnings'], $r['esi_employee'], $r['esi_employer']]);
    }
    fputcsv($output, ['','TOTAL','','','','','','','','',$esiTotals['gross'],$esiTotals['ee_esi'],$esiTotals['er_esi']]);
    fclose($output);
    exit;
}

// ESI Excel format for online upload
if (isset($_GET['export']) && $_GET['export'] === 'esi_excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="esi_online_' . $monthName . '_' . $year . '.xls"');
    $output = fopen('php://output', 'w');
    // ESI Online format header
    fputcsv($output, ['IP Number', 'Employee Name', 'Father Name', 'Gender', 'DOB', 'DOJ', 'DOL',
        'ESI Number', 'Aadhaar', 'Monthly Gross', 'No of Days Worked', 'OT Amount',
        'Total Wages', 'EE Contribution', 'ER Contribution']);
    foreach ($esiData as $r) {
        fputcsv($output, [
            $r['employee_code'], $r['full_name'], $r['father_name'] ?? '',
            strtoupper(substr($r['gender'] ?? 'M', 0, 1)),
            '', $r['date_of_joining'] ?? '', $r['date_of_leaving'] ?? '',
            $r['esi_number'] ?? '', $r['aadhaar_number'] ?? '',
            $r['monthly_gross'], $r['paid_days'], 0,
            $r['gross_salary'] ?? $r['gross_earnings'],
            $r['esi_employee'], $r['esi_employer']
        ]);
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-hospital me-2"></i>ESI Reports</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'"><i class="bi bi-download me-1"></i>Export CSV</button>
                <button class="btn btn-outline-primary" onclick="window.location.href+='&export=esi_excel'"><i class="bi bi-file-earmark-excel me-1"></i>ESI Online Format</button>
                <button class="btn btn-outline-info" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/esi-reports">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    <div class="col-md-2">
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m==$month?'selected':''; ?>><?php echo date('M',mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y=date('Y');$y>=date('Y')-3;$y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y==$year?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body py-2 px-3"><div class="text-white-50 small">ESI Members</div><div class="h4 mb-0"><?php echo $esiTotals['count']; ?></div></div></div></div>
            <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body py-2 px-3"><div class="text-white-50 small">Total Wages</div><div class="h4 mb-0"><?php echo formatCurrency($esiTotals['gross']); ?></div></div></div></div>
            <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body py-2 px-3"><div class="text-white-50 small">EE Contribution (0.75%)</div><div class="h4 mb-0"><?php echo formatCurrency($esiTotals['ee_esi']); ?></div></div></div></div>
            <div class="col-md-3"><div class="card bg-warning text-dark"><div class="card-body py-2 px-3"><div class="text-black-50 small">ER Contribution (3.25%)</div><div class="h4 mb-0"><?php echo formatCurrency($esiTotals['er_esi']); ?></div></div></div></div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?php echo $tab==='register'?'active':''; ?>" href="?page=report/esi-reports&tab=register&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Form 7 (Register)</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='summary'?'active':''; ?>" href="?page=report/esi-reports&tab=summary&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Summary</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='challan'?'active':''; ?>" href="?page=report/esi-reports&tab=challan&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Challan</a></li>
        </ul>

        <?php if ($tab === 'register'): ?>
        <!-- ESI Register (Form 7) -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">ESI Register (Form 7) - <?php echo $monthName . ' ' . $year; ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover" id="esiRegTable" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2">#</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Employee Name</th>
                                <th rowspan="2">Father Name</th>
                                <th rowspan="2">Gender</th>
                                <th rowspan="2">DOJ</th>
                                <th rowspan="2">DOL</th>
                                <th rowspan="2">ESI No.</th>
                                <th rowspan="2">IP No.</th>
                                <th class="text-center" colspan="2">Wages (₹)</th>
                                <th class="text-center" colspan="2">Contribution (₹)</th>
                            </tr>
                            <tr>
                                <th class="text-center" style="background:#198754;">Monthly</th>
                                <th class="text-center" style="background:#198754;">Earned</th>
                                <th class="text-center" style="background:#0d6efd;">EE (0.75%)</th>
                                <th class="text-center" style="background:#dc3545;">ER (3.25%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($esiData as $i => $r): ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td><?php echo sanitize($r['father_name'] ?? '-'); ?></td>
                                <td><?php echo strtoupper(substr($r['gender'] ?? 'M', 0, 1)); ?></td>
                                <td><?php echo formatDate($r['date_of_joining']); ?></td>
                                <td><?php echo !empty($r['date_of_leaving']) ? formatDate($r['date_of_leaving']) : '-'; ?></td>
                                <td><small><?php echo sanitize($r['esi_number'] ?? '-'); ?></small></td>
                                <td><small><?php echo sanitize($r['uan_number'] ?? '-'); ?></small></td>
                                <td class="text-end"><?php echo number_format(floatval($r['monthly_gross']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['gross_salary'] ?? $r['gross_earnings']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['esi_employee']),2); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['esi_employer']),2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="9"><strong>TOTAL (<?php echo count($esiData); ?> members)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($esiTotals['gross'],0); ?></strong></td>
                                <td></td>
                                <td class="text-end"><strong><?php echo number_format($esiTotals['ee_esi'],2); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($esiTotals['er_esi'],2); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'summary'): ?>
        <!-- Summary with Company-wise breakdown -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Employee-wise ESI Summary</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered" id="esiSumTable" style="font-size:0.8rem;">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Code</th><th>Name</th><th>Unit</th><th>ESI No.</th><th class="text-end">Gross</th><th class="text-end">EE (0.75%)</th><th class="text-end">ER (3.25%)</th><th class="text-end">Total</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($esiData as $i => $r): $tot = floatval($r['esi_employee']) + floatval($r['esi_employer']); ?>
                                    <tr>
                                        <td><?php echo $i+1; ?></td>
                                        <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                        <td><?php echo sanitize($r['full_name']); ?></td>
                                        <td class="text-muted"><?php echo sanitize($r['unit_name']); ?></td>
                                        <td><small><?php echo sanitize($r['esi_number'] ?? '-'); ?></small></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['gross_salary'] ?? $r['gross_earnings']),0); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['esi_employee']),2); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['esi_employer']),2); ?></td>
                                        <td class="text-end"><strong><?php echo number_format($tot,2); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td colspan="5"><strong>TOTAL</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($esiTotals['gross'],0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($esiTotals['ee_esi'],2); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($esiTotals['er_esi'],2); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($esiTotals['total'],2); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Company-wise Summary</h6></div>
                    <div class="card-body">
                        <?php foreach ($companyWise as $cn => $cd): $ct = $cd['ee'] + $cd['er']; ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="d-flex justify-content-between"><strong><?php echo sanitize($cn); ?></strong><span class="badge bg-success"><?php echo $cd['count']; ?> members</span></div>
                            <div class="row small mt-1">
                                <div class="col-6 text-muted">Gross Wages:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['gross']); ?></div>
                                <div class="col-6 text-muted">EE Share:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['ee']); ?></div>
                                <div class="col-6 text-muted">ER Share:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['er']); ?></div>
                                <div class="col-6 text-muted fw-bold">Total:</div><div class="col-6 text-end fw-bold"><?php echo formatCurrency($ct); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'challan'): ?>
        <!-- ESI Challan -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">ESI Challan - <?php echo $monthName . ' ' . $year; ?></h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" style="font-size:0.9rem;">
                        <tbody>
                            <tr><td class="fw-bold" width="60%">Total ESI Members</td><td class="text-center h5"><?php echo $esiTotals['count']; ?></td></tr>
                            <tr><td class="fw-bold">Total Wages on which contributions are payable</td><td class="text-end h5"><?php echo formatCurrency($esiTotals['gross']); ?></td></tr>
                            <tr class="table-light"><td class="fw-bold">Contribution Details</td><td></td></tr>
                            <tr><td class="ps-4">Employee's Share (0.75%)</td><td class="text-end"><?php echo formatCurrency($esiTotals['ee_esi']); ?></td></tr>
                            <tr><td class="ps-4">Employer's Share (3.25%)</td><td class="text-end"><?php echo formatCurrency($esiTotals['er_esi']); ?></td></tr>
                            <tr class="table-dark"><td class="fw-bold">TOTAL CONTRIBUTION</td><td class="text-end h4"><?php echo formatCurrency($esiTotals['total']); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#esiRegTable, #esiSumTable').DataTable({ responsive: true, pageLength: 50, ordering: false });
});
@media print {
    .btn, form, .nav-tabs, .nav { display: none !important; }
    .table { font-size: 7pt; }
}
</script>
