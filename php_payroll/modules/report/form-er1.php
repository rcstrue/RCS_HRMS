<?php
/**
 * RCS HRMS Pro - Form ER-1
 * Annual Return under Contract Labour (Regulation & Abolition) Act, 1970
 * Comprehensive yearly summary with client-wise breakdown
 */

$pageTitle = 'Form ER-1';

$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

// Build query for annual payroll data
$where = "pp.year = :year";
$params = [':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}

// Client-wise summary query
$sql = "SELECT 
            c.id as client_id, c.name as client_name,
            COUNT(DISTINCT p.employee_id) as total_employees,
            COUNT(DISTINCT CASE WHEN e.gender = 'Male' THEN p.employee_id END) as male_count,
            COUNT(DISTINCT CASE WHEN e.gender = 'Female' THEN p.employee_id END) as female_count,
            COUNT(DISTINCT CASE WHEN e.gender NOT IN ('Male','Female') OR e.gender IS NULL THEN p.employee_id END) as other_count,
            SUM(p.gross_salary) as total_wages_paid,
            SUM(p.pf_employee + p.pf_employer) as total_pf_contribution,
            SUM(p.esi_employee + p.esi_employer) as total_esi_contribution,
            SUM(p.bonus_encashment) as total_bonus_paid,
            SUM(p.paid_days) as total_paid_days,
            SUM(p.overtime_hours) as total_overtime_hours,
            SUM(p.overtime_amount) as total_overtime_amount,
            SUM(p.net_pay) as total_net_pay,
            SUM(p.ctc) as total_ctc,
            AVG(p.gross_salary) as avg_wages
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN clients c ON e.client_id = c.id
        WHERE $where
        GROUP BY c.id, c.name
        ORDER BY c.name";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clientData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientData = [];
    $error = $e->getMessage();
}

// Grand totals
$grandTotals = [
    'total_employees' => 0, 'male_count' => 0, 'female_count' => 0, 'other_count' => 0,
    'total_wages_paid' => 0, 'total_pf_contribution' => 0, 'total_esi_contribution' => 0,
    'total_bonus_paid' => 0, 'total_paid_days' => 0, 'total_overtime_hours' => 0,
    'total_overtime_amount' => 0, 'total_net_pay' => 0, 'total_ctc' => 0
];

foreach ($clientData as $row) {
    foreach ($grandTotals as $key => &$val) {
        $val += floatval($row[$key] ?? 0);
    }
}

// Get monthly trend data for the year
$monthlyData = [];
try {
    $mtSql = "SELECT pp.month, 
                     COUNT(DISTINCT p.employee_id) as emp_count,
                     SUM(p.gross_salary) as total_gross,
                     SUM(p.net_pay) as total_net
              FROM payroll p
              JOIN payroll_periods pp ON p.payroll_period_id = pp.id
              JOIN employees e ON p.employee_id = e.employee_code
              WHERE pp.year = :year";
    $mtParams = [':year' => $year];
    
    if ($clientFilter) {
        $mtSql .= " AND e.client_id = :cid";
        $mtParams[':cid'] = $clientFilter;
    }
    
    $mtSql .= " GROUP BY pp.month ORDER BY pp.month";
    
    $mtStmt = $db->prepare($mtSql);
    $mtStmt->execute($mtParams);
    $mtResult = $mtStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mtResult as $mt) {
        $monthlyData[intval($mt['month'])] = $mt;
    }
} catch (Exception $e) {
    // Ignore
}

