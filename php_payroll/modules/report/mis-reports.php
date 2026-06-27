<?php
/**
 * RCS HRMS Pro - MIS Reports Hub
 * CTC, Birthday, Leave Balance, Join/Left, Increment, Salary Certificate, Form 16
 */

$pageTitle = 'MIS Reports';

$tab = sanitize($_GET['tab'] ?? 'ctc');
$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$search = sanitize($_GET['search'] ?? '');

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$monthName = date('F', mktime(0,0,0,$month,1,$year));

// ========== TAB: CTC Report ==========
$ctcData = [];
if ($tab === 'ctc') {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($search) { $where .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)"; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }

    $ctcData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation, e.date_of_joining,
                e.worker_category, c.name as client_name, u.name as unit_name,
                ess.gross_salary, ess.pf_applicable, ess.esi_applicable, ess.gratuity_applicable, ess.bonus_applicable,
                ess.basic_da, ess.hra, ess.washing_allowance, ess.leave_encashment, ess.bonus_encashment
         FROM employees e
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $where
         ORDER BY c.name, e.employee_code",
        $params
    );
    // Calculate CTC breakdown for each
    foreach ($ctcData as &$row) {
        $gross = floatval($row['gross_salary'] ?? 0);
        $basic = floatval($row['basic_da'] ?? 0);
        $row['calculated_gross'] = $gross;
        $row['pf_ee'] = $row['pf_applicable'] ? round(min($basic, 15000) * 12 / 100, 2) : 0;
        $row['pf_er'] = $row['pf_applicable'] ? round(min($basic, 15000) * 12.67 / 100, 2) : 0;
        $row['esi_ee'] = ($row['esi_applicable'] && $gross <= 21000) ? round($gross * 0.75 / 100, 2) : 0;
        $row['esi_er'] = ($row['esi_applicable'] && $gross <= 21000) ? round($gross * 3.25 / 100, 2) : 0;
        $row['gratuity'] = $row['gratuity_applicable'] ? round($basic * 4.81 / 100, 2) : 0;
        $row['bonus_prov'] = $row['bonus_applicable'] ? round(min($basic, 7000) * 8.33 / 100, 2) : 0;
        $row['total_ctc'] = $gross + $row['pf_er'] + $row['esi_er'] + $row['gratuity'] + $row['bonus_prov'];
    }
}

// ========== TAB: Birthday Report ==========
$birthdayData = [];
if ($tab === 'birthday') {
    $birthdayData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.date_of_birth, e.mobile_number,
                e.designation, c.name as client_name, u.name as unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.status = 'approved' AND e.date_of_birth IS NOT NULL AND e.date_of_birth != '0000-00-00'
         ORDER BY MONTH(e.date_of_birth), DAY(e.date_of_birth)"
    );
}

// ========== TAB: Leave Balance Report ==========
$leaveBalData = [];
if ($tab === 'leave_balance') {
    $leaveBalData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, c.name as client_name, u.name as unit_name,
                lb.leave_type, lb.opening_balance, lb.accrued, lb.used, lb.closing_balance, lb.year
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN leave_balances lb ON lb.employee_id = e.id AND lb.year = ?
         WHERE e.status = 'approved'
         ORDER BY e.employee_code, FIELD(lb.leave_type, 'CL','PL','SL','EL','CO','ML')",
        [$year]
    );
}

// ========== TAB: Join/Left Report ==========
$joinLeftData = [];
if ($tab === 'join_left') {
    $where = "YEAR(e.date_of_joining) = :year OR YEAR(e.date_of_leaving) = :year2";
    $params = [':year' => $year, ':year2' => $year];
    if ($clientFilter) { $where .= " AND e.client_id = :cid"; $params[':cid'] = $clientFilter; }

    $joinLeftData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation,
                e.date_of_joining, e.date_of_leaving, e.status,
                c.name as client_name, u.name as unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $where
         ORDER BY e.date_of_joining DESC",
        $params
    );
}

// Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mis_report_' . $tab . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['MIS Report - ' . str_replace('_',' ',ucfirst($tab)) . ' - ' . $year]);
    
    if ($tab === 'ctc' && !empty($ctcData)) {
        fputcsv($output, ['Code','Name','Designation','Client','Gross','PF(EE)','PF(ER)','ESI(EE)','ESI(ER)','Gratuity','Bonus','CTC']);
        foreach ($ctcData as $r) fputcsv($output, [$r['employee_code'],$r['full_name'],$r['designation'],$r['client_name'],
            $r['calculated_gross'],$r['pf_ee'],$r['pf_er'],$r['esi_ee'],$r['esi_er'],$r['gratuity'],$r['bonus_prov'],$r['total_ctc']]);
    } elseif ($tab === 'birthday' && !empty($birthdayData)) {
        fputcsv($output, ['Code','Name','DOB','Mobile','Designation','Client']);
        foreach ($birthdayData as $r) fputcsv($output, [$r['employee_code'],$r['full_name'],$r['date_of_birth'],$r['mobile_number'],$r['designation'],$r['client_name']]);
    } elseif ($tab === 'join_left' && !empty($joinLeftData)) {
        fputcsv($output, ['Code','Name','Designation','DOJ','DOL','Status','Client','Unit']);
        foreach ($joinLeftData as $r) fputcsv($output, [$r['employee_code'],$r['full_name'],$r['designation'],$r['date_of_joining'],$r['date_of_leaving'],$r['status'],$r['client_name'],$r['unit_name']]);
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>MIS Reports</h4>
            <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'"><i class="bi bi-download me-1"></i>Export CSV</button>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
            <li class="nav-item"><a class="nav-link <?php echo $tab==='ctc'?'active':''; ?>" href="?page=report/mis-reports&tab=ctc">CTC Report</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='birthday'?'active':''; ?>" href="?page=report/mis-reports&tab=birthday">Birthday</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='leave_balance'?'active':''; ?>" href="?page=report/mis-reports&tab=leave_balance">Leave Balance</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='join_left'?'active':''; ?>" href="?page=report/mis-reports&tab=join_left">Join/Left</a></li>
        </ul>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/mis-reports">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
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
                    <?php if ($tab === 'ctc'): ?>
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name/code" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <?php endif; ?>
                    <div class="col-md-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button></div>
                </form>
            </div>
        </div>

        <?php if ($tab === 'ctc'): ?>
        <!-- CTC Report -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">CTC Report - Year <?php echo $year; ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover" id="ctcTable" style="font-size:0.78rem;">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th><th>Code</th><th>Name</th><th>Designation</th><th>Client</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF(EE)</th><th class="text-end">PF(ER)</th>
                                <th class="text-end">ESI(EE)</th><th class="text-end">ESI(ER)</th>
                                <th class="text-end">Gratuity</th><th class="text-end">Bonus</th>
                                <th class="text-end" style="background:#0d6efd;"><strong>CTC</strong></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $grandCTC = 0; foreach ($ctcData as $i => $r): $grandCTC += $r['total_ctc']; ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($r['designation']); ?></td>
                                <td><?php echo sanitize($r['client_name']); ?></td>
                                <td class="text-end"><?php echo number_format($r['calculated_gross'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['pf_ee'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['pf_er'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['esi_ee'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['esi_er'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['gratuity'],0); ?></td>
                                <td class="text-end"><?php echo number_format($r['bonus_prov'],0); ?></td>
                                <td class="text-end"><strong><?php echo number_format($r['total_ctc'],0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($ctcData)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="11"><strong>TOTAL CTC (<?php echo count($ctcData); ?> employees)</strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandCTC); ?></strong></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'birthday'): ?>
        <!-- Birthday Report -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Employee Birthday Report</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="birthdayTable">
                        <thead class="table-light">
                            <tr><th>#</th><th>Code</th><th>Name</th><th>Date of Birth</th><th>Day</th><th>Mobile</th><th>Designation</th><th>Client</th><th>Unit</th></tr>
                        </thead>
                        <tbody>
                            <?php $currentMonth = prev_month_num(); foreach ($birthdayData as $i => $r):
                                $dobMonth = (int)date('n', strtotime($r['date_of_birth']));
                                $isThisMonth = ($dobMonth === $currentMonth);
                            ?>
                            <tr class="<?php echo $isThisMonth ? 'table-success' : ''; ?>">
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><strong><?php echo sanitize($r['full_name']); ?></strong></td>
                                <td><?php echo date('d-M-Y', strtotime($r['date_of_birth'])); ?></td>
                                <td><?php echo date('l', strtotime($r['date_of_birth'])); ?></td>
                                <td><?php echo sanitize($r['mobile_number'] ?? '-'); ?></td>
                                <td><?php echo sanitize($r['designation']); ?></td>
                                <td><?php echo sanitize($r['client_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($r['unit_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'leave_balance'): ?>
        <!-- Leave Balance Report -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Leave Balance Report - <?php echo $year; ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="leaveBalTable" style="font-size:0.8rem;">
                        <thead class="table-light">
                            <tr><th>Code</th><th>Name</th><th>Client</th><th>Leave Type</th><th class="text-end">Opening</th><th class="text-end">Accrued</th><th class="text-end">Used</th><th class="text-end">Balance</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaveBalData as $r): ?>
                            <tr>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td><?php echo sanitize($r['client_name']); ?></td>
                                <td><span class="badge bg-info"><?php echo $r['leave_type']; ?></span></td>
                                <td class="text-end"><?php echo $r['opening_balance']; ?></td>
                                <td class="text-end"><?php echo $r['accrued']; ?></td>
                                <td class="text-end text-danger"><?php echo $r['used']; ?></td>
                                <td class="text-end"><strong class="<?php echo floatval($r['closing_balance']) > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $r['closing_balance']; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'join_left'): ?>
        <!-- Join/Left Report -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Employee Join/Left Report - <?php echo $year; ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="joinLeftTable">
                        <thead class="table-light">
                            <tr><th>#</th><th>Code</th><th>Name</th><th>Designation</th><th>DOJ</th><th>DOL</th><th>Status</th><th>Client</th><th>Unit</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($joinLeftData as $i => $r): ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td><?php echo sanitize($r['designation']); ?></td>
                                <td><?php echo $r['date_of_joining'] ? formatDate($r['date_of_joining']) : '-'; ?></td>
                                <td><?php echo $r['date_of_leaving'] ? formatDate($r['date_of_leaving']) : '-'; ?></td>
                                <td><span class="badge bg-<?php echo in_array($r['status'],['approved','active']) ? 'success' : 'secondary'; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                <td><?php echo sanitize($r['client_name']); ?></td>
                                <td><?php echo sanitize($r['unit_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
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
    $('#ctcTable, #birthdayTable, #leaveBalTable, #joinLeftTable').DataTable({ responsive: true, pageLength: 50 });
});
</script>
