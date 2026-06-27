<?php
/**
 * Form XXII - Register of Overtime (CLRA)
 * Contract Labour (Regulation & Abolition) Act, 1970
 * OT register as per CLRA Act
 */

$pageTitle = 'Form XXII - Register of Overtime (CLRA)';

// Filters
$filterMonth = intval($_GET['month'] ?? date('m'));
$filterYear = intval($_GET['year'] ?? date('Y'));
$filterClient = intval($_GET['client_id'] ?? 0);
$filterUnit = intval($_GET['unit_id'] ?? 0);

$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// Fetch dropdowns
try {
    $clients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $clients = [];
}

$units = [];
if ($filterClient > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = :cid ORDER BY name");
        $stmt->execute([':cid' => $filterClient]);
        $units = $stmt->fetchAll();
    } catch (Exception $e) {
        $units = [];
    }
}

// Fetch OT data from attendance and payroll
$otData = [];
$monthTotals = [
    'total_hours' => 0, 'total_amount' => 0, 'workers_count' => 0
];

try {
    $where = "at.month = :month AND at.year = :year AND at.overtime_hours > 0";
    $params = [':month' => $filterMonth, ':year' => $filterYear];

    if ($filterClient) {
        $where .= " AND e.client_id = :cid";
        $params[':cid'] = $filterClient;
    }
    if ($filterUnit) {
        $where .= " AND e.unit_id = :uid";
        $params[':uid'] = $filterUnit;
    }

    $sql = "
        SELECT
            e.employee_code, e.full_name, e.designation, e.unit_id,
            at.overtime_hours,
            p.overtime_amount,
            ess.basic_da,
            u.name as unit_name, c.name as client_name
        FROM attendance_summary at
        JOIN employees e ON e.id = at.employee_id
        LEFT JOIN payroll p ON p.employee_id = e.id
            AND p.payroll_period_id IN (SELECT id FROM payroll_periods WHERE month = :month AND year = :year)
        LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id AND ess.effective_to IS NULL
        LEFT JOIN units u ON u.id = e.unit_id
        LEFT JOIN clients c ON c.id = e.client_id
        WHERE $where
        ORDER BY u.name, e.employee_code
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $otData = $stmt->fetchAll();

    $monthTotals['workers_count'] = count($otData);
    foreach ($otData as $row) {
        $hours = floatval($row['overtime_hours']);
        $basicDa = floatval($row['basic_da']);
        $ratePerHour = $basicDa > 0 ? round(($basicDa / 26 / 8) * 2, 2) : 0; // Double rate
        $otAmount = $hours * $ratePerHour;
        $monthTotals['total_hours'] += $hours;
        $monthTotals['total_amount'] += $otAmount;
        $row['_rate_per_hour'] = $ratePerHour;
        $row['_ot_amount'] = $otAmount;
    }
} catch (Exception $e) {
    $otData = [];
}

