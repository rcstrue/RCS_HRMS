<?php
/**
 * RCS HRMS Pro - Employee Loans Listing
 */

$pageTitle = 'Employee Loans';

// Ensure tables exist
$loanClass = new Loan();
$loanClass->ensureTables();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create new loan
    if ($action === 'create_loan') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $loanType = sanitize($_POST['loan_type'] ?? 'Personal');
        $amount = (float)($_POST['amount'] ?? 0);
        $interestRate = (float)($_POST['interest_rate'] ?? 0);
        $tenureMonths = (int)($_POST['tenure_months'] ?? 0);
        $startMonth = (int)($_POST['start_month'] ?? prev_month_num());
        $startYear = (int)($_POST['start_year'] ?? date('Y'));
        $remarks = sanitize($_POST['remarks'] ?? '');

        if ($employeeId <= 0 || $amount <= 0 || $tenureMonths <= 0) {
            setFlash('error', 'Please fill all required fields with valid values.');
        } else {
            $empData = $db->fetch("SELECT unit_id FROM employees WHERE id = ?", [$employeeId]);
            $unitId = $empData ? $empData['unit_id'] : null;

            $result = $loanClass->create([
                'employee_id' => $employeeId,
                'unit_id' => $unitId,
                'loan_type' => $loanType,
                'amount' => $amount,
                'interest_rate' => $interestRate,
                'tenure_months' => $tenureMonths,
                'start_month' => $startMonth,
                'start_year' => $startYear,
                'remarks' => $remarks
            ]);

            if ($result['success']) {
                setFlash('success', $result['message'] . ' EMI: ' . formatCurrency($result['emi']));
            } else {
                setFlash('error', $result['message']);
            }
        }
        redirect('index.php?page=loan/list&client_id=' . ((int)($_POST['filter_client_id'] ?? 0)) . '&unit_id=' . ((int)($_POST['filter_unit_id'] ?? 0)));
    }

    // Settle loan
    if ($action === 'settle_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $result = $loanClass->settleLoan($loanId);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=loan/list&client_id=' . ((int)($_POST['filter_client_id'] ?? 0)) . '&unit_id=' . ((int)($_POST['filter_unit_id'] ?? 0)));
    }

    // Delete loan
    if ($action === 'delete_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $result = $loanClass->delete($loanId);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=loan/list&client_id=' . ((int)($_POST['filter_client_id'] ?? 0)) . '&unit_id=' . ((int)($_POST['filter_unit_id'] ?? 0)));
    }
}

// ── Filters (same pattern as id-card page) ──
// GET params first, fallback to session header filter
$selectedClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : (($_SESSION['filter_client_id'] ?? 0) ?: 0);
$selectedUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : (($_SESSION['filter_unit_id'] ?? 0) ?: 0);

// Load clients
$clients = [];
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
} catch (Exception $e) {}

// Load units for selected client
$units = [];
if ($selectedClient) {
    $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$selectedClient]);
}

// Load employees for Add Loan modal
$unitEmployees = [];
if ($selectedUnit) {
    $unitEmployees = $db->fetchAll(
        "SELECT id, employee_code, full_name FROM employees WHERE status = 'approved' AND unit_id = ? ORDER BY employee_code",
        [$selectedUnit]
    );
}

// Get filtered loans
$loanFilters = [];
if ($selectedClient) $loanFilters['client_id'] = $selectedClient;
if ($selectedUnit) $loanFilters['unit_id'] = $selectedUnit;
$loans = $loanClass->getAll($loanFilters);

// Summary for selected scope
$summaryFilters = [];
if ($selectedClient) $summaryFilters['client_id'] = $selectedClient;
if ($selectedUnit) $summaryFilters['unit_id'] = $selectedUnit;
$summaryData = $loanClass->getAll($summaryFilters);
$summaryActive = 0;
$summaryOutstanding = 0;
$summaryThisMonthEmi = 0;
foreach ($summaryData as $s) {
    if ($s['status'] === 'Active') {
        $summaryActive++;
        $summaryOutstanding += (float)$s['balance_amount'];
    }
    if ($s['status'] === 'Active' && $s['emi_amount'] > 0) {
        $summaryThisMonthEmi += (float)$s['emi_amount'];
    }
}

