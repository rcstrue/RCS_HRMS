<?php
/**
 * RCS HRMS Pro - Payroll Entry / Process Edit Page
 * Version: 1.1.0
 *
 * Excel-like editable grid for direct payroll entry.
 * Route: index.php?page=payroll/process-edit
 *
 * Features:
 * - Sticky left columns (Code, Name, Designation, Status icons)
 * - Editable Attendance, Wage Details, Advances, Office Deductions
 * - Auto-calculated Earnings, Deductions (PF/ESI/PT/LWF), Gross, Net Pay
 * - Live JavaScript recalculation on every input change
 * - Per-row save via AJAX to api/payroll-save-row.php
 * - Double-click modal for detailed employee editing
 * - Yellow highlight on changed cells
 * - Toast notification on load
 */

$pageTitle = 'Payroll Entry';

// ── Session Filters ──────────────────────────────────────────────
$clientId = (int)($_SESSION['filter_client_id'] ?? 0);
$unitId   = (int)($_SESSION['filter_unit_id'] ?? 0);
$month    = (int)($_SESSION['filter_month'] ?? prev_month_num());
$year     = (int)($_SESSION['filter_year'] ?? prev_month_year());

// Override from GET params (when form is submitted)
if (isset($_GET['client_id'])) $clientId = (int)$_GET['client_id'];
if (isset($_GET['unit_id']))   $unitId   = (int)$_GET['unit_id'];
if (isset($_GET['month']))     $month    = (int)$_GET['month'];
if (isset($_GET['year']))      $year     = (int)$_GET['year'];
$payrollPeriodId = (int)($_GET['period_id'] ?? 0);

// Save back to session for persistence
$_SESSION['filter_client_id'] = $clientId;
$_SESSION['filter_unit_id']   = $unitId;
$_SESSION['filter_month']     = $month;
$_SESSION['filter_year']      = $year;

$calendarDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// ── Fetch unit's pay_days_type from salary formula ──────────────
$unitFormula = null;
if ($unitId > 0) {
    $unitFormula = $db->fetch(
        "SELECT * FROM unit_salary_formulas
         WHERE unit_id = ? AND is_active = 1
         ORDER BY effective_from DESC LIMIT 1",
        [$unitId]
    );
}
$payDaysType = $unitFormula['pay_days_type'] ?? 'actual';

// Calculate totalDays based on pay_days_type
$totalDays = $calendarDays;
switch ($payDaysType) {
    case 'fixed_30':
        $totalDays = 30;
        break;
    case 'previous_month':
        $prevMonth = $month - 1;
        $prevYear  = $year;
        if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
        $totalDays = (int)cal_days_in_month(CAL_GREGORIAN, $prevMonth, $prevYear);
        break;
    case 'calendar_minus_sundays':
        // Count all Sundays in the selected month
        $sundays = 0;
        for ($d = 1; $d <= $calendarDays; $d++) {
            $dow = (int)date('N', strtotime("$year-$month-$d"));
            if ($dow === 7) $sundays++;
        }
        $totalDays = $calendarDays - $sundays;
        break;
    default: // 'actual'
        $totalDays = $calendarDays;
        break;
}

// Also get OT settings from unit formula
$unitOtCalcType = $unitFormula['ot_calculation_type'] ?? 'double_pay';
$unitOtCalcOn   = $unitFormula['ot_calculation_on'] ?? 'basic_da';
$unitOtHrsPerDay = (float)($unitFormula['ot_hours_per_day'] ?? 8);

// ── Look up payroll period (for period_id + pay_days) ──────────
$periodPayDays = 0;
if ($payrollPeriodId > 0) {
    $periodInfo = $db->fetch("SELECT pay_days FROM payroll_periods WHERE id = ?", [$payrollPeriodId]);
    if ($periodInfo) $periodPayDays = (int)$periodInfo['pay_days'];
} elseif ($unitId > 0) {
    // Fallback: look up period by month/year
    $periodInfo = $db->fetch(
        "SELECT id, pay_days FROM payroll_periods WHERE month = ? AND year = ? ORDER BY id DESC LIMIT 1",
        [$month, $year]
    );
    if ($periodInfo) {
        $payrollPeriodId = (int)$periodInfo['id'];
        $periodPayDays = (int)$periodInfo['pay_days'];
    }
}
// For 'actual' type, prefer payroll_periods.pay_days over calendar days
if ($payDaysType === 'actual' && $periodPayDays > 0) {
    $totalDays = $periodPayDays;
}

// ── Fetch PF/ESI rates from DB (match process.php class.payroll.php) ──
$pfEmployeeShare = 12.00;
$pfWageCeiling = 15000;
$pfEmployerShare = 3.67;
$pfEmployerEps = 8.33;
$pfEmployerEdlis = 0.50;
$pfEpfAdmin = 0.50;
$esiEmployeeShare = 0.75;
$esiEmployerShare = 3.25;
$esiWageCeiling = 21000;
try {
    $pfRate = $db->fetch("SELECT * FROM pf_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
    if ($pfRate) {
        $pfEmployeeShare = (float)$pfRate['employee_share'];
        $pfWageCeiling  = (float)$pfRate['wage_ceiling'];
        $pfEmployerShare = (float)$pfRate['employer_share_pf'];
        $pfEmployerEps  = (float)$pfRate['employer_share_eps'];
        $pfEmployerEdlis = (float)$pfRate['employer_share_edlis'];
        $pfEpfAdmin     = (float)$pfRate['epf_admin_charges'];
    }
} catch (Exception $e) {}
try {
    $esiRate = $db->fetch("SELECT * FROM esi_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
    if ($esiRate) {
        $esiEmployeeShare = (float)$esiRate['employee_share'];
        $esiEmployerShare = (float)$esiRate['employer_share'];
        $esiWageCeiling   = (float)$esiRate['wage_ceiling'];
    }
} catch (Exception $e) {}

// ── Fetch unit state + PT + LWF configuration ────────────────
$unitState = '';
$unitStatePtApplicable = false;
$ptSlabs = [];
$lwfAmount = 0; // Default 0, will be set per-state below
if ($unitId > 0) {
    $unitInfo = $db->fetch(
        "SELECT u.state, s.pt_applicable as state_pt_applicable, s.id as state_id
         FROM units u
         LEFT JOIN states s ON (u.state = s.state_code OR u.state = s.state_name)
         WHERE u.id = ?",
        [$unitId]
    );
    if ($unitInfo) {
        $unitState = $unitInfo['state'] ?? '';
        $unitStatePtApplicable = !empty($unitInfo['state_pt_applicable']);
    }
    // Fetch PT slabs for this state
    if ($unitStatePtApplicable && $unitState) {
        try {
            $ptSlabs = $db->fetchAll(
                "SELECT min_gross, max_gross, pt_amount
                 FROM professional_tax_slabs
                 WHERE (state_name = ? OR state_name = ?) AND is_active = 1
                 ORDER BY min_gross",
                [$unitState, ucfirst(strtolower($unitState))]
            );
        } catch (Exception $e) {}
    }
    // Fetch LWF rate for this state (from lwf_rates → lwf_state_rates fallback)
    if ($unitState) {
        try {
            $lwfRate = $db->fetch(
                "SELECT lr.employee_share, lr.contribution_months
                 FROM lwf_rates lr
                 JOIN states s ON lr.state_id = s.id
                 WHERE (s.state_code = ? OR s.state_name = ?)
                 AND lr.is_active = 1 AND lr.effective_from <= CURDATE()
                 ORDER BY lr.effective_from DESC LIMIT 1",
                [$unitState, $unitState]
            );
            if (!$lwfRate) {
                $lwfRate = $db->fetch(
                    "SELECT employee_share, contribution_months
                     FROM lwf_state_rates
                     WHERE (state_name = ? OR state_name = ?) AND is_active = 1
                     AND effective_from <= CURDATE()
                     ORDER BY effective_from DESC LIMIT 1",
                    [$unitState, ucfirst(strtolower($unitState))]
                );
            }
            if ($lwfRate) {
                $contribMonths = array_filter(array_map('intval', explode(',', $lwfRate['contribution_months'] ?? '')));
                if (in_array($month, $contribMonths)) {
                    $lwfAmount = floatval($lwfRate['employee_share']);
                }
            }
        } catch (Exception $e) {}
    }
}

// ── Dropdowns ────────────────────────────────────────────────────
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");

// Units filtered by selected client (same pattern as attendance/add)
$units = [];
if ($clientId > 0) {
    $units = $db->fetchAll(
        "SELECT id, name, unit_code FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name",
        [$clientId]
    );
}

// ── Handle Load Employees via POST (AJAX or form) ───────────────
$employeeData = [];
$loaded = false;

if (isset($_GET['load']) && (int)$_GET['load'] === 1 && $unitId > 0) {
    $loaded = true;
    $employees = $db->fetchAll(
        "SELECT id, employee_code, full_name, designation
         FROM employees
         WHERE unit_id = ? AND status = 'approved'
         ORDER BY employee_code",
        [$unitId]
    );

    $effDateStr = date('Y-m-d'); // Use CURDATE() to match process.php behavior

    foreach ($employees as $emp) {
        $empId  = (int)$emp['id'];
        $empCode = $emp['employee_code'];

        // Salary structure (latest effective for selected month)
        $salary = $db->fetch(
            "SELECT * FROM employee_salary_structures
             WHERE employee_id = ? AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 1",
            [$empId, $effDateStr]
        );

        // Attendance
        $att = [];
        try {
            $att = $db->fetch(
                "SELECT * FROM attendance_summary
                 WHERE employee_id = ? AND month = ? AND year = ?",
                [$empId, $month, $year]
            );
        } catch (Exception $e) {
            $att = [];
        }

        // Advances
        $adv = [];
        try {
            $adv = $db->fetch(
                "SELECT COALESCE(adv1,0)+COALESCE(adv2,0)+COALESCE(office_advance,0)+COALESCE(dress_advance,0) as total_advance,
                        COALESCE(adv1,0) as adv1,
                        COALESCE(adv2,0) as adv2,
                        COALESCE(office_advance,0) as office_advance,
                        COALESCE(dress_advance,0) as dress_advance
                 FROM employee_advances
                 WHERE employee_id = ? AND month = ? AND year = ?",
                [$empId, $month, $year]
            );
        } catch (Exception $e) {
            $adv = [];
        }

        // Existing payroll record (if already processed) — prefer period_id lookup
        $existing = [];
        try {
            if ($payrollPeriodId > 0) {
                $existing = $db->fetch(
                    "SELECT * FROM payroll
                     WHERE employee_id = ? AND payroll_period_id = ?",
                    [$empCode, $payrollPeriodId]
                );
            }
            if (empty($existing)) {
                // Fallback: join via payroll_periods to find by month/year
                $existing = $db->fetch(
                    "SELECT p.* FROM payroll p
                     JOIN payroll_periods pp ON p.payroll_period_id = pp.id
                     WHERE p.employee_id = ? AND pp.month = ? AND pp.year = ?
                     ORDER BY p.id DESC LIMIT 1",
                    [$empCode, $month, $year]
                );
            }
        } catch (Exception $e) {
            $existing = [];
        }

        // Active loan EMI for this employee (with balance > 0, not already deducted this month)
        $loanEmi = 0;
        try {
            // Sum EMI from ALL active loans (match process.php logic)
            $loans = $db->fetchAll(
                "SELECT el.emi_amount, el.id as loan_id
                 FROM employee_loans el
                 WHERE el.employee_id = ? AND el.status = 'Active'
                 AND el.balance_amount > 0
                 AND (el.start_year < ? OR (el.start_year = ? AND el.start_month <= ?))
                 AND el.id NOT IN (
                     SELECT lem.loan_id FROM loan_emi_log lem
                     WHERE lem.month = ? AND lem.year = ?
                 )",
                [$empId, $year, $year, $month, $month, $year]
            );
            foreach ($loans as $loan) {
                $loanEmi += floatval($loan['emi_amount']);
            }
        } catch (Exception $e) {
            // Fallback: try without emi_log check (in case table has different structure)
            try {
                $loans2 = $db->fetchAll(
                    "SELECT el.emi_amount, el.id as loan_id
                     FROM employee_loans el
                     WHERE el.employee_id = ? AND el.status = 'Active'
                     AND el.balance_amount > 0
                     AND (el.start_year < ? OR (el.start_year = ? AND el.start_month <= ?))",
                    [$empId, $year, $year, $month]
                );
                foreach ($loans2 as $loan2) {
                    $loanEmi += floatval($loan2['emi_amount']);
                }
            } catch (Exception $e2) {
                $loanEmi = 0;
            }
        }

        $employeeData[] = [
            'emp'      => $emp,
            'salary'   => $salary ?: [],
            'att'      => $att ?: [],
            'adv'      => $adv ?: [],
            'existing' => $existing ?: [],
            'loan_emi' => $loanEmi,
        ];
    }
}

// Month name for display
$monthNames = [1=>'January','February','March','April','May','June',
               7=>'July','August','September','October','November','December'];
$monthLabel = $monthNames[$month] ?? '';
?>

<style>
/* ── Grid wrapper ────────────────────────────────────────────── */
#payrollGridWrapper {
    max-height: calc(100vh - 280px);
    overflow: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

#payrollGridWrapper::-webkit-scrollbar { width: 8px; height: 8px; }
#payrollGridWrapper::-webkit-scrollbar-track { background: #f1f1f1; }
#payrollGridWrapper::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
#payrollGridWrapper::-webkit-scrollbar-thumb:hover { background: #555; }

/* ── Table ───────────────────────────────────────────────────── */
#payrollTable {
    font-size: 0.78rem;
    white-space: nowrap;
}

#payrollTable th,
#payrollTable td {
    padding: 3px 5px !important;
    vertical-align: middle;
    border-right: 1px solid #e9ecef;
}

