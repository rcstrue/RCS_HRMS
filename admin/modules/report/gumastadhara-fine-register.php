<?php
/**
 * RCS HRMS Pro - Gumastadhara Fine Register (Form 1)
 * Register of Fines under the Bombay Shops and Establishments Act, 1948
 * Admin entry form + register view
 */

$pageTitle = 'Gumastadhara Fine Register - Form 1';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$deleteId = (int)($_GET['delete'] ?? 0);

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

// Auto-create fine_register table if not exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS fine_register (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        employee_id INT NOT NULL,
        fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        nature_of_fine VARCHAR(500),
        recovery_date DATE,
        remarks TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table may already exist with different syntax
}

// Fetch filter dropdowns
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

// Get employees for dropdown (filter by client/unit if selected)
try {
    $empWhere = "status IN ('approved', 'active')";
    $empParams = [];
    if ($clientFilter) { $empWhere .= " AND client_id = ?"; $empParams[] = $clientFilter; }
    if ($unitFilter) { $empWhere .= " AND unit_id = ?"; $empParams[] = $unitFilter; }
    $employees = $db->fetchAll(
        "SELECT id, employee_code, full_name, designation FROM employees WHERE $empWhere ORDER BY employee_code",
        $empParams
    );
} catch (Exception $e) {
    $employees = [];
}

// Handle POST - Add/Edit fine entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $fineDate = sanitize($_POST['fine_date'] ?? '');
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $fineAmount = floatval($_POST['fine_amount'] ?? 0);
        $natureOfFine = sanitize($_POST['nature_of_fine'] ?? '');
        $recoveryDate = sanitize($_POST['recovery_date'] ?? '');
        $remarks = sanitize($_POST['remarks'] ?? '');

        if ($employeeId > 0 && $fineAmount > 0 && !empty($fineDate)) {
            if ($_POST['action'] === 'add') {
                $db->query(
                    "INSERT INTO fine_register (date, employee_id, fine_amount, nature_of_fine, recovery_date, remarks)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$fineDate, $employeeId, $fineAmount, $natureOfFine, $recoveryDate ?: null, $remarks]
                );
                $successMsg = 'Fine entry added successfully.';
            } elseif ($_POST['action'] === 'edit' && !empty($_POST['edit_id'])) {
                $db->query(
                    "UPDATE fine_register SET date = ?, employee_id = ?, fine_amount = ?, nature_of_fine = ?, recovery_date = ?, remarks = ?
                     WHERE id = ?",
                    [$fineDate, $employeeId, $fineAmount, $natureOfFine, $recoveryDate ?: null, $remarks, (int)$_POST['edit_id']]
                );
                $successMsg = 'Fine entry updated successfully.';
            }
            // Redirect to avoid form resubmission
            header('Location: ?page=report/gumastadhara-fine-register&month=' . $month . '&year=' . $year
                 . ($clientFilter ? '&client_id=' . $clientFilter : '') 
                 . ($unitFilter ? '&unit_id=' . $unitFilter : '')
                 . '&msg=' . urlencode($successMsg));
            exit;
        } else {
            $errorMsg = 'Please fill all required fields (Date, Employee, Amount).';
        }
    } catch (Exception $e) {
        $errorMsg = 'Error saving fine entry: ' . $e->getMessage();
    }
}

// Handle delete
if ($deleteId > 0) {
    try {
        $db->query("DELETE FROM fine_register WHERE id = ?", [$deleteId]);
        header('Location: ?page=report/gumastadhara-fine-register&month=' . $month . '&year=' . $year
             . ($clientFilter ? '&client_id=' . $clientFilter : '')
             . ($unitFilter ? '&unit_id=' . $unitFilter : '')
             . '&msg=' . urlencode('Fine entry deleted.'));
        exit;
    } catch (Exception $e) {
        $errorMsg = 'Error deleting entry: ' . $e->getMessage();
    }
}

// Fetch fine entries
$where = "MONTH(fr.date) = :month AND YEAR(fr.date) = :year";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}
if ($unitFilter) {
    $where .= " AND e.unit_id = :uid";
    $params[':uid'] = $unitFilter;
}

