<?php
/**
 * RCS HRMS Pro - Loan Statement / View
 */

$loanId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$loanId) {
    setFlash('error', 'Invalid loan ID.');
    redirect('index.php?page=loan/list');
}

$loanClass = new Loan();
$loan = $loanClass->getById($loanId);

if (!$loan) {
    setFlash('error', 'Loan not found.');
    redirect('index.php?page=loan/list');
}

$pageTitle = 'Loan Statement - ' . sanitize($loan['full_name']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Record manual EMI payment
    if ($action === 'record_payment') {
        $month = (int)($_POST['month'] ?? 0);
        $year = (int)($_POST['year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 2020) {
            setFlash('error', 'Invalid month/year.');
        } else {
            $result = $loanClass->recordEmi($loanId, $month, $year, false);
            setFlash($result['success'] ? 'success' : 'error', $result['message']);
        }
        redirect("index.php?page=loan/view&id={$loanId}");
    }

    // Settle loan
    if ($action === 'settle_loan') {
        $result = $loanClass->settleLoan($loanId);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect("index.php?page=loan/view&id={$loanId}");
    }

    // Add amount to loan
    if ($action === 'add_amount') {
        $addAmount = (float)($_POST['add_amount'] ?? 0);
        if ($addAmount <= 0) {
            setFlash('error', 'Please enter a valid amount to add.');
        } else {
            $result = $loanClass->addAmount($loanId, $addAmount);
            setFlash($result['success'] ? 'success' : 'error', $result['message']);
        }
        redirect("index.php?page=loan/view&id={$loanId}");
    }
}

// Refresh loan data after any action
$loan = $loanClass->getById($loanId);

// Get EMI log
$emiLog = $loanClass->getEmiLog($loanId);

// Build EMI schedule (projected + actual)
$emiSchedule = [];
$currentMonth = prev_month_num();
$currentYear = date('Y');

$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
               'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthNamesFull = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                   'July', 'August', 'September', 'October', 'November', 'December'];

// Create a map of paid EMIs
$paidEmis = [];
foreach ($emiLog as $log) {
    $key = $log['year'] . '-' . str_pad($log['month'], 2, '0', STR_PAD_LEFT);
    $paidEmis[$key] = $log;
}

// Generate schedule for the full tenure
$startMonth = (int)$loan['start_month'];
$startYear = (int)$loan['start_year'];
$tenure = (int)$loan['tenure_months'];

$runningBalance = (float)$loan['total_repayable'];
$emiAmount = (float)$loan['emi_amount'];

for ($i = 0; $i < $tenure; $i++) {
    $schedMonth = $startMonth + $i;
    $schedYear = $startYear;
    while ($schedMonth > 12) {
        $schedMonth -= 12;
        $schedYear++;
    }

    $key = $schedYear . '-' . str_pad($schedMonth, 2, '0', STR_PAD_LEFT);

    if (isset($paidEmis[$key])) {
        $log = $paidEmis[$key];
        $emiSchedule[] = [
            'month' => $schedMonth,
            'year' => $schedYear,
            'emi' => $log['emi_amount'],
            'principal' => $log['principal_component'],
            'interest' => $log['interest_component'],
            'balance' => $log['balance_after'],
            'status' => 'Paid',
            'paid_via_payroll' => $log['deducted_via_payroll'],
            'paid_date' => $log['created_at']
        ];
        $runningBalance = (float)$log['balance_after'];
    } else {
        // Determine status
        if ($schedYear < $currentYear || ($schedYear == $currentYear && $schedMonth < $currentMonth)) {
            $status = 'Pending';
        } elseif ($schedYear == $currentYear && $schedMonth == $currentMonth) {
            $status = 'Current';
        } else {
            $status = 'Future';
        }

        // Skip future months if loan is closed
        if ($loan['status'] !== 'Active' && $status === 'Future') {
            break;
        }

        // Calculate principal/interest for display
        if ($loan['interest_rate'] > 0 && $runningBalance > 0) {
            $monthlyRate = (float)$loan['interest_rate'] / 12 / 100;
            $interestComp = round($runningBalance * $monthlyRate, 2);
            $principalComp = round($emiAmount - $interestComp, 2);
        } else {
            $interestComp = 0;
            $principalComp = $emiAmount;
        }

        // Adjust last EMI
        if ($emiAmount > $runningBalance && $runningBalance > 0) {
            $emiAmount = $runningBalance;
            $principalComp = $emiAmount;
        }

        $emiSchedule[] = [
            'month' => $schedMonth,
            'year' => $schedYear,
            'emi' => $status === 'Future' ? $emiAmount : 0,
            'principal' => $principalComp,
            'interest' => $interestComp,
            'balance' => max(0, $runningBalance - ($status !== 'Future' ? 0 : $emiAmount)),
            'status' => $status,
            'paid_via_payroll' => false,
            'paid_date' => null
        ];

        if ($runningBalance > 0) {
            $runningBalance = max(0, $runningBalance - $emiAmount);
        }
    }

    if ($runningBalance <= 0 && $loan['status'] !== 'Active') {
        break;
    }
}

// Progress calculation
$totalPaid = 0;
$totalInterestPaid = 0;
$totalPrincipalPaid = 0;
foreach ($emiLog as $log) {
    $totalPaid += (float)$log['emi_amount'];
    $totalInterestPaid += (float)$log['interest_component'];
    $totalPrincipalPaid += (float)$log['principal_component'];
}
$progressPercent = $loan['total_repayable'] > 0
    ? min(100, round(($totalPaid / (float)$loan['total_repayable']) * 100, 1))
    : 100;

$statusClass = [
    'Active' => 'success',
    'Closed' => 'secondary',
    'Settled' => 'info',
    'Written Off' => 'warning'
];
$badgeClass = $statusClass[$loan['status']] ?? 'secondary';
?>

<!-- Print Styles -->
<?php
$extraCSS = <<<'CSS'
<style media="print">
    @media print {
        .no-print { display: none !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; }
        .table { font-size: 11px !important; }
        body { background: white !important; }
        .print-only { display: block !important; }
        .breadcrumb, .topbar { display: none !important; }
        #sidebar, .sidebar-overlay { display: none !important; }
        .main-content { margin-left: 0 !important; }
    }
    .print-only { display: none; }
</style>
CSS;
?>

<!-- Back Button & Actions -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <a href="index.php?page=loan/list" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Loans
    </a>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Download Statement
        </button>
        <?php if ($loan['status'] === 'Active'): ?>
        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#addAmountModal">
            <i class="bi bi-plus-circle me-1"></i>Add Amount
        </button>
        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#settleModal">
            <i class="bi bi-check-circle me-1"></i>Settle Loan
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Print Header -->
<div class="print-only text-center mb-4">
    <h4>RCS TRUE FACILITIES PVT LTD</h4>
    <h5>Employee Loan Statement</h5>
</div>

<!-- Loan Details Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0"><i class="bi bi-person-badge me-2"></i>Loan Details</h5>
            <span class="badge bg-light text-dark fs-6">Loan #<?php echo $loan['id']; ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <!-- Employee Info -->
            <div class="col-md-6">
                <h6 class="text-muted small fw-bold text-uppercase mb-3">Employee Information</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width:140px;">Employee</td>
                        <td class="fw-bold"><?php echo sanitize($loan['full_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Emp Code</td>
                        <td><code><?php echo sanitize($loan['employee_code']); ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Designation</td>
                        <td><?php echo sanitize($loan['designation'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Unit</td>
                        <td><?php echo sanitize($loan['unit_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Client</td>
                        <td><?php echo sanitize($loan['client_name'] ?? '-'); ?></td>
                    </tr>
                </table>
            </div>
            <!-- Loan Info -->
            <div class="col-md-6">
                <h6 class="text-muted small fw-bold text-uppercase mb-3">Loan Information</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width:140px;">Loan Type</td>
                        <td><span class="badge bg-light text-dark border"><?php echo sanitize($loan['loan_type']); ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Amount</td>
                        <td class="fw-bold"><?php echo formatCurrency($loan['amount']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Interest Rate</td>
                        <td><?php echo $loan['interest_rate'] > 0 ? $loan['interest_rate'] . '% p.a.' : 'No Interest'; ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Tenure</td>
                        <td><?php echo $loan['tenure_months']; ?> Months</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Monthly EMI</td>
                        <td class="fw-bold text-primary fs-5"><?php echo formatCurrency($loan['emi_amount']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Financial Summary Row -->
        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">Total Interest</small>
                    <div class="fw-bold text-warning"><?php echo formatCurrency($loan['total_interest']); ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">Total Repayable</small>
                    <div class="fw-bold"><?php echo formatCurrency($loan['total_repayable']); ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">Total Paid</small>
                    <div class="fw-bold text-success"><?php echo formatCurrency($totalPaid); ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">Balance</small>
                    <div class="fw-bold text-danger fs-5"><?php echo formatCurrency($loan['balance_amount']); ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">EMI Paid</small>
                    <div class="fw-bold"><?php echo $loan['emi_deducted']; ?> / <?php echo $loan['tenure_months']; ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="text-center p-2 bg-light rounded">
                    <small class="text-muted d-block">Status</small>
                    <span class="badge bg-<?php echo $badgeClass; ?> fs-6"><?php echo $loan['status']; ?></span>
                </div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="mt-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted">Repayment Progress</small>
                <small class="fw-bold"><?php echo $progressPercent; ?>%</small>
            </div>
            <div class="progress" style="height: 10px;">
                <div class="progress-bar bg-success" style="width: <?php echo $progressPercent; ?>%;">
                </div>
            </div>
        </div>

        <?php if ($loan['remarks']): ?>
        <div class="mt-3">
            <small class="text-muted">Remarks:</small>
            <span class="ms-2"><?php echo sanitize($loan['remarks']); ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- EMI Schedule -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>EMI Schedule</h5>
        <div class="no-print">
            <span class="badge bg-success me-1"><?php echo count($emiLog); ?> Paid</span>
            <span class="badge bg-danger"><?php echo ($loan['tenure_months'] - count($emiLog)); ?> Remaining</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                <thead class="table-light">
                    <tr>
                        <th class="text-center">#</th>
                        <th>Month/Year</th>
                        <th class="text-end">EMI</th>
                        <th class="text-end">Principal</th>
                        <th class="text-end">Interest</th>
                        <th class="text-end">Balance After</th>
                        <th class="text-center">Status</th>
                        <?php if ($loan['status'] === 'Active'): ?>
                        <th class="text-center no-print">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $emptyColspan = $loan['status'] === 'Active' ? 8 : 7;
                    if (empty($emiSchedule)): ?>
                    <tr>
                        <td colspan="<?php echo $emptyColspan; ?>" class="text-center py-4 text-muted">No schedule data available.</td>
                    </tr>
                    <?php else: ?>
                    <?php $sr = 1;
                    foreach ($emiSchedule as $sched): ?>
                    <tr class="<?php
                        if ($sched['status'] === 'Paid') echo 'table-success';
                        elseif ($sched['status'] === 'Pending') echo 'table-danger';
                        elseif ($sched['status'] === 'Current') echo 'table-info';
                    ?>">
                        <td class="text-center"><?php echo $sr++; ?></td>
                        <td>
                            <?php echo $monthNames[$sched['month']] . ' ' . $sched['year']; ?>
                            <?php if ($sched['paid_date']): ?>
                            <br><small class="text-muted"><?php echo date('d-m-Y', strtotime($sched['paid_date'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold"><?php echo formatCurrency($sched['emi']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($sched['principal']); ?></td>
                        <td class="text-end text-warning"><?php echo formatCurrency($sched['interest']); ?></td>
                        <td class="text-end fw-bold"><?php echo formatCurrency($sched['balance']); ?></td>
                        <td class="text-center">
                            <?php
                            $schedStatusClass = [
                                'Paid' => 'success',
                                'Pending' => 'danger',
                                'Current' => 'info',
                                'Future' => 'secondary'
                            ];
                            $scls = $schedStatusClass[$sched['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $scls; ?>">
                                <?php
                                if ($sched['status'] === 'Paid') {
                                    echo $sched['paid_via_payroll'] ? '<i class="bi bi-check-circle"></i> Paid' : '<i class="bi bi-hand-thumbs-up"></i> Manual';
                                } else {
                                    echo $sched['status'];
                                }
                                ?>
                            </span>
                        </td>
                        <?php if ($loan['status'] === 'Active'): ?>
                        <td class="text-center no-print">
                            <?php if (in_array($sched['status'], ['Pending', 'Current'])): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="record_payment">
                                <input type="hidden" name="month" value="<?php echo $sched['month']; ?>">
                                <input type="hidden" name="year" value="<?php echo $sched['year']; ?>">
                                <button type="submit" class="btn btn-success btn-sm" title="Record EMI for <?php echo $monthNames[$sched['month']] . ' ' . $sched['year']; ?>"
                                        onclick="return confirm('Record EMI of <?php echo formatCurrency($loan['emi_amount']); ?> for <?php echo $monthNames[$sched['month']] . ' ' . $sched['year']; ?>?')">
                                    <i class="bi bi-cash-coin me-1"></i>Record
                                </button>
                            </form>
                            <?php elseif ($sched['status'] === 'Paid'): ?>
                            <span class="text-success" title="Paid on <?php echo $sched['paid_date'] ? date('d-m-Y', strtotime($sched['paid_date'])) : 'N/A'; ?>">
                                <i class="bi bi-check-circle-fill"></i>
                            </span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($emiSchedule)): ?>
                <tfoot class="table-dark">
                    <tr class="fw-bold">
                        <td colspan="2">TOTAL</td>
                        <?php if ($loan['status'] === 'Active'): ?><td></td><?php endif; ?>
                        <td class="text-end"><?php echo formatCurrency($totalPaid); ?></td>
                        <td class="text-end"><?php echo formatCurrency($totalPrincipalPaid); ?></td>
                        <td class="text-end"><?php echo formatCurrency($totalInterestPaid); ?></td>
                        <td class="text-end"><?php echo formatCurrency($loan['balance_amount']); ?></td>
                        <td></td>
                        <?php if ($loan['status'] === 'Active'): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<?php if ($loan['status'] === 'Active'): ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Month</label>
                        <select class="form-select" name="month" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                                <?php echo $monthNamesFull[$m]; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Year</label>
                        <select class="form-select" name="year" required>
                            <?php for ($y = $currentYear; $y >= $startYear; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <small>EMI Amount: <strong><?php echo formatCurrency($loan['emi_amount']); ?></strong></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Settle Loan Modal -->
<div class="modal fade" id="settleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="settle_loan">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Settle Loan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to settle this loan?</p>
                    <div class="alert alert-warning py-2 mb-0">
                        <small>Outstanding Balance: <strong class="text-danger"><?php echo formatCurrency($loan['balance_amount']); ?></strong></small><br>
                        <small class="text-muted">The loan will be marked as Settled and closed.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-lg me-1"></i>Settle Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Amount Modal -->
<div class="modal fade" id="addAmountModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_amount">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Amount</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Increase the outstanding loan balance.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (₹)</label>
                        <input type="number" class="form-control" name="add_amount"
                               min="1" step="0.01" placeholder="Enter amount" required>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <small>Current Balance: <strong><?php echo formatCurrency($loan['balance_amount']); ?></strong></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Add Amount
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Floating Record Payment Button -->
<div class="position-fixed bottom-0 end-0 p-4 no-print" style="z-index: 1040;">
    <button type="button" class="btn btn-success btn-lg rounded-circle shadow" data-bs-toggle="modal" data-bs-target="#recordPaymentModal"
            title="Record Payment" style="width: 60px; height: 60px;">
        <i class="bi bi-cash-coin fs-4"></i>
    </button>
</div>
<?php endif; ?>
