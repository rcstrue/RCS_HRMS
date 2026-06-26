<?php
/**
 * RCS HRMS Pro — Form 24: Register of Contractors
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Register of Contractors at an Establishment
 */
$pageTitle = 'Form 24 - Register of Contractors';

// ── Auto-create table if not exists ──────────────────────────────────
try {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS contractors_register (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contractor_name VARCHAR(255) NOT NULL,
        registration_number VARCHAR(100) DEFAULT '',
        nature_of_work VARCHAR(255) DEFAULT '',
        total_workers INT DEFAULT 0,
        license_valid_from DATE,
        license_valid_to DATE,
        license_fee DECIMAL(12,2) DEFAULT 0,
        remarks TEXT DEFAULT '',
        status VARCHAR(50) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    die('Table creation failed: ' . $e->getMessage());
}

// ── Handle POST actions ─────────────────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        try {
            $id              = intval($_POST['id'] ?? 0);
            $contractorName  = sanitize($_POST['contractor_name'] ?? '');
            $regNumber       = sanitize($_POST['registration_number'] ?? '');
            $natureOfWork    = sanitize($_POST['nature_of_work'] ?? '');
            $totalWorkers    = intval($_POST['total_workers'] ?? 0);
            $validFrom       = sanitize($_POST['license_valid_from'] ?? '');
            $validTo         = sanitize($_POST['license_valid_to'] ?? '');
            $licenseFee      = floatval($_POST['license_fee'] ?? 0);
            $remarks         = sanitize($_POST['remarks'] ?? '');

            if (empty($contractorName)) {
                throw new Exception('Contractor name is required.');
            }

            if ($action === 'edit' && $id > 0) {
                $stmt = $db->prepare("UPDATE contractors_register SET
                    contractor_name=?, registration_number=?, nature_of_work=?,
                    total_workers=?, license_valid_from=?, license_valid_to=?,
                    license_fee=?, remarks=?, updated_at=CURRENT_TIMESTAMP
                    WHERE id=?");
                $stmt->execute([$contractorName, $regNumber, $natureOfWork,
                    $totalWorkers, $validFrom ?: null, $validTo ?: null,
                    $licenseFee, $remarks, $id]);
                $message = 'Contractor record updated successfully.';
            } else {
                $stmt = $db->prepare("INSERT INTO contractors_register
                    (contractor_name, registration_number, nature_of_work,
                     total_workers, license_valid_from, license_valid_to,
                     license_fee, remarks)
                    VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$contractorName, $regNumber, $natureOfWork,
                    $totalWorkers, $validFrom ?: null, $validTo ?: null,
                    $licenseFee, $remarks]);
                $message = 'Contractor added successfully.';
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
                $stmt = $db->prepare("DELETE FROM contractors_register WHERE id=?");
                $stmt->execute([$id]);
                $message = 'Contractor record deleted.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Delete failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ── Fetch records ───────────────────────────────────────────────────
$contractors = [];
try {
    $stmt = $db->query("SELECT * FROM contractors_register ORDER BY id ASC");
    $contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = 'Fetch failed: ' . $e->getMessage();
    $messageType = 'danger';
}

// ── Edit record (pre-fill form) ─────────────────────────────────────
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM contractors_register WHERE id=?");
        $stmt->execute([$editId]);
        $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_24_Register_of_Contractors_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sl No', 'Name of Contractor', 'Registration No', 'Nature of Work',
        'No. of Workers', 'License Valid From', 'License Valid To',
        'License Fee', 'Remarks']);
    $sl = 1;
    foreach ($contractors as $c) {
        fputcsv($output, [$sl++, $c['contractor_name'], $c['registration_number'],
            $c['nature_of_work'], $c['total_workers'],
            formatDate($c['license_valid_from']), formatDate($c['license_valid_to']),
            formatCurrency($c['license_fee']), $c['remarks']]);
    }
    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-building me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (Regulation & Abolition) Act, 1970 — Rule 75</small>
        </div>
        <div class="d-flex gap-2">
            <a href="?page=forms/labour/form-24&export=csv" class="btn btn-outline-success btn-sm">
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
                <?= $editRecord ? 'Edit Contractor' : 'Add New Contractor' ?></h6>
        </div>
        <div class="card-body">
            <form method="POST" action="?page=forms/labour/form-24">
                <input type="hidden" name="action" value="<?= $editRecord ? 'edit' : 'add' ?>">
                <?php if ($editRecord): ?>
                    <input type="hidden" name="id" value="<?= $editRecord['id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Contractor Name <span class="text-danger">*</span></label>
                        <input type="text" name="contractor_name" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['contractor_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Registration No</label>
                        <input type="text" name="registration_number" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['registration_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Nature of Work</label>
                        <input type="text" name="nature_of_work" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['nature_of_work'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">No. of Workers</label>
                        <input type="number" name="total_workers" class="form-control form-control-sm" min="0"
                               value="<?= htmlspecialchars($editRecord['total_workers'] ?? '0') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">License Valid From</label>
                        <input type="date" name="license_valid_from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['license_valid_from'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">License Valid To</label>
                        <input type="date" name="license_valid_to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['license_valid_to'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">License Fee</label>
                        <input type="number" name="license_fee" class="form-control form-control-sm"
                               step="0.01" min="0"
                               value="<?= htmlspecialchars($editRecord['license_fee'] ?? '0') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['remarks'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i><?= $editRecord ? 'Update' : 'Add Contractor' ?>
                    </button>
                    <?php if ($editRecord): ?>
                        <a href="?page=forms/labour/form-24" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Register Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Register of Contractors
                <span class="badge bg-secondary ms-2"><?= count($contractors) ?></span></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">Sl No</th>
                            <th>Name of Contractor</th>
                            <th>Registration No</th>
                            <th>Nature of Work</th>
                            <th class="text-center">Workers</th>
                            <th>Valid From</th>
                            <th>Valid To</th>
                            <th class="text-end">License Fee</th>
                            <th>Remarks</th>
                            <th style="width:100px" class="print-hide">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contractors)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">No contractors found.</td></tr>
                        <?php else: $sl = 1; foreach ($contractors as $c): ?>
                            <tr>
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><strong><?= htmlspecialchars($c['contractor_name']) ?></strong></td>
                                <td><?= htmlspecialchars($c['registration_number']) ?></td>
                                <td><?= htmlspecialchars($c['nature_of_work']) ?></td>
                                <td class="text-center"><?= intval($c['total_workers']) ?></td>
                                <td><?= formatDate($c['license_valid_from']) ?></td>
                                <td><?= formatDate($c['license_valid_to']) ?></td>
                                <td class="text-end"><?= formatCurrency($c['license_fee']) ?></td>
                                <td><?= htmlspecialchars($c['remarks']) ?></td>
                                <td class="print-hide">
                                    <a href="?page=forms/labour/form-24&edit=<?= $c['id'] ?>"
                                       class="btn btn-outline-primary btn-xs py-0 px-1" title="Edit">
                                        <i class="bi bi-pencil-square"></i></a>
                                    <form method="POST" action="?page=forms/labour/form-24" class="d-inline"
                                          onsubmit="return confirm('Delete this contractor?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-xs py-0 px-1" title="Delete">
                                            <i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
@media print {
    .print-hide, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
    .container-fluid { padding: 0 !important; }
}
</style>
