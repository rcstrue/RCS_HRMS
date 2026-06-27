<?php
/**
 * RCS HRMS Pro — Form XXI: Register of Deductions
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Register of all deductions made from wages of contract workers
 */
$pageTitle = 'Form XXI - Register of Deductions';

// ── Auto-create table ───────────────────────────────────────────────
try {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS deductions_register (
        id INT AUTO_INCREMENT PRIMARY KEY,
        deduction_date DATE NOT NULL,
        employee_id INT,
        employee_code VARCHAR(100) DEFAULT '',
        employee_name VARCHAR(255) DEFAULT '',
        nature_of_deduction VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        authority VARCHAR(255) DEFAULT '',
        recovery_date DATE,
        remarks TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    die('Table creation failed: ' . $e->getMessage());
}

// ── Fetch employees for dropdown ────────────────────────────────────
$employees = [];
try {
    $stmt = $db->query("SELECT id, employee_code, full_name FROM employees ORDER BY employee_code ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

// ── Handle POST ─────────────────────────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        try {
            $id                 = intval($_POST['id'] ?? 0);
            $deductionDate      = sanitize($_POST['deduction_date'] ?? '');
            $employeeId         = intval($_POST['employee_id'] ?? 0);
            $natureOfDeduction  = sanitize($_POST['nature_of_deduction'] ?? '');
            $amount             = floatval($_POST['amount'] ?? 0);
            $authority          = sanitize($_POST['authority'] ?? '');
            $recoveryDate       = sanitize($_POST['recovery_date'] ?? '');
            $remarks            = sanitize($_POST['remarks'] ?? '');

            if (empty($deductionDate)) throw new Exception('Deduction date is required.');
            if (empty($natureOfDeduction)) throw new Exception('Nature of deduction is required.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            // Resolve employee code & name
            $empCode = '';
            $empName = '';
            if ($employeeId > 0) {
                $stmt = $db->prepare("SELECT employee_code, full_name FROM employees WHERE id = ?");
                $stmt->execute([$employeeId]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($emp) { $empCode = $emp['employee_code']; $empName = $emp['full_name']; }
            }

            if ($action === 'edit' && $id > 0) {
                $stmt = $db->prepare("UPDATE deductions_register SET
                    deduction_date=?, employee_id=?, employee_code=?, employee_name=?,
                    nature_of_deduction=?, amount=?, authority=?,
                    recovery_date=?, remarks=?, updated_at=CURRENT_TIMESTAMP
                    WHERE id=?");
                $stmt->execute([$deductionDate, $employeeId ?: null, $empCode, $empName,
                    $natureOfDeduction, $amount, $authority,
                    $recoveryDate ?: null, $remarks, $id]);
                $message = 'Deduction record updated successfully.';
            } else {
                $stmt = $db->prepare("INSERT INTO deductions_register
                    (deduction_date, employee_id, employee_code, employee_name,
                     nature_of_deduction, amount, authority, recovery_date, remarks)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$deductionDate, $employeeId ?: null, $empCode, $empName,
                    $natureOfDeduction, $amount, $authority,
                    $recoveryDate ?: null, $remarks]);
                $message = 'Deduction record added successfully.';
            }
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    if ($action === 'delete') {
        try {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM deductions_register WHERE id=?");
                $stmt->execute([$id]);
                $message = 'Deduction record deleted.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Delete failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ── Fetch records ───────────────────────────────────────────────────
$deductions = [];
$totalAmount = 0;

try {
    $stmt = $db->query("SELECT * FROM deductions_register ORDER BY deduction_date DESC, id DESC");
    $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($deductions as $d) $totalAmount += floatval($d['amount']);
} catch (Exception $e) {
    $message = 'Fetch failed: ' . $e->getMessage();
    $messageType = 'danger';
}

// ── Edit record ─────────────────────────────────────────────────────
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM deductions_register WHERE id=?");
        $stmt->execute([$editId]);
        $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_XXI_Register_of_Deductions_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sl No', 'Date', 'Emp Code', 'Name', 'Nature of Deduction',
        'Amount', 'Authority', 'Recovery Date', 'Remarks']);
    $sl = 1;
    foreach ($deductions as $d) {
        fputcsv($output, [$sl++, formatDate($d['deduction_date']), $d['employee_code'],
            $d['employee_name'], $d['nature_of_deduction'], formatCurrency($d['amount']),
            $d['authority'], formatDate($d['recovery_date']), $d['remarks']]);
    }
    fputcsv($output, ['', '', '', '', 'TOTAL', formatCurrency($totalAmount)]);
    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-dash-circle me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rule 81</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?page=forms/labour/form-xxi&export=csv" class="btn btn-outline-success btn-sm">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Form -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="bi bi-plus-circle me-1"></i>
                <?= $editRecord ? 'Edit Deduction Entry' : 'Add New Deduction' ?></h6>
        </div>
        <div class="card-body">
            <form method="POST" action="?page=forms/labour/form-xxi">
                <input type="hidden" name="action" value="<?= $editRecord ? 'edit' : 'add' ?>">
                <?php if ($editRecord): ?>
                    <input type="hidden" name="id" value="<?= $editRecord['id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Deduction Date <span class="text-danger">*</span></label>
                        <input type="date" name="deduction_date" class="form-control form-control-sm" required
                               value="<?= htmlspecialchars($editRecord['deduction_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employee</label>
                        <select name="employee_id" class="form-select form-select-sm">
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"
                                    <?= ($editRecord && $editRecord['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nature of Deduction <span class="text-danger">*</span></label>
                        <select name="nature_of_deduction" class="form-select form-control-sm"
                                onchange="if(this.value==='other'){document.getElementById('custom_nature').style.display='block';this.style.display='none'}">
                            <option value="">-- Select --</option>
                            <option value="PF" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'PF') ? 'selected' : '' ?>>Provident Fund</option>
                            <option value="ESI" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'ESI') ? 'selected' : '' ?>>ESI Contribution</option>
                            <option value="PT" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'PT') ? 'selected' : '' ?>>Professional Tax</option>
                            <option value="TDS" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'TDS') ? 'selected' : '' ?>>TDS</option>
                            <option value="Advance" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'Advance') ? 'selected' : '' ?>>Advance Recovery</option>
                            <option value="Absence" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'Absence') ? 'selected' : '' ?>>Absence / Leave Without Pay</option>
                            <option value="Damage" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'Damage') ? 'selected' : '' ?>>Damage / Loss Recovery</option>
                            <option value="Fine" <?= ($editRecord && $editRecord['nature_of_deduction'] === 'Fine') ? 'selected' : '' ?>>Fine / Penalty</option>
                            <option value="other" <?= ($editRecord && !in_array($editRecord['nature_of_deduction'], ['PF','ESI','PT','TDS','Advance','Absence','Damage','Fine'])) ? 'selected' : '' ?>>Other (Specify)</option>
                        </select>
                        <input type="text" id="custom_nature" name="nature_of_deduction" class="form-control form-control-sm mt-1"
                               placeholder="Enter nature..."
                               style="display:<?= ($editRecord && !in_array($editRecord['nature_of_deduction'] ?? '', ['PF','ESI','PT','TDS','Advance','Absence','Damage','Fine'])) ? 'block' : 'none' ?>"
                               value="<?= htmlspecialchars($editRecord['nature_of_deduction'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control form-control-sm"
                               step="0.01" min="0.01" required
                               value="<?= htmlspecialchars($editRecord['amount'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Recovery Date</label>
                        <input type="date" name="recovery_date" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['recovery_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Authority</label>
                        <input type="text" name="authority" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['authority'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['remarks'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i><?= $editRecord ? 'Update' : 'Add Entry' ?>
                    </button>
                    <?php if ($editRecord): ?>
                        <a href="?page=forms/labour/form-xxi" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Deductions Register Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Register of Deductions
                <span class="badge bg-secondary ms-2"><?= count($deductions) ?></span></h6>
            <span class="text-muted">Total: <strong><?= formatCurrency($totalAmount) ?></strong></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th style="width:45px" class="text-center">Sl No</th>
                            <th style="width:90px">Date</th>
                            <th style="width:75px">Emp Code</th>
                            <th>Employee Name</th>
                            <th style="width:160px">Nature of Deduction</th>
                            <th style="width:90px" class="text-end">Amount</th>
                            <th style="width:120px">Authority</th>
                            <th style="width:90px">Recovery Date</th>
                            <th>Remarks</th>
                            <th style="width:90px" class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deductions)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">No deduction records found.</td></tr>
                        <?php else: $sl = 1; foreach ($deductions as $d): ?>
                            <tr>
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><?= formatDate($d['deduction_date']) ?></td>
                                <td><?= htmlspecialchars($d['employee_code']) ?></td>
                                <td><?= htmlspecialchars($d['employee_name']) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = match($d['nature_of_deduction']) {
                                        'PF', 'ESI', 'PT' => 'bg-info',
                                        'TDS' => 'bg-warning text-dark',
                                        'Advance' => 'bg-success',
                                        'Fine' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($d['nature_of_deduction']) ?></span>
                                </td>
                                <td class="text-end fw-semibold"><?= formatCurrency($d['amount']) ?></td>
                                <td><?= htmlspecialchars($d['authority']) ?></td>
                                <td><?= formatDate($d['recovery_date']) ?></td>
                                <td class="small"><?= htmlspecialchars($d['remarks']) ?></td>
                                <td class="no-print">
                                    <a href="?page=forms/labour/form-xxi&edit=<?= $d['id'] ?>"
                                       class="btn btn-outline-primary btn-xs py-0 px-1" title="Edit">
                                        <i class="bi bi-pencil-square"></i></a>
                                    <form method="POST" action="?page=forms/labour/form-xxi" class="d-inline"
                                          onsubmit="return confirm('Delete this deduction record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-xs py-0 px-1" title="Delete">
                                            <i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="table-warning fw-bold">
                                <td colspan="5" class="text-end">Grand Total</td>
                                <td class="text-end"><?= formatCurrency($totalAmount) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .badge { border: 1px solid #999 !important; }
    body { font-size: 10px; }
    .table { font-size: 9px; }
    .container-fluid { padding: 0 !important; }
}
</style>
