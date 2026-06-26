<?php
/**
 * RCS HRMS Pro — Form 32/33: Register of Injuries
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Register of accidents, injuries, dangerous occurrences
 */
$pageTitle = 'Form 32 - Register of Injuries';

// ── Auto-create table ───────────────────────────────────────────────
try {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS injury_register (
        id INT AUTO_INCREMENT PRIMARY KEY,
        injury_date DATE NOT NULL,
        employee_id INT,
        employee_code VARCHAR(100) DEFAULT '',
        employee_name VARCHAR(255) DEFAULT '',
        nature_of_injury VARCHAR(255) NOT NULL,
        cause_of_injury VARCHAR(255) DEFAULT '',
        body_part_affected VARCHAR(255) DEFAULT '',
        treatment_given VARCHAR(500) DEFAULT '',
        hospital_name VARCHAR(255) DEFAULT '',
        days_lost INT DEFAULT 0,
        compensation_amount DECIMAL(12,2) DEFAULT 0,
        accident_type VARCHAR(50) DEFAULT 'minor',
        status VARCHAR(50) DEFAULT 'open',
        remarks TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    die('Table creation failed: ' . $e->getMessage());
}

// ── Fetch employees ─────────────────────────────────────────────────
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
            $id                = intval($_POST['id'] ?? 0);
            $injuryDate        = sanitize($_POST['injury_date'] ?? '');
            $employeeId        = intval($_POST['employee_id'] ?? 0);
            $natureOfInjury    = sanitize($_POST['nature_of_injury'] ?? '');
            $causeOfInjury     = sanitize($_POST['cause_of_injury'] ?? '');
            $bodyPart          = sanitize($_POST['body_part_affected'] ?? '');
            $treatment         = sanitize($_POST['treatment_given'] ?? '');
            $hospitalName      = sanitize($_POST['hospital_name'] ?? '');
            $daysLost          = intval($_POST['days_lost'] ?? 0);
            $compensation      = floatval($_POST['compensation_amount'] ?? 0);
            $accidentType      = sanitize($_POST['accident_type'] ?? 'minor');
            $status            = sanitize($_POST['status'] ?? 'open');
            $remarks           = sanitize($_POST['remarks'] ?? '');

            if (empty($injuryDate)) throw new Exception('Injury date is required.');
            if (empty($natureOfInjury)) throw new Exception('Nature of injury is required.');

            // Resolve employee info
            $empCode = '';
            $empName = '';
            if ($employeeId > 0) {
                $stmt = $db->prepare("SELECT employee_code, full_name FROM employees WHERE id = ?");
                $stmt->execute([$employeeId]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($emp) { $empCode = $emp['employee_code']; $empName = $emp['full_name']; }
            }

            if ($action === 'edit' && $id > 0) {
                $stmt = $db->prepare("UPDATE injury_register SET
                    injury_date=?, employee_id=?, employee_code=?, employee_name=?,
                    nature_of_injury=?, cause_of_injury=?, body_part_affected=?,
                    treatment_given=?, hospital_name=?, days_lost=?,
                    compensation_amount=?, accident_type=?, status=?,
                    remarks=?, updated_at=CURRENT_TIMESTAMP
                    WHERE id=?");
                $stmt->execute([$injuryDate, $employeeId ?: null, $empCode, $empName,
                    $natureOfInjury, $causeOfInjury, $bodyPart,
                    $treatment, $hospitalName, $daysLost,
                    $compensation, $accidentType, $status,
                    $remarks, $id]);
                $message = 'Injury record updated successfully.';
            } else {
                $stmt = $db->prepare("INSERT INTO injury_register
                    (injury_date, employee_id, employee_code, employee_name,
                     nature_of_injury, cause_of_injury, body_part_affected,
                     treatment_given, hospital_name, days_lost,
                     compensation_amount, accident_type, status, remarks)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$injuryDate, $employeeId ?: null, $empCode, $empName,
                    $natureOfInjury, $causeOfInjury, $bodyPart,
                    $treatment, $hospitalName, $daysLost,
                    $compensation, $accidentType, $status, $remarks]);
                $message = 'Injury record added successfully.';
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
                $stmt = $db->prepare("DELETE FROM injury_register WHERE id=?");
                $stmt->execute([$id]);
                $message = 'Injury record deleted.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Delete failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ── Fetch records ───────────────────────────────────────────────────
$injuries = [];
$summary = [
    'total' => 0, 'fatal' => 0, 'serious' => 0, 'minor' => 0,
    'total_days_lost' => 0, 'total_compensation' => 0,
    'open_count' => 0, 'closed_count' => 0
];

try {
    $stmt = $db->query("SELECT * FROM injury_register ORDER BY injury_date DESC, id DESC");
    $injuries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($injuries as $inj) {
        $summary['total']++;
        $type = $inj['accident_type'] ?? 'minor';
        if (isset($summary[$type])) $summary[$type]++;
        $summary['total_days_lost'] += intval($inj['days_lost']);
        $summary['total_compensation'] += floatval($inj['compensation_amount']);
        if (($inj['status'] ?? 'open') === 'open') $summary['open_count']++;
        else $summary['closed_count']++;
    }
} catch (Exception $e) {
    $message = 'Fetch failed: ' . $e->getMessage();
    $messageType = 'danger';
}

// ── Edit record ─────────────────────────────────────────────────────
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM injury_register WHERE id=?");
        $stmt->execute([$editId]);
        $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_32_Register_of_Injuries_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sl No', 'Date', 'Emp Code', 'Name', 'Nature of Injury',
        'Cause', 'Body Part', 'Treatment', 'Hospital', 'Days Lost',
        'Compensation', 'Type', 'Status', 'Remarks']);
    $sl = 1;
    foreach ($injuries as $inj) {
        fputcsv($output, [$sl++, formatDate($inj['injury_date']), $inj['employee_code'],
            $inj['employee_name'], $inj['nature_of_injury'], $inj['cause_of_injury'],
            $inj['body_part_affected'], $inj['treatment_given'], $inj['hospital_name'],
            intval($inj['days_lost']), formatCurrency($inj['compensation_amount']),
            $inj['accident_type'], $inj['status'], $inj['remarks']]);
    }
    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-bandaid me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rules 93 & 94</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?page=forms/labour/form-32&export=csv" class="btn btn-outline-success btn-sm">
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

    <!-- Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-primary">
                <div class="fs-4 fw-bold text-primary"><?= $summary['total'] ?></div>
                <small class="text-muted">Total Incidents</small>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-danger">
                <div class="fs-4 fw-bold text-danger"><?= $summary['fatal'] ?></div>
                <small class="text-muted">Fatal</small>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-warning">
                <div class="fs-4 fw-bold text-warning"><?= $summary['serious'] ?></div>
                <small class="text-muted">Serious</small>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-success">
                <div class="fs-4 fw-bold text-success"><?= $summary['minor'] ?></div>
                <small class="text-muted">Minor</small>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-info">
                <div class="fs-4 fw-bold text-info"><?= $summary['total_days_lost'] ?></div>
                <small class="text-muted">Days Lost</small>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card text-center p-2 border-start border-4 border-secondary">
                <div class="fs-4 fw-bold"><?= formatCurrency($summary['total_compensation']) ?></div>
                <small class="text-muted">Compensation</small>
            </div>
        </div>
    </div>

    <!-- Add / Edit Form -->
    <div class="card mb-3">
        <div class="card-header bg-danger text-white">
            <h6 class="mb-0"><i class="bi bi-plus-circle me-1"></i>
                <?= $editRecord ? 'Edit Injury Record' : 'Report New Injury / Accident' ?></h6>
        </div>
        <div class="card-body">
            <form method="POST" action="?page=forms/labour/form-32">
                <input type="hidden" name="action" value="<?= $editRecord ? 'edit' : 'add' ?>">
                <?php if ($editRecord): ?>
                    <input type="hidden" name="id" value="<?= $editRecord['id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Injury Date <span class="text-danger">*</span></label>
                        <input type="date" name="injury_date" class="form-control form-control-sm" required
                               value="<?= htmlspecialchars($editRecord['injury_date'] ?? date('Y-m-d')) ?>">
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
                    <div class="col-md-2">
                        <label class="form-label">Accident Type <span class="text-danger">*</span></label>
                        <select name="accident_type" class="form-select form-select-sm">
                            <option value="minor" <?= ($editRecord && $editRecord['accident_type'] === 'minor') ? 'selected' : '' ?>>Minor</option>
                            <option value="serious" <?= ($editRecord && $editRecord['accident_type'] === 'serious') ? 'selected' : '' ?>>Serious</option>
                            <option value="fatal" <?= ($editRecord && $editRecord['accident_type'] === 'fatal') ? 'selected' : '' ?>>Fatal</option>
                            <option value="dangerous" <?= ($editRecord && $editRecord['accident_type'] === 'dangerous') ? 'selected' : '' ?>>Dangerous Occurrence</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="open" <?= ($editRecord && $editRecord['status'] === 'open') ? 'selected' : '' ?>>Open</option>
                            <option value="under_treatment" <?= ($editRecord && $editRecord['status'] === 'under_treatment') ? 'selected' : '' ?>>Under Treatment</option>
                            <option value="recovered" <?= ($editRecord && $editRecord['status'] === 'recovered') ? 'selected' : '' ?>>Recovered</option>
                            <option value="closed" <?= ($editRecord && $editRecord['status'] === 'closed') ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nature of Injury <span class="text-danger">*</span></label>
                        <input type="text" name="nature_of_injury" class="form-control form-control-sm" required
                               placeholder="e.g. Cut, Burn, Fracture"
                               value="<?= htmlspecialchars($editRecord['nature_of_injury'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cause of Injury</label>
                        <input type="text" name="cause_of_injury" class="form-control form-control-sm"
                               placeholder="e.g. Slipped, Machinery, Chemical"
                               value="<?= htmlspecialchars($editRecord['cause_of_injury'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Body Part Affected</label>
                        <input type="text" name="body_part_affected" class="form-control form-control-sm"
                               placeholder="e.g. Right Hand, Head, Eye"
                               value="<?= htmlspecialchars($editRecord['body_part_affected'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Treatment Given</label>
                        <input type="text" name="treatment_given" class="form-control form-control-sm"
                               placeholder="e.g. First Aid, Stitches, Surgery"
                               value="<?= htmlspecialchars($editRecord['treatment_given'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hospital / Clinic</label>
                        <input type="text" name="hospital_name" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['hospital_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Days Lost</label>
                        <input type="number" name="days_lost" class="form-control form-control-sm" min="0"
                               value="<?= htmlspecialchars($editRecord['days_lost'] ?? '0') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Compensation (₹)</label>
                        <input type="number" name="compensation_amount" class="form-control form-control-sm"
                               step="0.01" min="0"
                               value="<?= htmlspecialchars($editRecord['compensation_amount'] ?? '0') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($editRecord['remarks'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-check-lg me-1"></i><?= $editRecord ? 'Update' : 'Submit Report' ?>
                    </button>
                    <?php if ($editRecord): ?>
                        <a href="?page=forms/labour/form-32" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Injury Register Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Register of Injuries & Accidents
                <span class="badge bg-secondary ms-2"><?= $summary['total'] ?></span>
            </h6>
            <span class="text-muted">
                <span class="badge bg-warning text-dark"><?= $summary['open_count'] ?> Open</span>
                <span class="badge bg-success"><?= $summary['closed_count'] ?> Closed</span>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th style="width:40px" class="text-center">Sl</th>
                            <th style="width:85px">Date</th>
                            <th style="width:70px">Emp Code</th>
                            <th>Employee Name</th>
                            <th style="width:120px">Nature of Injury</th>
                            <th style="width:100px">Cause</th>
                            <th style="width:100px">Body Part</th>
                            <th style="width:90px">Treatment</th>
                            <th style="width:100px">Hospital</th>
                            <th style="width:55px" class="text-center">Days</th>
                            <th style="width:90px" class="text-end">Comp.</th>
                            <th style="width:60px">Type</th>
                            <th style="width:70px">Status</th>
                            <th style="width:85px" class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($injuries)): ?>
                            <tr><td colspan="14" class="text-center text-muted py-3">No injury records found.</td></tr>
                        <?php else: $sl = 1; foreach ($injuries as $inj):
                            $typeBadge = match($inj['accident_type']) {
                                'fatal' => 'bg-danger',
                                'serious' => 'bg-warning text-dark',
                                'dangerous' => 'bg-dark',
                                default => 'bg-info'
                            };
                            $statusBadge = match($inj['status']) {
                                'closed', 'recovered' => 'bg-success',
                                'under_treatment' => 'bg-warning text-dark',
                                default => 'bg-danger'
                            };
                        ?>
                            <tr class="<?= ($inj['status'] ?? '') === 'closed' ? 'table-secondary opacity-75' : '' ?>">
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><?= formatDate($inj['injury_date']) ?></td>
                                <td><?= htmlspecialchars($inj['employee_code']) ?></td>
                                <td><?= htmlspecialchars($inj['employee_name']) ?></td>
                                <td><?= htmlspecialchars($inj['nature_of_injury']) ?></td>
                                <td><?= htmlspecialchars($inj['cause_of_injury']) ?></td>
                                <td><?= htmlspecialchars($inj['body_part_affected']) ?></td>
                                <td><?= htmlspecialchars($inj['treatment_given']) ?></td>
                                <td class="small"><?= htmlspecialchars($inj['hospital_name']) ?></td>
                                <td class="text-center"><?= intval($inj['days_lost']) ?></td>
                                <td class="text-end"><?= formatCurrency($inj['compensation_amount']) ?></td>
                                <td><span class="badge <?= $typeBadge ?>"><?= strtoupper($inj['accident_type']) ?></span></td>
                                <td><span class="badge <?= $statusBadge ?>"><?= strtoupper(str_replace('_', ' ', $inj['status'])) ?></span></td>
                                <td class="no-print">
                                    <a href="?page=forms/labour/form-32&edit=<?= $inj['id'] ?>"
                                       class="btn btn-outline-primary btn-xs py-0 px-1" title="Edit">
                                        <i class="bi bi-pencil-square"></i></a>
                                    <form method="POST" action="?page=forms/labour/form-32" class="d-inline"
                                          onsubmit="return confirm('Delete this injury record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $inj['id'] ?>">
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

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .badge { border: 1px solid #999 !important; }
    body { font-size: 9px; }
    .table { font-size: 8px; }
    .container-fluid { padding: 0 !important; }
}
</style>
