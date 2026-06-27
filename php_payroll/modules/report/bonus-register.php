<?php
/**
 * RCS HRMS Pro - Bonus Register / Form C / Tarvani Patrak
 * Bonus calculation as per Payment of Bonus Act, 1965
 */

$pageTitle = 'Bonus Register';

$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$reportFormat = sanitize($_GET['format'] ?? 'register');

$formatOptions = [
    'register' => 'Bonus Register',
    'form_c' => 'Form C (Statutory)',
    'tarvani' => 'Tarvani Patrak (Gujarati Format)'
];

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

// Build query for bonus-eligible employees
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

// Get employees with their latest salary structure and bonus status
$sql = "SELECT 
            e.id, e.employee_code, e.full_name, e.father_name, e.designation, e.date_of_joining,
            e.date_of_leaving, e.gender,
            u.name as unit_name, c.name as client_name,
            ess.basic_da, ess.gross_salary, ess.pf_applicable, ess.esi_applicable,
            p.payment_mode,
            p.gross_salary as last_gross, p.basic_da as last_basic_da, p.bonus_encashment as last_bonus_paid
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
        LEFT JOIN units u ON e.unit_id = u.id
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN payroll p ON e.employee_code = p.employee_id
            AND p.payroll_period_id = (SELECT id FROM payroll_periods WHERE year = :year ORDER BY month DESC LIMIT 1)
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

// Process bonus calculation
// As per Payment of Bonus Act: Min bonus = 8.33% of min(Basic+DA, Rs.7000) or min wages (whichever is higher)
$bonusData = [];
$bonusTotals = [
    'basic_da' => 0, 'min_wages' => 0, 'bonus_applicable' => 0, 
    'bonus_paid' => 0, 'total' => 0, 'count' => 0
];