/* ── Sticky columns ──────────────────────────────────────────── */
.sticky-col {
    position: sticky !important;
    z-index: 2 !important;
    background: #fff !important;
}
.sticky-col-0 { left: 0;    min-width: 32px;  width: 32px;  max-width: 32px;  }
.sticky-col-1 { left: 32px;  min-width: 80px;  width: 80px;  max-width: 80px;  }
.sticky-col-2 { left: 112px; min-width: 160px; width: 160px; max-width: 160px; }
.sticky-col-3 { left: 272px; min-width: 120px; width: 120px; max-width: 120px; }

#payrollTable thead .sticky-col {
    z-index: 3 !important;
}

/* ── Column group backgrounds ────────────────────────────────── */
.col-att   { background: #e3f2fd !important; }
.col-wage  { background: #fff8e1 !important; }
.col-earn  { background: #e8f5e9 !important; }
.col-ded   { background: #fce4ec !important; }
.col-gross { background: #c8e6c9 !important; border-left: 2px solid #666 !important; }
.col-net   { background: #bbdefb !important; border-left: 2px solid #666 !important; }

#payrollTable thead th.col-gross,
#payrollTable thead th.col-net {
    z-index: 4 !important;
    position: sticky !important;
}

/* ── Input styling ───────────────────────────────────────────── */
.payroll-input {
    width: 70px;
    padding: 2px 4px;
    font-size: 0.78rem;
    border: 1px solid #ced4da;
    border-radius: 3px;
    text-align: right;
    background: #fffde7;
    transition: background 0.2s;
}
.payroll-input:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 2px rgba(13,110,253,0.15);
}
.payroll-input.att-input { background: #e3f2fd; }
.payroll-input.adv-input { background: #fce4ec; }

/* ── Changed-cell highlight ──────────────────────────────────── */
.cell-changed {
    background: #fff176 !important;
    transition: background 0.3s;
}

/* ── Row hover ───────────────────────────────────────────────── */
#payrollTable tbody tr:hover td { background-color: rgba(13,110,253,0.04) !important; }
#payrollTable tbody tr:hover .sticky-col { background-color: #f8f9fa !important; }

/* ── Calculated cell ─────────────────────────────────────────── */
.calc-val { font-weight: 600; cursor: default; }

/* ── Status icons ────────────────────────────────────────────── */
.status-icon { font-size: 0.72rem; }
.status-on  { color: #198754; }
.status-off { color: #dc3545; opacity: 0.5; }

/* ── Column toggle chips ────────────────────────────────────── */
.col-toggle-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.col-toggle-chip {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: 600;
    cursor: pointer; user-select: none; border: 1px solid transparent;
    transition: all 0.15s;
}
.col-toggle-chip.active { color: #fff; }
.col-toggle-chip:not(.active) { background: #e9ecef; color: #666; text-decoration: line-through; }
.col-toggle-chip .chip-dot { width: 8px; height: 8px; border-radius: 50%; }
.chip-att  { background: #e3f2fd; color: #1565c0; border-color: #90caf9; }
.chip-att.active  { background: #1565c0; color: #fff; }
.chip-wage { background: #fff8e1; color: #f57f17; border-color: #ffe082; }
.chip-wage.active { background: #f57f17; color: #fff; }
.chip-earn { background: #e8f5e9; color: #2e7d32; border-color: #a5d6a7; }
.chip-earn.active { background: #2e7d32; color: #fff; }
.chip-ded  { background: #fce4ec; color: #c62828; border-color: #f48fb1; }
.chip-ded.active  { background: #c62828; color: #fff; }

/* ── Hidden columns ─────────────────────────────────────────── */
[data-col-group].col-hidden { display: none !important; }

/* ── Toast ───────────────────────────────────────────────────── */
#loadToast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    min-width: 300px;
}

/* ── Save spinner ────────────────────────────────────────────── */
.saving-row td { opacity: 0.6; }
.save-spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid #0d6efd; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Modal ───────────────────────────────────────────────────── */
#editModal .modal-dialog { max-width: 640px; }
#editModal .form-label { font-size: 0.82rem; font-weight: 600; margin-bottom: 2px; }
#editModal .form-control, #editModal .form-check-input { font-size: 0.85rem; }
#editModal .modal-body { max-height: 70vh; overflow-y: auto; }

/* ── Footer summary bar ──────────────────────────────────────── */
#summaryBar {
    font-size: 0.82rem;
    font-weight: 600;
}

/* ── Row save indicator ──────────────────────────────────────── */
.row-save-ok td:first-child::after {
    content: '\2713';
    position: absolute;
    left: 6px;
    top: 50%;
    transform: translateY(-50%);
    color: #198754;
    font-weight: bold;
    font-size: 0.7rem;
}
</style>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- COLUMN TOGGLE BAR -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="card mb-2" id="colToggleCard" style="display:none;">
    <div class="card-body py-1 px-2">
        <div class="col-toggle-bar">
            <small class="text-muted me-1 fw-bold"><i class="bi bi-eye me-1"></i>Columns:</small>
            <span class="col-toggle-chip chip-att active" data-toggle-group="att"><span class="chip-dot" style="background:#1565c0;"></span>Attendance</span>
            <span class="col-toggle-chip chip-wage" data-toggle-group="wage"><span class="chip-dot" style="background:#f57f17;"></span>Wage Details</span>
            <span class="col-toggle-chip chip-earn active" data-toggle-group="earn"><span class="chip-dot" style="background:#2e7d32;"></span>Earnings</span>
            <span class="col-toggle-chip chip-ded active" data-toggle-group="ded"><span class="chip-dot" style="background:#c62828;"></span>Deductions</span>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- TOP BAR -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="card mb-2">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <input type="hidden" name="page" value="payroll/process-edit">

            <!-- Client -->
            <div class="col-md-2">
                <label class="form-label mb-0 small">Client</label>
                <select class="form-select form-select-sm" name="client_id" id="clientSelect">
                    <option value="">-- Select --</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Unit -->
            <div class="col-md-2">
                <label class="form-label mb-0 small">Unit</label>
                <select class="form-select form-select-sm" name="unit_id" id="unitSelect">
                    <option value="">-- Select --</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>>
                        <?= sanitize($u['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Month -->
            <div class="col-md-1">
                <label class="form-label mb-0 small">Month</label>
                <select class="form-select form-select-sm" name="month" id="monthSelect">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>>
                        <?= date('M', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Year -->
            <div class="col-md-1">
                <label class="form-label mb-0 small">Year</label>
                <select class="form-select form-select-sm" name="year" id="yearSelect">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Load Button -->
            <div class="col-md-auto">
                <button type="submit" name="load" value="1" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-down-circle me-1"></i>Load Employees
                </button>
            </div>

            <!-- Save All Button -->
            <div class="col-md-auto">
                <button type="button" class="btn btn-success btn-sm" id="btnSaveAll" <?= !$loaded ? 'disabled' : '' ?>>
                    <i class="bi bi-floppy me-1"></i>Save All
                </button>
            </div>

            <!-- Info -->
            <div class="col-md-auto ms-auto">
                <span class="badge bg-light text-dark border" id="empCountBadge">
                    <?php if ($loaded): ?>
                    <i class="bi bi-people me-1"></i><?= count($employeeData) ?> Employees &middot;
                    <span title="Pay Days Mode: <?= htmlspecialchars($payDaysType) ?>"><?= $totalDays ?> Working Days</span>
                    <?php if ($payDaysType !== 'actual'): ?>
                    <span class="badge bg-info ms-1" title="Pay Days Mode"><?= $payDaysType ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    Select filters &amp; Load
                    <?php endif; ?>
                </span>
            </div>
        </form>
    </div>
</div>

<?php if (!$loaded): ?>
<!-- Empty state -->
<div class="text-center py-5 text-muted">
    <i class="bi bi-journal-text fs-1"></i>
    <p class="mt-3">Select <strong>Client, Unit, Month, Year</strong> and click <strong>Load Employees</strong> to begin.</p>
</div>

<?php else: ?>
<?php if (empty($employeeData)): ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-person-x fs-1"></i>
    <p class="mt-3">No active employees found for the selected unit.</p>
</div>
<?php else: ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- PAYROLL GRID -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="payrollGridWrapper">
    <table class="table table-sm table-bordered mb-0" id="payrollTable">
        <thead>
            <!-- ── Row 1: Group headers ──────────────────────────── -->
            <tr style="font-size:0.72rem;">
                <th rowspan="2" class="sticky-col sticky-col-0 text-center">#</th>
                <th rowspan="2" class="sticky-col sticky-col-1">Code</th>
                <th rowspan="2" class="sticky-col sticky-col-2">Employee Name</th>
                <th rowspan="2" class="sticky-col sticky-col-3">Designation</th>
                <th colspan="4" class="text-center col-att" data-col-group="att">ATTENDANCE</th>
                <th colspan="6" class="text-center col-wage" data-col-group="wage">WAGE DETAILS (Monthly)</th>
                <th colspan="6" class="text-center col-earn" data-col-group="earn">EARNINGS (Calculated)</th>
                <th rowspan="2" class="text-end col-gross" style="min-width:90px;"><strong>Gross Salary</strong></th>
                <th colspan="8" class="text-center col-ded" data-col-group="ded">DEDUCTIONS</th>
                <th rowspan="2" class="text-end col-net" style="min-width:100px; border-left:2px solid #666;"><strong>Net Pay</strong></th>
                <th rowspan="2" style="min-width:36px;"></th>
            </tr>
            <!-- ── Row 2: Column headers ─────────────────────────── -->
            <tr style="font-size:0.72rem;">
                <!-- Attendance -->
                <th class="text-center col-att" data-col-group="att">Present</th>
                <th class="text-center col-att" data-col-group="att">W/O</th>
                <th class="text-center col-att" data-col-group="att">Extra</th>
                <th class="text-center col-att" data-col-group="att">OT Hrs</th>
                <!-- Wage Details -->
                <th class="text-end col-wage" data-col-group="wage">Basic+DA</th>
                <th class="text-end col-wage" data-col-group="wage">HRA</th>
                <th class="text-end col-wage" data-col-group="wage">L.Enc</th>
                <th class="text-end col-wage" data-col-group="wage">B.Enc</th>
                <th class="text-end col-wage" data-col-group="wage">Wash</th>
                <th class="text-end col-wage" data-col-group="wage">OT Rate/hr</th>
                <!-- Earnings -->
                <th class="text-end col-earn" data-col-group="earn">Basic+DA</th>
                <th class="text-end col-earn" data-col-group="earn">HRA</th>
                <th class="text-end col-earn" data-col-group="earn">L.Enc</th>
                <th class="text-end col-earn" data-col-group="earn">B.Enc</th>
                <th class="text-end col-earn" data-col-group="earn">Wash</th>
                <th class="text-end col-earn" data-col-group="earn">OT Amt</th>
                <!-- Deductions -->
                <th class="text-end col-ded" data-col-group="ded">PF</th>
                <th class="text-end col-ded" data-col-group="ded">ESI</th>
                <th class="text-end col-ded" data-col-group="ded">PT</th>
                <th class="text-end col-ded" data-col-group="ded">LWF</th>
                <th class="text-end col-ded" data-col-group="ded">Advance</th>
                <th class="text-end col-ded" data-col-group="ded" style="background:#f3e5f5;">Loan EMI</th>
                <th class="text-end col-ded" data-col-group="ded">Off.Ded</th>
                <th class="text-end col-ded fw-bold col-gross" data-col-group="ded" style="border-left:2px solid #666;">Tot Ded</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employeeData as $idx => $ed):
            $emp      = $ed['emp'];
            $sal      = $ed['salary'];
            $att      = $ed['att'];
            $adv      = $ed['adv'];
            $existing = $ed['existing'];
            // Prefer saved loan_emi from existing payroll record, fall back to calculated
            $savedLoanEmi = floatval($existing['loan_emi'] ?? 0);
            $loanEmiDisplay = $savedLoanEmi > 0 ? $savedLoanEmi : $loanEmi;

            $empId      = (int)$emp['id'];
            $empCode    = $emp['employee_code'];
            $empName    = $emp['full_name'];
            $designation = $emp['designation'];

            // Statutory flags come from salary structure, not employees table
            $pfApp  = !empty($sal['pf_applicable']);
            $esiApp = !empty($sal['esi_applicable']);
            $ptApp  = !empty($sal['pt_applicable']) && $unitStatePtApplicable;
            $lwfApp = !empty($sal['lwf_applicable']);

            // OT settings from unit salary formula
            $otCalcType = $unitOtCalcType; // single_pay or double_pay
            $otHrsPerDay = $unitOtHrsPerDay;
            $otMultiplier = ($otCalcType === 'double_pay') ? 2 : 1;

            // Wage details (monthly full amounts from salary structure)
            $wBasicDa = floatval($sal['basic_da'] ?? 0);
            $wHra     = floatval($sal['hra'] ?? 0);
            $wLeaveEnc = floatval($sal['leave_encashment'] ?? 0);
            $wBonusEnc = floatval($sal['bonus_encashment'] ?? 0);
            $wWash     = floatval($sal['washing_allowance'] ?? 0);
            $wGrossSal = floatval($sal['gross_salary'] ?? ($wBasicDa + $wHra + $wLeaveEnc + $wBonusEnc + $wWash));

            // OT base depends on ot_calculation_on setting (match process.php)
            $wOtRate = 0;
            if ($totalDays > 0 && $otHrsPerDay > 0) {
                $otBase = 0;
                switch ($unitOtCalcOn) {
                    case 'gross':
                        $otBase = $wGrossSal;
                        break;
                    case 'basic_hra':
                        $otBase = $wBasicDa + $wHra;
                        break;
                    case 'basic':
                        $otBase = $wBasicDa * 0.5; // approximate basic without DA
                        break;
                    default: // basic_da
                        $otBase = $wBasicDa;
                        break;
                }
                $wOtRate = round($otBase / $totalDays / $otHrsPerDay, 2);
            }

            // Attendance
            $attPresent  = floatval($att['total_present'] ?? 0);
            $attWO       = floatval($att['total_wo'] ?? 0);
            $attExtra    = floatval($att['total_extra'] ?? 0);
            $attOtHours  = floatval($att['overtime_hours'] ?? 0);

            // If existing payroll, prefer existing attendance numbers
            if (!empty($existing)) {
                $attPresent = $attPresent ?: floatval($existing['total_present'] ?? 0);
                $attWO      = $attWO ?: floatval($existing['total_wo'] ?? 0);
                $attExtra   = $attExtra ?: floatval($existing['total_extra'] ?? 0);
            }

            $paidDays = $attPresent + $attWO + $attExtra;

            // Advances (exclude office_advance to match process.php — stored/processed separately)
            $adv1        = floatval($adv['adv1'] ?? 0);
            $adv2        = floatval($adv['adv2'] ?? 0);
            $advDress    = floatval($adv['dress_advance'] ?? 0);
            $totalAdvance = $adv1 + $adv2 + $advDress; // office_advance is NOT part of salary_advance

            // If existing payroll and no advance data from table
            if (!empty($existing) && $totalAdvance == 0) {
                $totalAdvance = floatval($existing['salary_advance'] ?? 0);
            }
            $officeDeduction = floatval($existing['office_deduction'] ?? 0);
            // Also check office_advance from employee_advances if no existing payroll value
            if ($officeDeduction <= 0) {
                $officeDeduction = floatval($adv['office_advance'] ?? 0);
            }

            // LWF: state-specific amount from lwf_rates table (already resolved at unit level)
            // $lwfAmount is 0 if not a contribution month or no DB rate found
            $empLwfAmount = $lwfApp ? $lwfAmount : 0;

            // ESI eligibility: use FULL monthly gross from salary structure (matching process.php)
            // process.php checks $emp['gross_salary'] (from employee_salary_structures) not the pro-rated payroll value
            $existingGross = $wGrossSal;
        ?>
            <tr data-row="<?= $idx ?>"
                data-emp-id="<?= $empId ?>"
                data-emp-code="<?= sanitize($empCode) ?>"
                data-pf="<?= $pfApp ? 1 : 0 ?>"
                data-esi="<?= $esiApp ? 1 : 0 ?>"
                data-pt="<?= $ptApp ? 1 : 0 ?>"
                data-lwf="<?= $lwfApp ? 1 : 0 ?>"
                data-lwf-amount="<?= $empLwfAmount ?>"
                data-ot-multiplier="<?= $otMultiplier ?>"
                data-ot-hrs-per-day="<?= $otHrsPerDay ?>"
                data-existing-gross="<?= $existingGross ?>"
                data-loan-emi="<?= $loanEmi ?>"
                data-original-wages="<?= htmlspecialchars(json_encode([
                    'basic_da' => $wBasicDa, 'hra' => $wHra,
                    'leave_encashment' => $wLeaveEnc, 'bonus_encashment' => $wBonusEnc,
                    'washing_allowance' => $wWash, 'ot_rate' => $wOtRate
                ])) ?>"
            >
                <!-- Sticky cols -->
                <td class="sticky-col sticky-col-0 text-center">#<?= $idx + 1 ?></td>
                <td class="sticky-col sticky-col-1"><?= sanitize($empCode) ?></td>
                <td class="sticky-col sticky-col-2 fw-semibold"><?= sanitize($empName) ?></td>
                <td class="sticky-col sticky-col-3">
                    <?= sanitize($designation) ?>
                    <span class="ms-1">
                        <?php if ($pfApp):  ?><i class="bi bi-p-circle status-icon status-on" title="PF"></i><?php endif; ?>
                        <?php if ($esiApp): ?><i class="bi bi-e-circle status-icon status-on" title="ESI"></i><?php endif; ?>
                        <?php if ($ptApp):  ?><i class="bi bi-t-circle status-icon status-on" title="PT"></i><?php endif; ?>
                    </span>
                </td>

                <!-- ── ATTENDANCE (editable) ─────────────────── -->
                <td class="text-center col-att" data-col-group="att">
                    <input type="number" class="payroll-input att-input att-field" name="att_present" value="<?= $attPresent ?>" min="0" max="31" step="0.5">
                </td>
                <td class="text-center col-att" data-col-group="att">
                    <input type="number" class="payroll-input att-input att-field" name="att_wo" value="<?= $attWO ?>" min="0" max="31" step="0.5">
                </td>
                <td class="text-center col-att" data-col-group="att">
                    <input type="number" class="payroll-input att-input att-field" name="att_extra" value="<?= $attExtra ?>" min="0" max="31" step="0.5">
                </td>
                <td class="text-center col-att" data-col-group="att">
                    <input type="number" class="payroll-input att-input att-field" name="ot_hours" value="<?= $attOtHours ?>" min="0" max="200" step="0.5">
                </td>

                <!-- ── WAGE DETAILS (editable, monthly amounts) ── -->
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_basic_da" value="<?= $wBasicDa ?>" min="0" step="1">
                </td>
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_hra" value="<?= $wHra ?>" min="0" step="1">
                </td>
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_leave_enc" value="<?= $wLeaveEnc ?>" min="0" step="1">
                </td>
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_bonus_enc" value="<?= $wBonusEnc ?>" min="0" step="1">
                </td>
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_wash" value="<?= $wWash ?>" min="0" step="1">
                </td>
                <td class="text-end col-wage" data-col-group="wage">
                    <input type="number" class="payroll-input wage-field" name="w_ot_rate" value="<?= $wOtRate ?>" min="0" step="0.01">
                </td>

                <!-- ── EARNINGS (auto-calculated, readonly) ───── -->
                <td class="text-end col-earn calc-val earn-basic-da" data-col-group="earn">--</td>
                <td class="text-end col-earn calc-val earn-hra" data-col-group="earn">--</td>
                <td class="text-end col-earn calc-val earn-leave-enc" data-col-group="earn">--</td>
                <td class="text-end col-earn calc-val earn-bonus-enc" data-col-group="earn">--</td>
                <td class="text-end col-earn calc-val earn-wash" data-col-group="earn">--</td>
                <td class="text-end col-earn calc-val earn-ot-amt" data-col-group="earn">--</td>

                <!-- ── GROSS ──────────────────────────────────── -->
                <td class="text-end col-gross fw-bold calc-val gross-salary">--</td>

                <!-- ── DEDUCTIONS ─────────────────────────────── -->
                <td class="text-end col-ded calc-val ded-pf" data-col-group="ded">--</td>
                <td class="text-end col-ded calc-val ded-esi" data-col-group="ded">--</td>
                <td class="text-end col-ded calc-val ded-pt" data-col-group="ded">--</td>
                <td class="text-end col-ded calc-val ded-lwf" data-col-group="ded">--</td>
                <td class="text-end col-ded" data-col-group="ded">
                    <input type="number" class="payroll-input adv-input ded-field" name="advance" value="<?= $totalAdvance ?>" min="0" step="1">
                </td>
                <td class="text-end col-ded" data-col-group="ded" style="background:#f3e5f5;">
                    <input type="number" class="payroll-input ded-field" name="loan_emi" value="<?= $loanEmiDisplay > 0 ? $loanEmiDisplay : '' ?>" min="0" step="1" placeholder="0">
                </td>
                <td class="text-end col-ded" data-col-group="ded">
                    <input type="number" class="payroll-input adv-input ded-field" name="office_ded" value="<?= $officeDeduction ?>" min="0" step="1">
                </td>
                <td class="text-end col-ded col-gross fw-bold calc-val total-deductions" data-col-group="ded" style="border-left:2px solid #666;">--</td>

                <!-- ── NET PAY ────────────────────────────────── -->
                <td class="text-end col-net fw-bold calc-val net-pay">--</td>

                <!-- ── Action ─────────────────────────────────── -->
                <td class="text-center" style="min-width:36px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-row-detail" title="Edit Details">
                        <i class="bi bi-pencil-square" style="font-size:0.75rem;"></i>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot id="summaryBar">
            <tr style="font-weight:700; font-size:0.78rem; background:#e8f0fe !important;">
                <td colspan="4" class="text-center sticky-col" style="left:0; z-index:2; background:#e8f0fe !important;">TOTALS</td>
                <!-- Attendance totals -->
                <td class="text-center col-att" data-col-group="att" id="totPresent">0</td>
                <td class="text-center col-att" data-col-group="att" id="totWO">0</td>
                <td class="text-center col-att" data-col-group="att" id="totExtra">0</td>
                <td class="text-center col-att" data-col-group="att" id="totOtHrs">0</td>
                <!-- Wage Details — no totals -->
                <td class="col-wage" data-col-group="wage"></td>
                <td class="col-wage" data-col-group="wage"></td>
                <td class="col-wage" data-col-group="wage"></td>
                <td class="col-wage" data-col-group="wage"></td>
                <td class="col-wage" data-col-group="wage"></td>
                <td class="col-wage" data-col-group="wage"></td>
                <!-- Earnings totals -->
                <td class="text-end col-earn" data-col-group="earn" id="totEarnBasicDa">--</td>
                <td class="text-end col-earn" data-col-group="earn" id="totEarnHra">--</td>
                <td class="text-end col-earn" data-col-group="earn" id="totEarnLeaveEnc">--</td>
                <td class="text-end col-earn" data-col-group="earn" id="totEarnBonusEnc">--</td>
                <td class="text-end col-earn" data-col-group="earn" id="totEarnWash">--</td>
                <td class="text-end col-earn" data-col-group="earn" id="totEarnOtAmt">--</td>
                <!-- Gross -->
                <td class="text-end col-gross" style="border-left:2px solid #666; background:#e8f0fe !important;" id="totalGross">₹0.00</td>
                <!-- Deduction totals -->
                <td class="text-end col-ded" data-col-group="ded" id="totPF">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totESI">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totPT">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totLWF">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totAdvance">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totLoanEmi">--</td>
                <td class="text-end col-ded" data-col-group="ded" id="totOffDed">--</td>
                <!-- Tot Ded -->
                <td class="text-end col-ded fw-bold col-gross" data-col-group="ded" style="border-left:2px solid #666; background:#e8f0fe !important;" id="totalDed">₹0.00</td>
                <!-- Net Pay -->
                <td class="text-end col-net" style="border-left:2px solid #666; background:#e8f0fe !important;" id="totalNet">₹0.00</td>
                <td style="background:#e8f0fe !important;"></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- DETAIL EDIT MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="editModalTitle">
                    <i class="bi bi-person-badge me-2"></i>Edit Employee Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 pb-2 border-bottom">
                    <span class="fw-bold" id="modalEmpName">--</span>
                    <span class="text-muted ms-2" id="modalEmpCode">--</span>
                </div>

                <!-- Attendance Section -->
                <h6 class="mb-2"><i class="bi bi-calendar-check me-1"></i>Attendance</h6>
                <div class="row g-2 mb-3">
                    <div class="col-3">
                        <label class="form-label">Present</label>
                        <input type="number" class="form-control form-control-sm" id="modalAttPresent" min="0" max="31" step="0.5">
                    </div>
                    <div class="col-3">
                        <label class="form-label">W/O</label>
                        <input type="number" class="form-control form-control-sm" id="modalAttWO" min="0" max="31" step="0.5">
                    </div>
                    <div class="col-3">
                        <label class="form-label">Extra</label>
                        <input type="number" class="form-control form-control-sm" id="modalAttExtra" min="0" max="31" step="0.5">
                    </div>
                    <div class="col-3">
                        <label class="form-label">OT Hours</label>
                        <input type="number" class="form-control form-control-sm" id="modalOtHours" min="0" max="200" step="0.5">
                    </div>
                </div>

                <!-- Wage Details Section -->
                <h6 class="mb-2"><i class="bi bi-cash-coin me-1"></i>Wage Details (Monthly)</h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Basic + DA</label>
                        <input type="number" class="form-control form-control-sm" id="modalWBasicDa" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">HRA</label>
                        <input type="number" class="form-control form-control-sm" id="modalWHra" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Leave Encashment</label>
                        <input type="number" class="form-control form-control-sm" id="modalWLeaveEnc" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Bonus Encashment</label>
                        <input type="number" class="form-control form-control-sm" id="modalWBonusEnc" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Washing Allowance</label>
                        <input type="number" class="form-control form-control-sm" id="modalWWash" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">OT Rate / hr</label>
                        <input type="number" class="form-control form-control-sm" id="modalWOtRate" min="0" step="0.01">
                    </div>
                </div>

                <!-- Statutory Applicability -->
                <h6 class="mb-2"><i class="bi bi-shield-check me-1"></i>Statutory Applicability</h6>
                <div class="row g-2 mb-3">
                    <div class="col-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="modalPfApp">
                            <label class="form-check-label small" for="modalPfApp">PF</label>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="modalEsiApp">
                            <label class="form-check-label small" for="modalEsiApp">ESI</label>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="modalPtApp">
                            <label class="form-check-label small" for="modalPtApp">PT</label>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="modalLwfApp">
                            <label class="form-check-label small" for="modalLwfApp">LWF</label>
                        </div>
                    </div>
                </div>

                <!-- Deductions Section -->
                <h6 class="mb-2"><i class="bi bi-dash-circle me-1"></i>Deductions (Editable)</h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Salary Advance</label>
                        <input type="number" class="form-control form-control-sm" id="modalAdvance" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Office Deduction</label>
                        <input type="number" class="form-control form-control-sm" id="modalOfficeDed" min="0" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Loan EMI</label>
                        <input type="number" class="form-control form-control-sm" id="modalLoanEmi" min="0" step="1">
                    </div>
                </div>

                <!-- Calculated Summary (read-only preview) -->
                <div class="bg-light rounded p-2 border" id="modalCalcPreview">
                    <div class="row text-end" style="font-size:0.8rem;">
                        <div class="col-4">Gross Salary:</div>
                        <div class="col-4 fw-bold" id="modalPreviewGross">₹0.00</div>
                        <div class="col-4 fw-bold text-danger" id="modalPreviewDed">₹0.00</div>
                        <div class="col-4 mt-1">Net Pay:</div>
                        <div class="col-8 fw-bold text-success" id="modalPreviewNet">₹0.00</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnModalPrev">
                    <i class="bi bi-chevron-left me-1"></i>Previous
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="btnModalSave">
                    <i class="bi bi-check-lg me-1"></i>Recalculate & Save
                </button>
                <button type="button" class="btn btn-sm btn-success" id="btnModalSaveNext">
                    Save & Next<i class="bi bi-chevron-right ms-1"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- TOAST -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="loadToast" class="toast align-items-center text-bg-success border-0" role="alert">
    <div class="d-flex">
        <div class="toast-body" id="toastBody">
            <i class="bi bi-check-circle me-1"></i>Loaded <?= count($employeeData) ?> employees for <?= sanitize($monthLabel) ?> <?= $year ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
(function() {
    'use strict';

    const TOTAL_DAYS = <?= $totalDays ?>;
    const MONTH = <?= $month ?>;
    const YEAR  = <?= $year ?>;
    const UNIT_ID = <?= $unitId ?>;
    const PERIOD_ID = <?= $payrollPeriodId ?>;
    const SAVE_URL = 'index.php?page=api/payroll-save-row';

    // PT slabs from database (for unit's state)
    const PT_SLABS = <?= json_encode($ptSlabs) ?>;
    const UNIT_STATE = <?= json_encode($unitState) ?>;

    // PF/ESI rates from DB (matching class.payroll.php)
    const PF_EMPLOYEE_SHARE = <?= $pfEmployeeShare ?>;
    const PF_WAGE_CEILING   = <?= $pfWageCeiling ?>;
    const PF_EMPLOYER_SHARE = <?= $pfEmployerShare ?>;
    const PF_EMPLOYER_EPS   = <?= $pfEmployerEps ?>;
    const PF_EMPLOYER_EDLIS = <?= $pfEmployerEdlis ?>;
    const PF_EPF_ADMIN      = <?= $pfEpfAdmin ?>;
    const ESI_EMPLOYEE_SHARE = <?= $esiEmployeeShare ?>;
    const ESI_WAGE_CEILING   = <?= $esiWageCeiling ?>;

    // ── Helpers ─────────────────────────────────────────────────
    function round2(v) { return Math.round((v || 0) * 100) / 100; }
    function fmt(v) { return '\u20B9' + round2(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function numval(el) { return parseFloat($(el).val()) || 0; }

    // ── PT calculation (state-aware, slab-based) ─────────────────
    function calculatePT(grossSalary, ptApp) {
        if (!ptApp) return 0;
        // If DB slabs exist, use them
        if (PT_SLABS.length > 0) {
            for (var i = 0; i < PT_SLABS.length; i++) {
                var slab = PT_SLABS[i];
                var minG = parseFloat(slab.min_gross);
                var maxG = parseFloat(slab.max_gross);
                if (grossSalary > minG && (isNaN(maxG) || maxG === null || grossSalary <= maxG)) {
                    return parseFloat(slab.pt_amount);
                }
            }
            return 0;
        }
        // Fallback: state-code-based hardcoded slabs (same as class.payroll.php)
        var st = (UNIT_STATE || '').toUpperCase();
        if (st === 'MH' || st === 'MAHARASHTRA') {
            return grossSalary > 10000 ? 200 : 0;
        }
        if (st === 'KA' || st === 'KARNATAKA') {
            if (grossSalary > 15000) return 200;
            if (grossSalary > 10000) return 150;
            return 0;
        }
        if (st === 'TN' || st === 'TAMIL NADU') {
            if (grossSalary > 75000) return Math.round(1250/6 * 100) / 100;
            if (grossSalary > 50000) return Math.round(833/6 * 100) / 100;
            return 0;
        }
        if (st === 'DL' || st === 'DELHI') {
            return grossSalary > 25000 ? 200 : 0;
        }
        if (st === 'GJ' || st === 'GUJARAT') {
            return grossSalary > 12000 ? 200 : 0;
        }
        // Default: Maharashtra-style
        return grossSalary > 10000 ? 200 : 0;
    }

    // ── Calculate a single row ──────────────────────────────────
    function recalculateRow(tr) {
        var $tr = $(tr);
        var pfApp     = $tr.data('pf') === 1;
        var esiApp    = $tr.data('esi') === 1;
        var ptApp     = $tr.data('pt') === 1;
        var lwfApp    = $tr.data('lwf') === 1;
        var lwfAmount = parseFloat($tr.data('lwf-amount')) || 0;
        var otMult    = parseFloat($tr.data('ot-multiplier')) || 1;
        var existGross= parseFloat($tr.data('existing-gross')) || 0;

        // Attendance inputs
        var attPresent = numval($tr.find('[name=att_present]'));
        var attWO      = numval($tr.find('[name=att_wo]'));
        var attExtra   = numval($tr.find('[name=att_extra]'));
        var otHours    = numval($tr.find('[name=ot_hours]'));
        var paidDays   = attPresent + attWO + attExtra;

        // Wage inputs (monthly full amounts)
        var wBasicDa   = numval($tr.find('[name=w_basic_da]'));
        var wHra       = numval($tr.find('[name=w_hra]'));
        var wLeaveEnc  = numval($tr.find('[name=w_leave_enc]'));
        var wBonusEnc  = numval($tr.find('[name=w_bonus_enc]'));
        var wWash      = numval($tr.find('[name=w_wash]'));
        var wOtRate    = numval($tr.find('[name=w_ot_rate]'));

        // Deduction inputs
        var advance    = numval($tr.find('[name=advance]'));
        var officeDed  = numval($tr.find('[name=office_ded]'));

        // Loan EMI — editable input, fall back to data attribute
        var loanEmiInput = $tr.find('[name=loan_emi]');
        var loanEmi = loanEmiInput.length ? numval(loanEmiInput) : (parseFloat($tr.data('loan-emi')) || 0);

        // ── Calculations ──
        var totalDays = TOTAL_DAYS;
        if (totalDays === 0 || paidDays === 0) {
            setRowZero($tr);
            updateTotals();
            return;
        }

        var basicDa     = round2(wBasicDa * paidDays / totalDays);
        var hra         = round2(wHra * paidDays / totalDays);
        var leaveEnc    = round2(wLeaveEnc * paidDays / totalDays);
        var bonusEnc    = round2(wBonusEnc * paidDays / totalDays);
        var wash        = round2(wWash * paidDays / totalDays);
        var otAmount    = round2(wOtRate * otHours * otMult);

        var grossSalary = round2(basicDa + hra + leaveEnc + bonusEnc + wash + otAmount);

        // PF: use DB rates, ceiling from pf_rates table
        var pfBase = Math.min(basicDa, PF_WAGE_CEILING);
        var pf = pfApp ? round2(pfBase * PF_EMPLOYEE_SHARE / 100) : 0;
        // Employer PF components (stored in calc for save)
        var pfEmployer  = pfApp ? round2(pfBase * PF_EMPLOYER_SHARE / 100) : 0;
        var epsEmployer = pfApp ? round2(pfBase * PF_EMPLOYER_EPS / 100) : 0;
        var edlisEmployer= pfApp ? round2(pfBase * PF_EMPLOYER_EDLIS / 100) : 0;
        var epfAdmin     = pfApp ? round2(pfBase * PF_EPF_ADMIN / 100) : 0;
        var esiEmployer  = 0;

        // ESI: use DB rate, check full monthly gross (not pro-rated) against ceiling
        var esi = 0;
        if (esiApp && existGross > 0 && existGross <= ESI_WAGE_CEILING) {
            esi = round2(grossSalary * ESI_EMPLOYEE_SHARE / 100);
            esiEmployer = round2(grossSalary * <?= $esiEmployerShare ?> / 100);
        }

        // PT: state-aware slab-based calculation
        var pt = calculatePT(grossSalary, ptApp);

        // LWF
        var lwf = lwfApp ? lwfAmount : 0;

        // Loan EMI (already read from input above)

        var totalDed = round2(pf + esi + pt + lwf + advance + officeDed + loanEmi);
        var netPay   = Math.round(grossSalary - totalDed); // Round to nearest ₹.00

        // ── Write earnings ──
        $tr.find('.earn-basic-da').text(fmt(basicDa));
        $tr.find('.earn-hra').text(fmt(hra));
        $tr.find('.earn-leave-enc').text(fmt(leaveEnc));
        $tr.find('.earn-bonus-enc').text(fmt(bonusEnc));
        $tr.find('.earn-wash').text(fmt(wash));
        $tr.find('.earn-ot-amt').text(fmt(otAmount));

        // ── Gross ──
        $tr.find('.gross-salary').text(fmt(grossSalary));

        // ── Deductions ──
        $tr.find('.ded-pf').text(fmt(pf));
        $tr.find('.ded-esi').text(fmt(esi));
        $tr.find('.ded-pt').text(fmt(pt));
        $tr.find('.ded-lwf').text(fmt(lwf));

        // ── Totals ──
        $tr.find('.total-deductions').text(fmt(totalDed));
        $tr.find('.net-pay').text(fmt(netPay));

        // ── Highlight negative net ──
        if (netPay < 0) {
            $tr.find('.net-pay').css('color', '#dc3545');
        } else {
            $tr.find('.net-pay').css('color', '');
        }

        // Employer contributions + CTC (matching process.php: gross + employer contrib + bonus + gratuity prov)
        var employerContrib = round2(pfEmployer + epsEmployer + edlisEmployer + epfAdmin + esiEmployer);
        var ctc = round2(grossSalary + employerContrib);
        $tr.data('calc', {
            basic_da: basicDa, hra: hra,
            leave_encashment: leaveEnc, bonus_encashment: bonusEnc,
            washing_allowance: wash, overtime_amount: otAmount,
            overtime_hours: otHours,
            gross_earnings: basicDa + hra + leaveEnc + bonusEnc + wash + otAmount,
            gross_salary: grossSalary,
            pf_employee: pf, esi_employee: esi, professional_tax: pt,
            lwf_employee: lwf, salary_advance: advance,
            office_deduction: officeDed, loan_emi: loanEmi,
            total_deductions: totalDed, net_pay: netPay,
            paid_days: paidDays, total_days: totalDays,
            total_present: attPresent, total_wo: attWO, total_extra: attExtra,
            // Employer contributions (matching process.php)
            pf_employer: pfEmployer, eps_employer: epsEmployer,
            edlis_employer: edlisEmployer, epf_admin_charges: epfAdmin,
            esi_employer: esiEmployer,
            total_employer_contribution: employerContrib,
            ctc: ctc
        });

        updateTotals();
    }

    function setRowZero($tr) {
        $tr.find('.calc-val').text('₹0.00');
        $tr.data('calc', null);
    }

    // ── Update column totals ────────────────────────────────────
    function updateTotals() {
        var sP = 0, sWO = 0, sE = 0, sOH = 0;
        var sEBD = 0, sEH = 0, sELE = 0, sEBE = 0, sEW = 0, sEO = 0;
        var sGross = 0, sPF = 0, sESI = 0, sPT = 0, sLWF = 0;
        var sAdv = 0, sLoan = 0, sOffD = 0, sDed = 0, sNet = 0;

        $('#payrollTable tbody tr').each(function() {
            var c = $(this).data('calc');
            if (c) {
                sP  += (c.total_present || 0);
                sWO += (c.total_wo || 0);
                sE  += (c.total_extra || 0);
                sOH += (c.overtime_hours || 0);
                sEBD += (c.basic_da || 0);
                sEH  += (c.hra || 0);
                sELE += (c.leave_encashment || 0);
                sEBE += (c.bonus_encashment || 0);
                sEW  += (c.washing_allowance || 0);
                sEO  += (c.overtime_amount || 0);
                sGross += (c.gross_salary || 0);
                sPF  += (c.pf_employee || 0);
                sESI += (c.esi_employee || 0);
                sPT  += (c.professional_tax || 0);
                sLWF += (c.lwf_employee || 0);
                sAdv += (c.salary_advance || 0);
                sLoan += (c.loan_emi || 0);
                sOffD += (c.office_deduction || 0);
                sDed += (c.total_deductions || 0);
                sNet += (c.net_pay || 0);
            }
        });

        $('#totPresent').text(sP);
        $('#totWO').text(sWO);
        $('#totExtra').text(sE);
        $('#totOtHrs').text(sOH);
        $('#totEarnBasicDa').text(fmt(sEBD));
        $('#totEarnHra').text(fmt(sEH));
        $('#totEarnLeaveEnc').text(fmt(sELE));
        $('#totEarnBonusEnc').text(fmt(sEBE));
        $('#totEarnWash').text(fmt(sEW));
        $('#totEarnOtAmt').text(fmt(sEO));
        $('#totalGross').text(fmt(sGross));
        $('#totPF').text(fmt(sPF));
        $('#totESI').text(fmt(sESI));
        $('#totPT').text(fmt(sPT));
        $('#totLWF').text(fmt(sLWF));
        $('#totAdvance').text(fmt(sAdv));
        $('#totLoanEmi').text(fmt(sLoan));
        $('#totOffDed').text(fmt(sOffD));
        $('#totalDed').text(fmt(sDed));
        $('#totalNet').text(fmt(sNet));
    }

    // ── Mark changed cells ──────────────────────────────────────
    function markChanged(input) {
        var $input = $(input);
        var $tr = $input.closest('tr');
        var originalWages = $tr.data('original-wages') || {};

        if ($input.hasClass('wage-field')) {
            var fieldName = $input.attr('name');
            var originalKey = {
                'w_basic_da': 'basic_da', 'w_hra': 'hra',
                'w_leave_enc': 'leave_encashment', 'w_bonus_enc': 'bonus_encashment',
                'w_wash': 'washing_allowance', 'w_ot_rate': 'ot_rate'
            }[fieldName];
            if (originalKey && parseFloat($input.val()) != originalWages[originalKey]) {
                $input.addClass('cell-changed');
            } else {
                $input.removeClass('cell-changed');
            }
        }
    }

    // ── Bind input events ───────────────────────────────────────
    $(document).on('input change', '#payrollTable input', function() {
        var $tr = $(this).closest('tr');
        recalculateRow($tr);
        markChanged(this);
    });

    // ── Initial recalculation on page load ───────────────────
    $('#payrollTable tbody tr').each(function() {
        recalculateRow(this);
    });
    updateTotals();

    // ── Double-click on wage cells opens modal ──────────────────
    $(document).on('dblclick', '.col-wage, .col-att', function(e) {
        if ($(e.target).is('input')) return; // Don't double-trigger on input
        var $tr = $(this).closest('tr');
        openModalForRow($tr);
    });

    // ── Detail button opens modal ───────────────────────────────
    $(document).on('click', '.btn-row-detail', function() {
        var $tr = $(this).closest('tr');
        openModalForRow($tr);
    });

    var modalCurrentRow = null;
    var editModal = null;

    function openModalForRow($tr) {
        modalCurrentRow = $tr;
        if (!editModal) editModal = new bootstrap.Modal(document.getElementById('editModal'));

        // Populate modal fields from row inputs
        $('#modalEmpName').text($tr.find('.sticky-col-2').text().trim());
        $('#modalEmpCode').text('(' + $tr.find('.sticky-col-1').text().trim() + ')');

        $('#modalAttPresent').val($tr.find('[name=att_present]').val());
        $('#modalAttWO').val($tr.find('[name=att_wo]').val());
        $('#modalAttExtra').val($tr.find('[name=att_extra]').val());
        $('#modalOtHours').val($tr.find('[name=ot_hours]').val());

        $('#modalWBasicDa').val($tr.find('[name=w_basic_da]').val());
        $('#modalWHra').val($tr.find('[name=w_hra]').val());
        $('#modalWLeaveEnc').val($tr.find('[name=w_leave_enc]').val());
        $('#modalWBonusEnc').val($tr.find('[name=w_bonus_enc]').val());
        $('#modalWWash').val($tr.find('[name=w_wash]').val());
        $('#modalWOtRate').val($tr.find('[name=w_ot_rate]').val());

        $('#modalPfApp').prop('checked', $tr.data('pf') === 1);
        $('#modalEsiApp').prop('checked', $tr.data('esi') === 1);
        $('#modalPtApp').prop('checked', $tr.data('pt') === 1);
        $('#modalLwfApp').prop('checked', $tr.data('lwf') === 1);

        $('#modalAdvance').val($tr.find('[name=advance]').val());
        $('#modalOfficeDed').val($tr.find('[name=office_ded]').val());
        $('#modalLoanEmi').val($tr.find('[name=loan_emi]').val());

        // Preview calculation
        previewModalCalc();

        editModal.show();
    }

    function previewModalCalc() {
        var totalDays = TOTAL_DAYS;
        var attPresent = parseFloat($('#modalAttPresent').val()) || 0;
        var attWO      = parseFloat($('#modalAttWO').val()) || 0;
        var attExtra   = parseFloat($('#modalAttExtra').val()) || 0;
        var otHours    = parseFloat($('#modalOtHours').val()) || 0;
        var paidDays   = attPresent + attWO + attExtra;

        var wBasicDa   = parseFloat($('#modalWBasicDa').val()) || 0;
        var wHra       = parseFloat($('#modalWHra').val()) || 0;
        var wLeaveEnc  = parseFloat($('#modalWLeaveEnc').val()) || 0;
        var wBonusEnc  = parseFloat($('#modalWBonusEnc').val()) || 0;
        var wWash      = parseFloat($('#modalWWash').val()) || 0;
        var wOtRate    = parseFloat($('#modalWOtRate').val()) || 0;

        var advance    = parseFloat($('#modalAdvance').val()) || 0;
        var officeDed  = parseFloat($('#modalOfficeDed').val()) || 0;
        var loanEmiM   = parseFloat($('#modalLoanEmi').val()) || 0;

        if (totalDays === 0 || paidDays === 0) {
            $('#modalPreviewGross').text('₹0.00');
            $('#modalPreviewDed').text('₹0.00');
            $('#modalPreviewNet').text('₹0.00');
            return;
        }

        var pfApp   = $('#modalPfApp').is(':checked');
        var esiApp  = $('#modalEsiApp').is(':checked');
        var ptApp   = $('#modalPtApp').is(':checked');
        var lwfApp  = $('#modalLwfApp').is(':checked');

        var otMult  = modalCurrentRow ? (parseFloat(modalCurrentRow.data('ot-multiplier')) || 1) : 1;
        var lwfAmt  = modalCurrentRow ? (parseFloat(modalCurrentRow.data('lwf-amount')) || 0) : 0;
        var existGr = modalCurrentRow ? (parseFloat(modalCurrentRow.data('existing-gross')) || 0) : 0;

        var basicDa  = round2(wBasicDa * paidDays / totalDays);
        var hra      = round2(wHra * paidDays / totalDays);
        var leaveEnc = round2(wLeaveEnc * paidDays / totalDays);
        var bonusEnc = round2(wBonusEnc * paidDays / totalDays);
        var wash     = round2(wWash * paidDays / totalDays);
        var otAmt    = round2(wOtRate * otHours * otMult);

        var gross = round2(basicDa + hra + leaveEnc + bonusEnc + wash + otAmt);
        var pf    = pfApp ? round2(Math.min(basicDa, PF_WAGE_CEILING) * PF_EMPLOYEE_SHARE / 100) : 0;
        var esiG  = existGr > 0 ? existGr : 0; // full monthly gross
        var esi   = (esiApp && esiG > 0 && esiG <= ESI_WAGE_CEILING) ? round2(gross * ESI_EMPLOYEE_SHARE / 100) : 0;
        var pt    = calculatePT(gross, ptApp);
        var lwf   = lwfApp ? lwfAmt : 0;
        var ded   = round2(pf + esi + pt + lwf + advance + officeDed + loanEmiM);
        var net   = Math.round(gross - ded); // Round to nearest ₹.00

        $('#modalPreviewGross').text(fmt(gross));
        $('#modalPreviewDed').text(fmt(ded));
        $('#modalPreviewNet').text(fmt(net));
    }

    // Modal input changes update preview
    $(document).on('input change', '#editModal input, #editModal .form-check-input', function() {
        previewModalCalc();
    });

    // Save from modal → write back to row and recalculate
    function saveModalToRow() {
        if (!modalCurrentRow) return;
        var $tr = modalCurrentRow;

        $tr.find('[name=att_present]').val($('#modalAttPresent').val()).trigger('change');
        $tr.find('[name=att_wo]').val($('#modalAttWO').val()).trigger('change');
        $tr.find('[name=att_extra]').val($('#modalAttExtra').val()).trigger('change');
        $tr.find('[name=ot_hours]').val($('#modalOtHours').val()).trigger('change');

        $tr.find('[name=w_basic_da]').val($('#modalWBasicDa').val()).trigger('change');
        $tr.find('[name=w_hra]').val($('#modalWHra').val()).trigger('change');
        $tr.find('[name=w_leave_enc]').val($('#modalWLeaveEnc').val()).trigger('change');
        $tr.find('[name=w_bonus_enc]').val($('#modalWBonusEnc').val()).trigger('change');
        $tr.find('[name=w_wash]').val($('#modalWWash').val()).trigger('change');
        $tr.find('[name=w_ot_rate]').val($('#modalWOtRate').val()).trigger('change');

        $tr.data('pf', $('#modalPfApp').is(':checked') ? 1 : 0);
        $tr.data('esi', $('#modalEsiApp').is(':checked') ? 1 : 0);
        $tr.data('pt', $('#modalPtApp').is(':checked') ? 1 : 0);
        $tr.data('lwf', $('#modalLwfApp').is(':checked') ? 1 : 0);

        $tr.find('[name=advance]').val($('#modalAdvance').val()).trigger('change');
        $tr.find('[name=office_ded]').val($('#modalOfficeDed').val()).trigger('change');
        $tr.find('[name=loan_emi]').val($('#modalLoanEmi').val()).trigger('change');

        recalculateRow($tr);
    }

    // Modal: Save
    $('#btnModalSave').on('click', function() {
        saveModalToRow();
        if (editModal) editModal.hide();
    });

    // Modal: Save & Next
    $('#btnModalSaveNext').on('click', function() {
        saveModalToRow();
        var $next = modalCurrentRow.next('tr');
        if ($next.length) {
            openModalForRow($next);
        } else {
            if (editModal) editModal.hide();
        }
    });

    // Modal: Previous
    $('#btnModalPrev').on('click', function() {
        saveModalToRow();
        var $prev = modalCurrentRow.prev('tr');
        if ($prev.length) {
            openModalForRow($prev);
        }
    });

    // ── Save a single row via AJAX ──────────────────────────────
    function saveRow($tr) {
        var calc = $tr.data('calc');
        if (!calc) {
            console.warn('No calculated data for row', $tr.data('row'));
            return Promise.resolve(false);
        }

        $tr.addClass('saving-row');

        var payload = {
            employee_id: $tr.data('emp-id'),
            employee_code: $tr.data('emp-code'),
            month: MONTH,
            year: YEAR,
            unit_id: UNIT_ID,
            payroll_period_id: PERIOD_ID,
            pf_applicable: $tr.data('pf') === 1,
            esi_applicable: $tr.data('esi') === 1,
            pt_applicable: $tr.data('pt') === 1,
            lwf_applicable: $tr.data('lwf') === 1,
            wage_details: {
                basic_da: parseFloat($tr.find('[name=w_basic_da]').val()) || 0,
                hra: parseFloat($tr.find('[name=w_hra]').val()) || 0,
                leave_encashment: parseFloat($tr.find('[name=w_leave_enc]').val()) || 0,
                bonus_encashment: parseFloat($tr.find('[name=w_bonus_enc]').val()) || 0,
                washing_allowance: parseFloat($tr.find('[name=w_wash]').val()) || 0,
                gross_salary: parseFloat($tr.find('[name=w_basic_da]').val() || 0)
                    + parseFloat($tr.find('[name=w_hra]').val() || 0)
                    + parseFloat($tr.find('[name=w_leave_enc]').val() || 0)
                    + parseFloat($tr.find('[name=w_bonus_enc]').val() || 0)
                    + parseFloat($tr.find('[name=w_wash]').val() || 0)
            },
            attendance: {
                total_present: parseFloat($tr.find('[name=att_present]').val()) || 0,
                total_wo: parseFloat($tr.find('[name=att_wo]').val()) || 0,
                total_extra: parseFloat($tr.find('[name=att_extra]').val()) || 0,
                overtime_hours: parseFloat($tr.find('[name=ot_hours]').val()) || 0,
                total_paid_days: calc.paid_days || 0
            },
            advances: {
                advance: calc.salary_advance,
                office_deduction: calc.office_deduction,
                loan_emi: parseFloat($tr.find('[name=loan_emi]').val()) || 0
            },
            payroll: {
                basic_da: calc.basic_da,
                hra: calc.hra,
                leave_encashment: calc.leave_encashment,
                bonus_encashment: calc.bonus_encashment,
                washing_allowance: calc.washing_allowance,
                overtime_amount: calc.overtime_amount,
                overtime_hours: calc.overtime_hours,
                gross_earnings: calc.gross_earnings,
                gross_salary: calc.gross_salary,
                pf_employee: calc.pf_employee,
                esi_employee: calc.esi_employee,
                professional_tax: calc.professional_tax,
                lwf_employee: calc.lwf_employee,
                salary_advance: calc.salary_advance,
                office_deduction: calc.office_deduction,
                total_deductions: calc.total_deductions,
                net_pay: calc.net_pay,
                paid_days: calc.paid_days,
                total_days: calc.total_days,
                total_present: calc.total_present,
                total_wo: calc.total_wo,
                total_extra: calc.total_extra,
                // Employer contributions (matching process.php)
                pf_employer: calc.pf_employer,
                eps_employer: calc.eps_employer,
                edlis_employer: calc.edlis_employer,
                epf_admin_charges: calc.epf_admin_charges,
                esi_employer: calc.esi_employer,
                total_employer_contribution: calc.total_employer_contribution,
                ctc: calc.ctc
            }
        };

        return fetch(SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            $tr.removeClass('saving-row');
            if (data.success) {
                // Clear changed highlights
                $tr.find('.cell-changed').removeClass('cell-changed');
                // Brief green flash
                $tr.find('td').first().css('background-color', '#c8e6c9');
                setTimeout(function() { $tr.find('td').first().css('background-color', ''); }, 1200);
            } else {
                alert('Error saving row: ' + (data.message || 'Unknown error'));
            }
            return data.success;
        })
        .catch(function(err) {
            $tr.removeClass('saving-row');
            alert('AJAX error: ' + err.message);
            return false;
        });
    }

    // ── Save All ────────────────────────────────────────────────
    $('#btnSaveAll').on('click', function() {
        var $rows = $('#payrollTable tbody tr');
        var total = $rows.length;
        var saved = 0;
        var failed = 0;

        if (!confirm('Save payroll for all ' + total + ' employees?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="save-spinner me-1"></span>Saving...');

        var chain = Promise.resolve();
        $rows.each(function(idx) {
            chain = chain.then(function() {
                return saveRow($(this));
            }.bind($rows.eq(idx)));
        });

        chain.then(function() {
            // Count results
            $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Save All');
            showToast('Payroll saved for ' + total + ' employees.', 'success');
        });
    });

    // ── Toast ───────────────────────────────────────────────────
    function showToast(msg, type) {
        var $toast = $('#loadToast');
        $toast.removeClass('text-bg-success text-bg-danger text-bg-warning').addClass('text-bg-' + (type || 'success'));
        $('#toastBody').html(
            '<i class="bi bi-' + (type === 'danger' ? 'x-circle' : 'check-circle') + ' me-1"></i>' + msg
        );
        var t = bootstrap.Toast.getOrCreateInstance($toast[0], { delay: 4000 });
        t.show();
    }

    // ── Initial calculate all rows ──────────────────────────────
    $('#payrollTable tbody tr').each(function() {
        recalculateRow(this);
    });

    // ── Show load toast ─────────────────────────────────────────
    var t = bootstrap.Toast.getOrCreateInstance(document.getElementById('loadToast'), { delay: 4000 });
    t.show();

    // ── Column Toggle ──────────────────────────────────────────
    // Show toggle bar
    document.getElementById('colToggleCard').style.display = '';

    // Default hidden groups (wage is NOT active by default)
    var hiddenGroups = { wage: true };

    // Initialize: hide wage columns by default
    Object.keys(hiddenGroups).forEach(function(g) {
        if (hiddenGroups[g]) {
            toggleColumnGroup(g, false);
            var chip = document.querySelector('.col-toggle-chip[data-toggle-group="' + g + '"]');
            if (chip) chip.classList.remove('active');
        }
    });

    // Chip click handlers
    document.querySelectorAll('.col-toggle-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var group = this.getAttribute('data-toggle-group');
            var isActive = this.classList.contains('active');
            toggleColumnGroup(group, !isActive);
            this.classList.toggle('active');
        });
    });

    function toggleColumnGroup(group, show) {
        var cells = document.querySelectorAll('#payrollTable [data-col-group="' + group + '"]');
        cells.forEach(function(cell) {
            if (show) {
                cell.classList.remove('col-hidden');
            } else {
                cell.classList.add('col-hidden');
            }
        });
    }

    // ── Excel-like Enter key navigation ───────────────────────
    $(document).on('keydown', '#payrollTable .payroll-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var $input = $(this);
            var $td = $input.closest('td');
            var $tr = $td.closest('tr');
            var colIndex = $tr.find('td').index($td);
            var $nextRow = $tr.next('tr');

            if ($nextRow.length) {
                var $nextTd = $nextRow.find('td').eq(colIndex);
                var $nextInput = $nextTd.find('.payroll-input');
                if ($nextInput.length) {
                    $nextInput.focus().select();
                } else {
                    // If no input in same column, find next input in the row
                    var $firstInput = $nextRow.find('.payroll-input').first();
                    if ($firstInput.length) {
                        $firstInput.focus().select();
                    }
                }
            }
        }
        // Tab key: default browser behavior moves to next input — let it work
    });

})();
}); // end DOMContentLoaded
</script>

<?php endif; // employeeData not empty ?>
<?php endif; // loaded ?>

<?php
// Client → Unit cascade JS — MUST be outside the loaded/employeeData conditionals
// so it works even when no employees are loaded yet
$extraJS = <<<'JS'
<script>
// Load units when client changes (always available on this page)
document.getElementById('clientSelect').addEventListener('change', function() {
    var clientId = this.value;
    var unitSelect = document.getElementById('unitSelect');

    unitSelect.innerHTML = '<option value="">Loading...</option>';

    if (!clientId) {
        unitSelect.innerHTML = '<option value="">-- Select --</option>';
        return;
    }

    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            unitSelect.innerHTML = '<option value="">-- Select --</option>';
            if (data && data.units) {
                data.units.forEach(function(unit) {
                    var option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">Error loading units</option>';
        });
});
</script>
JS;
?>