// Group by unit
$groupedOT = [];
foreach ($otData as $row) {
    $key = $row['unit_name'] ?? 'Unknown';
    if (!isset($groupedOT[$key])) $groupedOT[$key] = [];
    $groupedOT[$key][] = $row;
}

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Form_XXII_OT_Register_' . $filterYear . '_' . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Form XXII - Register of Overtime', $monthNames[$filterMonth] . ' ' . $filterYear]);
    fputcsv($output, ['Sl No', 'Emp Code', 'Name', 'Designation', 'Unit', 'OT Hours', 'Rate/Hour', 'OT Amount', 'Authorized By']);

    $slNo = 0;
    foreach ($groupedOT as $unitName => $rows) {
        foreach ($rows as $row) {
            $slNo++;
            fputcsv($output, [
                $slNo, $row['employee_code'], $row['full_name'], $row['designation'],
                $unitName, number_format($row['overtime_hours'], 1),
                $row['_rate_per_hour'] ?? 0, round($row['_ot_amount'] ?? 0, 2),
                'Authorized'
            ]);
        }
    }
    fputcsv($output, ['', '', 'TOTAL', '', '', number_format($monthTotals['total_hours'], 1), '', round($monthTotals['total_amount'], 2), '']);

    fclose($output);
    exit;
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .table { font-size: 9px; }
    .container { max-width: 100%; padding: 0; }
}
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div>
            <h4 class="mb-1"><i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 - Rule 78 | <?= $monthNames[$filterMonth] ?> <?= $filterYear ?></small>
        </div>
        <div class="d-flex gap-2">
            <a href="?page=forms/labour/form-xxii&export=1&month=<?= $filterMonth ?>&year=<?= $filterYear ?>&client_id=<?= $filterClient ?>&unit_id=<?= $filterUnit ?>"
                class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="forms/labour/form-xxii">
        <div class="col-md-2">
            <label class="form-label fw-semibold small">Month</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small">Year</label>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small">Client</label>
            <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterClient === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small">Unit</label>
            <select name="unit_id" class="form-select form-select-sm">
                <option value="">All Units</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUnit === $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <!-- Monthly Summary -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card card-body bg-primary bg-opacity-10 border-primary p-2 text-center">
                <div class="fs-5 fw-bold text-primary"><?= $monthTotals['workers_count'] ?></div>
                <small class="text-muted">Workers with OT</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body bg-warning bg-opacity-10 border-warning p-2 text-center">
                <div class="fs-5 fw-bold text-warning"><?= number_format($monthTotals['total_hours'], 1) ?></div>
                <small class="text-muted">Total OT Hours</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body bg-success bg-opacity-10 border-success p-2 text-center">
                <div class="fs-5 fw-bold text-success"><?= formatCurrency($monthTotals['total_amount']) ?></div>
                <small class="text-muted">Total OT Amount</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body bg-info bg-opacity-10 border-info p-2 text-center">
                <div class="fs-5 fw-bold text-info"><?= count($groupedOT) ?></div>
                <small class="text-muted">Units</small>
            </div>
        </div>
    </div>

    <!-- OT Register -->
    <?php $slNo = 0; ?>
    <?php foreach ($groupedOT as $unitName => $rows): ?>
        <h6 class="mb-2 mt-3"><i class="bi bi-building me-1"></i><?= htmlspecialchars($unitName) ?>
            <span class="badge bg-secondary ms-1"><?= count($rows) ?> workers</span>
        </h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th class="text-center" style="width:45px">Sl No</th>
                        <th>Emp Code</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th class="text-center">Total OT Hours</th>
                        <th class="text-end">Rate per Hour (Rs.)</th>
                        <th class="text-end">OT Amount (Rs.)</th>
                        <th class="text-center">Authorized By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row):
                        $slNo++;
                        $basicDa = floatval($row['basic_da']);
                        $ratePerHour = $basicDa > 0 ? round(($basicDa / 26 / 8) * 2, 2) : 0;
                        $otAmount = floatval($row['overtime_hours']) * $ratePerHour;
                    ?>
                        <tr>
                            <td class="text-center"><?= $slNo ?></td>
                            <td><?= $row['employee_code'] ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['designation']) ?></td>
                            <td class="text-center"><?= number_format($row['overtime_hours'], 1) ?></td>
                            <td class="text-end"><?= formatCurrency($ratePerHour) ?></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($otAmount) ?></td>
                            <td class="text-center">
                                <span class="badge bg-success">Authorized</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <?php
                        $grpHours = array_sum(array_column($rows, 'overtime_hours'));
                        $grpAmount = 0;
                        foreach ($rows as $r) {
                            $bd = floatval($r['basic_da']);
                            $rph = $bd > 0 ? round(($bd / 26 / 8) * 2, 2) : 0;
                            $grpAmount += floatval($r['overtime_hours']) * $rph;
                        }
                    ?>
                    <tr>
                        <td colspan="4" class="text-end">Unit Sub-Total:</td>
                        <td class="text-center"><?= number_format($grpHours, 1) ?></td>
                        <td></td>
                        <td class="text-end"><?= formatCurrency($grpAmount) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endforeach; ?>

    <?php if (empty($groupedOT)): ?>
        <div class="alert alert-info text-center">
            <i class="bi bi-info-circle me-2"></i>No overtime records found for <?= $monthNames[$filterMonth] ?> <?= $filterYear ?>.
        </div>
    <?php endif; ?>

    <!-- Grand Total -->
    <?php if (!empty($groupedOT)): ?>
        <div class="alert alert-dark d-flex justify-content-between align-items-center">
            <span><strong>Grand Total:</strong> <?= $monthTotals['workers_count'] ?> Workers</span>
            <span><?= number_format($monthTotals['total_hours'], 1) ?> Hours</span>
            <span class="fw-bold">Rs. <?= formatCurrency($monthTotals['total_amount']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="d-flex justify-content-between mt-4">
        <div style="text-align:center; width:200px"><hr><small>Prepared By</small></div>
        <div style="text-align:center; width:200px"><hr><small>Checked By</small></div>
        <div style="text-align:center; width:200px"><hr><small>Authorized Signatory</small></div>
    </div>
</div>