// Month/Year for EMI preview
$currentMonth = prev_month_num();
$currentYear = date('Y');
$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <div class="row align-items-center g-2">
                    <div class="col-md">
                        <h5 class="card-title mb-0"><i class="bi bi-bank me-2"></i>Employee Loans</h5>
                    </div>
                    <div class="col-md-auto">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLoanModal"
                                <?php echo (!$selectedUnit) ? 'disabled title="Select a Unit first"' : ''; ?>>
                            <i class="bi bi-plus-lg me-1"></i>Add Loan
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Filter Row (same pattern as id-card page) -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="loan/list">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="filterClient">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="filterUnit">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="btn-group w-100">
                            <a href="index.php?page=loan/list" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                        <i class="bi bi-bank2 fs-5 text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Active Loans</div>
                                        <div class="fs-4 fw-bold text-primary"><?php echo $summaryActive; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                        <i class="bi bi-cash-stack fs-5 text-warning"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Total Outstanding</div>
                                        <div class="fs-4 fw-bold text-warning"><?php echo formatCurrency($summaryOutstanding); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                        <i class="bi bi-calendar-check fs-5 text-success"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">This Month EMI</div>
                                        <div class="fs-4 fw-bold text-success"><?php echo formatCurrency($summaryThisMonthEmi); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loans Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="loansTable" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Unit</th>
                                <th>Loan Type</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Interest%</th>
                                <th>Tenure</th>
                                <th class="text-end">EMI</th>
                                <th class="text-end">Balance</th>
                                <th>Start</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loans)): ?>
                            <tr>
                                <td colspan="13" class="text-center py-5 text-muted">
                                    <i class="bi bi-bank fs-1 d-block mb-2"></i>
                                    <?php echo $selectedClient ? 'No loans found for selected filters.' : 'Select a Client to view loans.'; ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php $sr = 1;
                            foreach ($loans as $loan): ?>
                            <tr>
                                <td><?php echo $sr++; ?></td>
                                <td><code><?php echo sanitize($loan['employee_code']); ?></code></td>
                                <td><?php echo sanitize($loan['full_name']); ?></td>
                                <td><small><?php echo sanitize($loan['unit_name'] ?? '-'); ?></small></td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo sanitize($loan['loan_type']); ?></span>
                                </td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($loan['amount']); ?></td>
                                <td class="text-end"><?php echo $loan['interest_rate'] > 0 ? $loan['interest_rate'] . '%' : '-'; ?></td>
                                <td class="text-center"><?php echo $loan['tenure_months']; ?> mo</td>
                                <td class="text-end"><?php echo formatCurrency($loan['emi_amount']); ?></td>
                                <td class="text-end fw-bold <?php echo (float)$loan['balance_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatCurrency($loan['balance_amount']); ?>
                                </td>
                                <td>
                                    <small><?php echo $monthNames[$loan['start_month']] . ' ' . $loan['start_year']; ?></small>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'Active' => 'success',
                                        'Closed' => 'secondary',
                                        'Settled' => 'info',
                                        'Written Off' => 'warning'
                                    ];
                                    $cls = $statusClass[$loan['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $cls; ?>"><?php echo $loan['status']; ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="index.php?page=loan/view&id=<?php echo $loan['id']; ?>"
                                           class="btn btn-outline-primary" title="View Statement">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($loan['status'] === 'Active'): ?>
                                        <button type="button" class="btn btn-outline-warning"
                                                onclick="confirmSettle(<?php echo $loan['id']; ?>, '<?php echo sanitize($loan['full_name']); ?>')"
                                                title="Settle Loan">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-danger"
                                                onclick="confirmDelete(<?php echo $loan['id']; ?>, '<?php echo sanitize($loan['full_name']); ?>')"
                                                title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Loan Modal -->
<div class="modal fade" id="addLoanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addLoanForm">
                <input type="hidden" name="action" value="create_loan">
                <input type="hidden" name="filter_client_id" value="<?php echo $selectedClient; ?>">
                <input type="hidden" name="filter_unit_id" value="<?php echo $selectedUnit; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Employee Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" name="employee_id" id="loanEmployeeSelect" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($unitEmployees as $e): ?>
                                <option value="<?php echo $e['id']; ?>">
                                    <?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">From <?php echo sanitize($units[$selectedUnit - 1]['name'] ?? 'selected unit'); ?> (<?php echo count($unitEmployees); ?> employees)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Loan Type</label>
                            <select class="form-select" name="loan_type" id="loanType">
                                <option value="Personal">Personal</option>
                                <option value="Emergency">Emergency</option>
                                <option value="Housing">Housing</option>
                                <option value="Festival">Festival</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="loanAmount"
                                   min="1" step="0.01" placeholder="e.g. 50000" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Interest Rate (%)</label>
                            <input type="number" class="form-control" name="interest_rate" id="loanInterestRate"
                                   min="0" max="50" step="0.01" value="0" placeholder="0 for no interest">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tenure (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="tenure_months" id="loanTenure"
                                   min="1" max="360" step="1" placeholder="e.g. 12" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start Month</label>
                            <select class="form-select" name="start_month" id="loanStartMonth">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                                    <?php echo $monthNames[$m]; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start Year</label>
                            <select class="form-select" name="start_year" id="loanStartYear">
                                <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Remarks</label>
                            <input type="text" class="form-control" name="remarks" placeholder="Optional remarks">
                        </div>

                        <!-- EMI Preview -->
                        <div class="col-12">
                            <div class="alert alert-info mb-0" id="emiPreview" style="display:none;">
                                <h6 class="alert-heading"><i class="bi bi-calculator me-2"></i>EMI Calculation Preview</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <small class="text-muted">Monthly EMI</small>
                                        <div class="fs-5 fw-bold text-primary" id="previewEmi">₹0.00</div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">Total Interest</small>
                                        <div class="fs-5 fw-bold text-warning" id="previewInterest">₹0.00</div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">Total Repayable</small>
                                        <div class="fs-5 fw-bold text-success" id="previewTotal">₹0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitLoanBtn">
                        <i class="bi bi-check-lg me-1"></i>Create Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Settle Loan Modal -->
<div class="modal fade" id="settleLoanModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="settle_loan">
                <input type="hidden" name="loan_id" id="settleLoanId">
                <input type="hidden" name="filter_client_id" value="<?php echo $selectedClient; ?>">
                <input type="hidden" name="filter_unit_id" value="<?php echo $selectedUnit; ?>">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Settle Loan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Settle loan for <strong id="settleLoanName"></strong>?</p>
                    <p class="text-muted small mb-0">This will mark the loan as settled and close it.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-lg me-1"></i>Settle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Loan Modal -->
<div class="modal fade" id="deleteLoanModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete_loan">
                <input type="hidden" name="loan_id" id="deleteLoanId">
                <input type="hidden" name="filter_client_id" value="<?php echo $selectedClient; ?>">
                <input type="hidden" name="filter_unit_id" value="<?php echo $selectedUnit; ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Delete loan for <strong id="deleteLoanName" class="text-danger"></strong>?</p>
                    <p class="text-muted small mb-0">Only closed/settled loans can be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Page Filter: Client → Unit cascade (same pattern as id-card page) ──
document.getElementById('filterClient').addEventListener('change', function() {
    var clientId = this.value;
    var unitSelect = document.getElementById('filterUnit');
    unitSelect.innerHTML = '<option value="">Loading...</option>';

    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        document.getElementById('filterForm').submit();
        return;
    }

    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '<option value="">All Units</option>';
            (data.units || []).forEach(function(u) {
                html += '<option value="' + u.id + '">' + u.name + '</option>';
            });
            unitSelect.innerHTML = html;
            document.getElementById('filterForm').submit();
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
});

document.getElementById('filterUnit').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

// ── DataTable (only init when actual data rows exist) ──
jQuery(document).ready(function($) {
    var $firstRow = $('#loansTable tbody tr:first');
    var hasDataRows = $firstRow.length > 0 && $firstRow.find('td').not('[colspan]').length > 0;

    if (hasDataRows) {
        $('#loansTable').DataTable({
            responsive: false,
            retrieve: true,
            pageLength: 25,
            order: [],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: '',
                searchPlaceholder: 'Search loans...',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ loans',
                paginate: {
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [12] },
                { className: 'text-nowrap', targets: [12] }
            ]
        });
    }

    // ── EMI Preview Calculator ──
    function calculateEmiPreview() {
        var amount = parseFloat($('#loanAmount').val()) || 0;
        var rate = parseFloat($('#loanInterestRate').val()) || 0;
        var tenure = parseInt($('#loanTenure').val()) || 0;

        if (amount > 0 && tenure > 0) {
            var emi, totalInterest, totalRepayable;
            if (rate <= 0) {
                emi = amount / tenure;
                totalInterest = 0;
                totalRepayable = amount;
            } else {
                var monthlyRate = rate / 12 / 100;
                var onePlusR = 1 + monthlyRate;
                var power = Math.pow(onePlusR, tenure);
                emi = amount * monthlyRate * power / (power - 1);
                totalRepayable = emi * tenure;
                totalInterest = totalRepayable - amount;
            }
            $('#previewEmi').text('₹' + emi.toFixed(2));
            $('#previewInterest').text('₹' + totalInterest.toFixed(2));
            $('#previewTotal').text('₹' + totalRepayable.toFixed(2));
            $('#emiPreview').slideDown();
        } else {
            $('#emiPreview').slideUp();
        }
    }

    $('#loanAmount, #loanInterestRate, #loanTenure').on('input', calculateEmiPreview);

    // ── Settle / Delete Modals ──
    window.confirmSettle = function(loanId, name) {
        $('#settleLoanId').val(loanId);
        $('#settleLoanName').text(name);
        new bootstrap.Modal(document.getElementById('settleLoanModal')).show();
    };

    window.confirmDelete = function(loanId, name) {
        $('#deleteLoanId').val(loanId);
        $('#deleteLoanName').text(name);
        new bootstrap.Modal(document.getElementById('deleteLoanModal')).show();
    };

    // ── Reset form on modal close ──
    $('#addLoanModal').on('hidden.bs.modal', function() {
        document.getElementById('addLoanForm').reset();
        $('#emiPreview').slideUp();
    });
});
</script>