// Try to get leave with wages data
$leaveWithWages = 0;
try {
    $lwResult = $db->fetchColumn(
        "SELECT SUM(total_days) FROM leave_applications 
         WHERE YEAR(from_date) = :year AND status = 'approved' AND leave_type IN ('PL','EL','CL')",
        [$year]
    );
    $leaveWithWages = $lwResult ? floatval($lwResult) : 0;
} catch (Exception $e) {
    // leave_applications might not exist
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'form_er1_' . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Form ER-1 - Annual Return under Contract Labour Act - Year ' . $year]);
    fputcsv($output, []);
    fputcsv($output, ['A. SUMMARY']);
    fputcsv($output, ['Total Employees', $grandTotals['total_employees']]);
    fputcsv($output, ['Male', $grandTotals['male_count']]);
    fputcsv($output, ['Female', $grandTotals['female_count']]);
    fputcsv($output, ['Total Wages Paid', $grandTotals['total_wages_paid']]);
    fputcsv($output, ['Total PF Contribution', $grandTotals['total_pf_contribution']]);
    fputcsv($output, ['Total ESI Contribution', $grandTotals['total_esi_contribution']]);
    fputcsv($output, ['Total Bonus Paid', $grandTotals['total_bonus_paid']]);
    fputcsv($output, ['Leave with Wages (days)', $leaveWithWages]);
    fputcsv($output, ['Total OT Hours', $grandTotals['total_overtime_hours']]);
    fputcsv($output, ['Total OT Amount', $grandTotals['total_overtime_amount']]);
    fputcsv($output, []);
    fputcsv($output, ['B. CLIENT-WISE BREAKDOWN']);
    fputcsv($output, ['Client','Employees','Male','Female','Total Wages','PF','ESI','Bonus','OT Hours','OT Amount']);
    foreach ($clientData as $row) {
        fputcsv($output, [
            $row['client_name'], $row['total_employees'], $row['male_count'], $row['female_count'],
            $row['total_wages_paid'], $row['total_pf_contribution'], $row['total_esi_contribution'],
            $row['total_bonus_paid'], $row['total_overtime_hours'], $row['total_overtime_amount']
        ]);
    }
    fputcsv($output, ['GRAND TOTAL', $grandTotals['total_employees'], $grandTotals['male_count'], $grandTotals['female_count'],
        $grandTotals['total_wages_paid'], $grandTotals['total_pf_contribution'], $grandTotals['total_esi_contribution'],
        $grandTotals['total_bonus_paid'], $grandTotals['total_overtime_hours'], $grandTotals['total_overtime_amount']]);
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-file-earmark-ruled me-2"></i>Form ER-1</h4>
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
                    <input type="hidden" name="page" value="report/form-er1">
                    <div class="col-md-3">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Generate Annual Return</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">FORM ER-1</h5>
            <small class="text-muted fw-bold">Annual Return under the Contract Labour (Regulation & Abolition) Act, 1970</small>
            <br><small class="text-muted">Year: <?php echo $year; ?></small>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="card bg-primary text-white border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>Employees</small>
                        <div class="h5 mb-0"><?php echo number_format($grandTotals['total_employees'],0); ?></div>
                        <small><i class="bi bi-gender-male me-1"></i><?php echo number_format($grandTotals['male_count'],0); ?> 
                        <i class="bi bi-gender-female ms-2 me-1"></i><?php echo number_format($grandTotals['female_count'],0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-success text-white border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>Total Wages</small>
                        <div class="h5 mb-0"><?php echo formatCurrency($grandTotals['total_wages_paid']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-warning text-dark border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>PF Contribution</small>
                        <div class="h5 mb-0"><?php echo formatCurrency($grandTotals['total_pf_contribution']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-info text-white border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>ESI Contribution</small>
                        <div class="h5 mb-0"><?php echo formatCurrency($grandTotals['total_esi_contribution']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-danger text-white border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>Bonus Paid</small>
                        <div class="h5 mb-0"><?php echo formatCurrency($grandTotals['total_bonus_paid']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-dark text-white border-0">
                    <div class="card-body py-2 px-3 text-center">
                        <small>OT Amount</small>
                        <div class="h5 mb-0"><?php echo formatCurrency($grandTotals['total_overtime_amount']); ?></div>
                        <small><?php echo number_format($grandTotals['total_overtime_hours'],1); ?> hours</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section A: Annual Summary -->
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-1-circle me-1"></i>A. ANNUAL SUMMARY</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <th class="text-end" style="width:50%;">1. Total Number of Workmen Employed</th>
                                <td class="text-end fw-bold"><?php echo number_format($grandTotals['total_employees'],0); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">   (a) Male</th>
                                <td class="text-end"><?php echo number_format($grandTotals['male_count'],0); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">   (b) Female</th>
                                <td class="text-end"><?php echo number_format($grandTotals['female_count'],0); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">2. Total Wages Paid (Gross)</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_wages_paid']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">3. Total Provident Fund Contribution (EE + ER)</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_pf_contribution']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">4. Total ESI Contribution (EE + ER)</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_esi_contribution']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">5. Total Bonus / Ex-gratia Paid</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_bonus_paid']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">6. Leave with Wages (days)</th>
                                <td class="text-end fw-bold"><?php echo number_format($leaveWithWages,0); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">7. Total Overtime Hours</th>
                                <td class="text-end fw-bold"><?php echo number_format($grandTotals['total_overtime_hours'],1); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">8. Total Overtime Amount</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_overtime_amount']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">9. Total Net Pay Disbursed</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_net_pay']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-end">10. Total CTC</th>
                                <td class="text-end fw-bold"><?php echo formatCurrency($grandTotals['total_ctc']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Section B: Monthly Trend -->
        <?php if (!empty($monthlyData)): ?>
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-2-circle me-1"></i>B. MONTHLY TREND - <?php echo $year; ?></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.78rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Employees</th>
                                <th class="text-end">Gross Wages</th>
                                <th class="text-end">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($m = 1; $m <= 12; $m++):
                                $md = $monthlyData[$m] ?? null;
                            ?>
                            <tr>
                                <td><?php echo date('F', mktime(0,0,0,$m,1)); ?></td>
                                <td class="text-end"><?php echo $md ? number_format($md['emp_count'],0) : '-'; ?></td>
                                <td class="text-end"><?php echo $md ? formatCurrency($md['total_gross']) : '-'; ?></td>
                                <td class="text-end"><?php echo $md ? formatCurrency($md['total_net']) : '-'; ?></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section C: Client-wise Breakdown -->
        <?php if (!empty($clientData)): ?>
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-3-circle me-1"></i>C. CLIENT-WISE BREAKDOWN</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2">Client</th>
                                <th colspan="3" class="text-center">Employees</th>
                                <th rowspan="2" class="text-end">Total Wages</th>
                                <th rowspan="2" class="text-end">PF Contrib.</th>
                                <th rowspan="2" class="text-end">ESI Contrib.</th>
                                <th rowspan="2" class="text-end">Bonus Paid</th>
                                <th colspan="2" class="text-center">Overtime</th>
                            </tr>
                            <tr>
                                <th class="text-end">Total</th>
                                <th class="text-end">Male</th>
                                <th class="text-end">Female</th>
                                <th class="text-end">Hours</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientData as $row): ?>
                            <tr>
                                <td><strong><?php echo sanitize($row['client_name'] ?: 'Unassigned'); ?></strong></td>
                                <td class="text-end fw-bold"><?php echo number_format($row['total_employees'],0); ?></td>
                                <td class="text-end"><?php echo number_format($row['male_count'],0); ?></td>
                                <td class="text-end"><?php echo number_format($row['female_count'],0); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_wages_paid']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_pf_contribution']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_esi_contribution']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_bonus_paid']); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_overtime_hours'],1); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_overtime_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td><strong>GRAND TOTAL</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['total_employees'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['male_count'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['female_count'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['total_wages_paid']); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['total_pf_contribution']); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['total_esi_contribution']); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['total_bonus_paid']); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['total_overtime_hours'],1); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['total_overtime_amount']); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section D: Statutory Compliance Summary -->
        <div class="card">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-4-circle me-1"></i>D. STATUTORY COMPLIANCE SUMMARY</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <th style="width:50%;">PF Compliance</th>
                                <td class="text-end fw-bold text-success">
                                    <?php echo $grandTotals['total_pf_contribution'] > 0 
                                        ? '<i class="bi bi-check-circle me-1"></i>Applicable - ' . formatCurrency($grandTotals['total_pf_contribution']) 
                                        : '<span class="text-muted">N/A</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>ESI Compliance</th>
                                <td class="text-end fw-bold text-success">
                                    <?php echo $grandTotals['total_esi_contribution'] > 0 
                                        ? '<i class="bi bi-check-circle me-1"></i>Applicable - ' . formatCurrency($grandTotals['total_esi_contribution']) 
                                        : '<span class="text-muted">N/A</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Bonus Compliance (Payment of Bonus Act)</th>
                                <td class="text-end fw-bold text-success">
                                    <?php echo $grandTotals['total_bonus_paid'] > 0 
                                        ? '<i class="bi bi-check-circle me-1"></i>Compliant - ' . formatCurrency($grandTotals['total_bonus_paid']) 
                                        : '<span class="text-warning"><i class="bi bi-exclamation-circle me-1"></i>Review Required</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Overtime (Factories Act compliance)</th>
                                <td class="text-end fw-bold">
                                    <?php echo $grandTotals['total_overtime_hours'] > 0 
                                        ? formatCurrency($grandTotals['total_overtime_amount']) . ' (' . number_format($grandTotals['total_overtime_hours'],1) . ' hrs)' 
                                        : '<span class="text-muted">No OT recorded</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Leave with Wages</th>
                                <td class="text-end fw-bold">
                                    <?php echo number_format($leaveWithWages,0); ?> days
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A4 landscape; margin: 10mm; }
}
</style>