try {
    $stmt = $db->prepare(
        "SELECT fr.id, fr.date, fr.employee_id, fr.fine_amount, fr.nature_of_fine,
                fr.recovery_date, fr.remarks, fr.created_at,
                e.employee_code, e.full_name, e.designation,
                c.name AS client_name, u.name AS unit_name
         FROM fine_register fr
         JOIN employees e ON fr.employee_id = e.id
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $where
         ORDER BY fr.date, e.employee_code"
    );
    $stmt->execute($params);
    $fines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fines = [];
}

// Fetch edit record
$editRecord = null;
if ($editId > 0) {
    try {
        $editRecord = $db->fetch(
            "SELECT fr.*, e.employee_code, e.full_name, e.designation
             FROM fine_register fr
             JOIN employees e ON fr.employee_id = e.id
             WHERE fr.id = ?",
            [$editId]
        );
    } catch (Exception $e) {}
}

// Calculate summary
$totalFines = 0;
$totalRecovered = 0;
foreach ($fines as $f) {
    $totalFines += floatval($f['fine_amount']);
    if (!empty($f['recovery_date'])) {
        $totalRecovered += floatval($f['fine_amount']);
    }
}

// Success message
$successMsg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Fine Register (Form 1)</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <i class="bi bi-check-circle me-1"></i><?php echo $successMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if (!empty($errorMsg ?? '')): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i><?php echo $errorMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/gumastadhara-fine-register">
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('M', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add/Edit Form -->
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0">
                    <i class="bi bi-plus-circle me-1"></i>
                    <?php echo $editRecord ? 'Edit Fine Entry' : 'Add New Fine Entry'; ?>
                    <?php if ($editRecord): ?>
                    <a href="?page=report/gumastadhara-fine-register&month=<?php echo $month; ?>&year=<?php echo $year; ?>"
                       class="btn btn-sm btn-outline-secondary float-end">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </a>
                    <?php endif; ?>
                </h6>
            </div>
            <div class="card-body py-2">
                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="<?php echo $editRecord ? 'edit' : 'add'; ?>">
                    <?php if ($editRecord): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editRecord['id']; ?>">
                    <?php endif; ?>

                    <div class="col-md-2">
                        <label class="form-label small">Date of Fine *</label>
                        <input type="date" name="fine_date" class="form-control form-control-sm"
                               value="<?php echo $editRecord ? $editRecord['date'] : date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Employee *</label>
                        <select name="employee_id" class="form-select form-select-sm" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"
                                <?php echo $editRecord && $editRecord['employee_id'] == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($emp['employee_code'] . ' - ' . $emp['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Nature of Fine / Offence *</label>
                        <input type="text" name="nature_of_fine" class="form-control form-control-sm"
                               placeholder="e.g. Late coming, Absent..."
                               value="<?php echo $editRecord ? sanitize($editRecord['nature_of_fine']) : ''; ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Amount (&#8377;) *</label>
                        <input type="number" name="fine_amount" class="form-control form-control-sm" step="0.01" min="0"
                               placeholder="0.00"
                               value="<?php echo $editRecord ? $editRecord['fine_amount'] : ''; ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">Recovery Date</label>
                        <input type="date" name="recovery_date" class="form-control form-control-sm"
                               value="<?php echo $editRecord ? $editRecord['recovery_date'] ?? '' : ''; ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">Remarks</label>
                        <input type="text" name="remarks" class="form-control form-control-sm"
                               value="<?php echo $editRecord ? sanitize($editRecord['remarks']) : ''; ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-<?php echo $editRecord ? 'warning' : 'primary'; ?> btn-sm w-100">
                            <i class="bi bi-<?php echo $editRecord ? 'pencil' : 'plus'; ?>"></i>
                            <?php echo $editRecord ? 'Update' : 'Add'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Entries</small>
                        <div class="h5 mb-0"><?php echo count($fines); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Fines (&#8377;)</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($totalFines); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Recovered (&#8377;)</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($totalRecovered); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Pending (&#8377;)</small>
                        <div class="h5 mb-0 text-warning"><?php echo formatCurrency($totalFines - $totalRecovered); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legal Header -->
        <div class="card mb-3">
            <div class="card-body text-center py-2">
                <h5 class="mb-1 fw-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                    Register of Fines Under the Bombay Shops and Establishments Act, 1948 (Form 1)
                </h5>
                <div class="row text-start small" style="font-size:0.8rem;">
                    <div class="col-md-4">
                        <strong>Period:</strong> <?php echo $monthName . ' ' . $year; ?>
                    </div>
                    <?php if ($clientFilter && !empty($fines)): ?>
                    <div class="col-md-4">
                        <strong>Client:</strong> <?php echo sanitize($fines[0]['client_name'] ?? ''); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Unit:</strong> <?php echo sanitize($fines[0]['unit_name'] ?? 'All'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fine Register Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:30px;">#</th>
                                <th style="width:70px;">Date</th>
                                <th style="width:50px;">Emp Code</th>
                                <th style="min-width:100px;">Name</th>
                                <th style="min-width:80px;">Designation</th>
                                <th style="min-width:120px;">Nature of Fine / Offence</th>
                                <th class="text-end" style="width:70px;">Amount (&#8377;)</th>
                                <th style="width:70px;">Recovery Date</th>
                                <th style="width:40px;">Status</th>
                                <th style="min-width:80px;">Remarks</th>
                                <th class="no-print" style="width:60px;">Signature</th>
                                <th class="no-print" style="width:80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fines)): ?>
                            <tr>
                                <td colspan="12" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    No fine entries found for the selected period.
                                </td>
                            </tr>
                            <?php else: $i = 0;
                            foreach ($fines as $f):
                                $i++;
                                $isRecovered = !empty($f['recovery_date']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i; ?></td>
                                <td class="text-center"><?php echo formatDate($f['date']); ?></td>
                                <td><code><?php echo sanitize($f['employee_code']); ?></code></td>
                                <td><?php echo sanitize($f['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($f['designation']); ?></td>
                                <td><?php echo sanitize($f['nature_of_fine']); ?></td>
                                <td class="text-end fw-bold" style="background:#fde8e8;">
                                    <?php echo number_format(floatval($f['fine_amount']), 2); ?>
                                </td>
                                <td class="text-center">
                                    <?php echo $f['recovery_date'] ? formatDate($f['recovery_date']) : '<span class="text-danger small">Pending</span>'; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isRecovered): ?>
                                    <span class="badge bg-success">Recovered</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo sanitize($f['remarks'] ?? ''); ?></td>
                                <td></td>
                                <td class="no-print">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=report/gumastadhara-fine-register&month=<?php echo $month; ?>&year=<?php echo $year; ?>&edit=<?php echo $f['id']; ?>"
                                           class="btn btn-outline-warning" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?page=report/gumastadhara-fine-register&month=<?php echo $month; ?>&year=<?php echo $year; ?>&delete=<?php echo $f['id']; ?>"
                                           class="btn btn-outline-danger" title="Delete"
                                           onclick="return confirm('Are you sure you want to delete this fine entry?');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($fines)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="6" class="text-end"><strong>TOTAL (<?php echo count($fines); ?> Entries)</strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalFines); ?></strong></td>
                                <td colspan="2">
                                    <strong>Recovered: <?php echo formatCurrency($totalRecovered); ?></strong>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Legal Compliance Note -->
        <div class="card mt-3">
            <div class="card-body py-2">
                <div class="row small">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Legal Compliance Notes:</strong></p>
                        <ul class="mb-0 text-muted" style="font-size:0.75rem;">
                            <li>As per Section 31 of the Bombay S&E Act, no fine shall exceed 3% of total wages in a wage period.</li>
                            <li>Fines must be imposed for acts/omissions specified in the establishment rules.</li>
                            <li>No fine shall be recovered in instalments exceeding 6 in number.</li>
                            <li>All fines must be recorded in this register and the amount recovered shall be utilized for the benefit of the persons employed.</li>
                        </ul>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1"><strong>Signature of Employer</strong></p>
                        <div style="border-bottom:1px solid #000; width:200px; margin-left:auto; height:40px;"></div>
                        <p class="small text-muted mb-0 mt-1">Name: ________________</p>
                        <p class="small text-muted mb-0">Date: ________________</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header, .no-print, .alert { display: none !important; }
    body { font-size: 9pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: 1px solid #000 !important; page-break-inside: avoid; }
    .card-body { padding: 6px !important; }
}
</style>