foreach ($employees as $emp) {
    $basicDa = floatval($emp['basic_da'] ?? $emp['last_basic_da'] ?? 0);
    
    // Get minimum wages from salary structure or use 7000 as statutory minimum
    try {
        $minWage = $db->fetchColumn(
            "SELECT MIN(amount) FROM minimum_wages WHERE year = ? AND state = ? LIMIT 1",
            [$year, $emp['state'] ?? 'Maharashtra']
        );
        $minWage = $minWage ? floatval($minWage) : 7000;
    } catch (Exception $e) {
        $minWage = 7000;
    }
    
    // Bonus calculation: 8.33% of min(Basic+DA, Rs.7000, min wages)
    $bonusBase = min($basicDa, 7000, $minWage);
    $bonusApplicable = round($bonusBase * 0.0833);
    $bonusPaid = floatval($emp['last_bonus_paid'] ?? 0);
    $total = $bonusApplicable; // Bonus amount payable
    
    // Filter: only show employees who worked for at least 30 days in the year
    $doj = $emp['date_of_joining'] ? new DateTime($emp['date_of_joining']) : null;
    $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : new DateTime($year . '-12-31');
    
    if ($doj) {
        $yearStart = new DateTime($year . '-01-01');
        $effectiveDoj = max($doj, $yearStart);
        if ($effectiveDoj > $dol) continue; // Joined after leaving or after year end
        $interval = $effectiveDoj->diff($dol);
        $daysWorked = $interval->days;
        if ($daysWorked < 30) continue;
        
        // Prorate bonus for employees who worked less than full year
        $fullYearDays = 365;
        $prorationFactor = min(1, $daysWorked / $fullYearDays);
        $bonusApplicable = round($bonusApplicable * $prorationFactor);
        $total = $bonusApplicable;
    }
    
    $bonusData[] = [
        'employee' => $emp,
        'basic_da' => $basicDa,
        'min_wages' => $minWage,
        'bonus_applicable' => $bonusApplicable,
        'bonus_paid' => $bonusPaid,
        'total' => $total,
        'payment_mode' => $emp['payment_mode'] ?? '-',
        'days_worked' => $daysWorked ?? 0
    ];
    
    $bonusTotals['basic_da'] += $basicDa;
    $bonusTotals['min_wages'] += $minWage;
    $bonusTotals['bonus_applicable'] += $bonusApplicable;
    $bonusTotals['bonus_paid'] += $bonusPaid;
    $bonusTotals['total'] += $total;
    $bonusTotals['count']++;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'bonus_register_' . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Bonus Register - ' . $year . ' (' . $formatOptions[$reportFormat] . ')']);
    
    if ($reportFormat === 'tarvani') {
        fputcsv($output, ['#','Emp Code','Name','Father Name','Designation','DOJ','Basic+DA','Min Wages','Bonus (8.33%)','Payment Mode']);
    } else {
        fputcsv($output, ['#','Emp Code','Name','Designation','DOJ','Basic+DA','Min Wages','Bonus Applicable','Bonus Paid','Total','Payment Mode']);
    }
    
    foreach ($bonusData as $i => $bd) {
        $emp = $bd['employee'];
        if ($reportFormat === 'tarvani') {
            fputcsv($output, [$i+1, $emp['employee_code'], $emp['full_name'], $emp['father_name'],
                $emp['designation'], $emp['date_of_joining'], $bd['basic_da'], $bd['min_wages'],
                $bd['bonus_applicable'], $bd['payment_mode']]);
        } else {
            fputcsv($output, [$i+1, $emp['employee_code'], $emp['full_name'], $emp['designation'],
                $emp['date_of_joining'], $bd['basic_da'], $bd['min_wages'],
                $bd['bonus_applicable'], $bd['bonus_paid'], $bd['total'], $bd['payment_mode']]);
        }
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-gift me-2"></i>Bonus Register</h4>
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
                    <input type="hidden" name="page" value="report/bonus-register">
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Report Format</label>
                        <select name="format" class="form-select form-select-sm">
                            <?php foreach ($formatOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $reportFormat===$val?'selected':''; ?>><?php echo $label; ?></option>
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

        <!-- Info Banner -->
        <div class="alert alert-info py-2 mb-3">
            <small><i class="bi bi-info-circle me-1"></i>Bonus calculated as per <strong>Payment of Bonus Act, 1965</strong> — 
            Minimum 8.33% of min(Basic+DA, ₹7,000 or Minimum Wages). Prorated for employees with less than 365 working days.</small>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Eligible Employees</small>
                        <div class="h5 mb-0"><?php echo $bonusTotals['count']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Bonus Payable</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($bonusTotals['bonus_applicable']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Basic+DA</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($bonusTotals['basic_da']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1"><?php echo strtoupper($formatOptions[$reportFormat]); ?></h5>
            <small class="text-muted">Year: <?php echo $year; ?></small>
        </div>

        <!-- Data Table -->
        <?php if (empty($bonusData)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No bonus-eligible employees found for selected criteria.
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <?php if ($reportFormat === 'tarvani'): ?>
                    <!-- Tarvani Patrak Format -->
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:30px;">#</th>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Father's Name</th>
                                <th>Designation</th>
                                <th>DOJ</th>
                                <th class="text-end">Basic+DA</th>
                                <th class="text-end">Min Wages</th>
                                <th class="text-end">Bonus (8.33%)</th>
                                <th>Payment Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bonusData as $i => $bd):
                                $emp = $bd['employee'];
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td><?php echo sanitize($emp['father_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td><?php echo $emp['date_of_joining'] ? formatDate($emp['date_of_joining']) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format($bd['basic_da'],0); ?></td>
                                <td class="text-end"><?php echo number_format($bd['min_wages'],0); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo number_format($bd['bonus_applicable'],0); ?></td>
                                <td><?php echo sanitize($bd['payment_mode']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="6"><strong>TOTAL (<?php echo $bonusTotals['count']; ?> Employees)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['min_wages'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['bonus_applicable'],0); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php elseif ($reportFormat === 'form_c'): ?>
                    <!-- Form C Format (Statutory) -->
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:30px;">#</th>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>DOJ</th>
                                <th>DOL</th>
                                <th class="text-end">Basic+DA</th>
                                <th class="text-end">Min Wages</th>
                                <th class="text-end">Bonus (8.33%)</th>
                                <th class="text-end">Ex-gratia</th>
                                <th class="text-end">Total</th>
                                <th>Mode</th>
                                <th>Cheque/UTR No.</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bonusData as $i => $bd):
                                $emp = $bd['employee'];
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td><?php echo $emp['date_of_joining'] ? formatDate($emp['date_of_joining']) : '-'; ?></td>
                                <td><?php echo $emp['date_of_leaving'] ? formatDate($emp['date_of_leaving']) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format($bd['basic_da'],0); ?></td>
                                <td class="text-end"><?php echo number_format($bd['min_wages'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($bd['bonus_applicable'],0); ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end fw-bold text-success"><?php echo number_format($bd['total'],0); ?></td>
                                <td><?php echo sanitize($bd['payment_mode']); ?></td>
                                <td>-</td>
                                <td>-</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="6"><strong>TOTAL (<?php echo $bonusTotals['count']; ?> Employees)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['min_wages'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['bonus_applicable'],0); ?></strong></td>
                                <td></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['total'],0); ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <!-- Standard Bonus Register -->
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:30px;">#</th>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>DOJ</th>
                                <th class="text-end">Basic+DA</th>
                                <th class="text-end">Min Wages</th>
                                <th class="text-end" style="background:#198754;">Bonus (8.33%)</th>
                                <th class="text-end">Bonus Paid</th>
                                <th class="text-end" style="background:#0d6efd;">Total Payable</th>
                                <th>Payment Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bonusData as $i => $bd):
                                $emp = $bd['employee'];
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td><?php echo $emp['date_of_joining'] ? formatDate($emp['date_of_joining']) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format($bd['basic_da'],0); ?></td>
                                <td class="text-end"><?php echo number_format($bd['min_wages'],0); ?></td>
                                <td class="text-end fw-bold" style="background:#e8f5e9;"><?php echo number_format($bd['bonus_applicable'],0); ?></td>
                                <td class="text-end"><?php echo number_format($bd['bonus_paid'],0); ?></td>
                                <td class="text-end fw-bold" style="background:#bbdefb;"><?php echo number_format($bd['total'],0); ?></td>
                                <td><?php echo sanitize($bd['payment_mode']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="5"><strong>TOTAL (<?php echo $bonusTotals['count']; ?> Employees)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['min_wages'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['bonus_applicable'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['bonus_paid'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($bonusTotals['total'],0); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php endif; ?>
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
}
</style>
