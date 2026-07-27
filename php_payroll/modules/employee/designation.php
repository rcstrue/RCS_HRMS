<?php
/**
 * RCS HRMS Pro - Designation Management
 * Manage employee designations with:
 *   - worker_category (Unskilled / Semi-skilled / Skilled / Highly Skilled)
 *   - desi_view (show in ESS App dropdown or not)
 *
 * When an employee is assigned a designation, the worker_category is
 * auto-populated from here (see employee/add.php).
 */

$pageTitle = 'Manage Designations';

// Canonical worker categories (must match employee form + minimum-wage lookup)
$WORKER_CATEGORIES = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly Skilled'];

// Detect whether worker_category column exists (migration may not be run yet).
// Checked early so AJAX handlers can degrade gracefully.
$hasWorkerCategoryCol = false;
try {
    $col = $db->fetch("SHOW COLUMNS FROM designations LIKE 'worker_category'");
    $hasWorkerCategoryCol = !empty($col);
} catch (Exception $e) {
    $hasWorkerCategoryCol = false;
}

// ---------------------------------------------------------------------------
// AJAX handlers
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    header('Content-Type: application/json');

    // ---- toggle desi_view (Show in App) ----
    if ($_POST['action'] === 'toggle_view') {
        $id = (int)$_POST['id'];
        $status = (int)$_POST['status'];
        try {
            $stmt = $db->prepare("UPDATE designations SET desi_view = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true, 'message' => 'Status updated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- add ----
    if ($_POST['action'] === 'add') {
        $name = sanitize($_POST['name']);
        $worker_category = sanitize($_POST['worker_category'] ?? 'Unskilled');
        if (!in_array($worker_category, $WORKER_CATEGORIES, true)) {
            $worker_category = 'Unskilled';
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Designation name is required']);
            exit;
        }
        try {
            if ($hasWorkerCategoryCol) {
                $stmt = $db->prepare("INSERT INTO designations (name, worker_category, desi_view) VALUES (?, ?, 1)");
                $stmt->execute([$name, $worker_category]);
            } else {
                $stmt = $db->prepare("INSERT INTO designations (name, desi_view) VALUES (?, 1)");
                $stmt->execute([$name]);
            }
            echo json_encode(['success' => true, 'message' => 'Designation added', 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- delete ----
    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE designation = (SELECT name FROM designations WHERE id = ?)");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => "Cannot delete. $count employee(s) have this designation."]);
            } else {
                $stmt = $db->prepare("DELETE FROM designations WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Designation deleted']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- update ----
    if ($_POST['action'] === 'update') {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        $worker_category = sanitize($_POST['worker_category'] ?? 'Unskilled');
        if (!in_array($worker_category, $WORKER_CATEGORIES, true)) {
            $worker_category = 'Unskilled';
        }
        try {
            if ($hasWorkerCategoryCol) {
                $stmt = $db->prepare("UPDATE designations SET name = ?, worker_category = ? WHERE id = ?");
                $stmt->execute([$name, $worker_category, $id]);
            } else {
                $stmt = $db->prepare("UPDATE designations SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Designation updated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
// Get all designations with employee count (+ worker_category if present)
if ($hasWorkerCategoryCol) {
    $designations = $db->fetchAll(
        "SELECT d.id, d.name, d.worker_category, d.desi_view,
                (SELECT COUNT(*) FROM employees e WHERE e.designation = d.name) as emp_count
         FROM designations d
         ORDER BY d.name"
    );
} else {
    $designations = $db->fetchAll(
        "SELECT d.id, d.name, 'Unskilled' AS worker_category, d.desi_view,
                (SELECT COUNT(*) FROM employees e WHERE e.designation = d.name) as emp_count
         FROM designations d
         ORDER BY d.name"
    );
}

// Category badge colour map
$catColor = [
    'Unskilled'      => 'secondary',
    'Semi-skilled'   => 'info',
    'Skilled'        => 'primary',
    'Highly Skilled' => 'success',
];
?>

<?php if (!$hasWorkerCategoryCol): ?>
<div class="alert alert-warning alert-dismissible fade show m-3" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Migration required.</strong>
    The <code>worker_category</code> column is missing on the <code>designations</code> table.
    Run the migration to enable worker category linking:
    <a href="index.php?page=employee/run-designation-migration" class="alert-link ms-2">
        <i class="bi bi-play-circle"></i> Run migration now
    </a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0">
                    <i class="bi bi-briefcase me-2"></i>Manage Designations
                </h5>
                <button type="button" class="btn btn-primary btn-sm" onclick="showAddModal()">
                    <i class="bi bi-plus-lg me-1"></i>Add Designation
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Designation Name</th>
                                <th style="width: 170px;">Worker Category</th>
                                <th style="width: 130px;">Show in App</th>
                                <th style="width: 90px;">Employees</th>
                                <th style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($designations)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    No designations found. Click <strong>Add Designation</strong> to create one.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($designations as $i => $des):
                                $cat  = $des['worker_category'] ?? 'Unskilled';
                                $cCol = $catColor[$cat] ?? 'secondary';
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <span id="name-<?php echo $des['id']; ?>"><?php echo sanitize($des['name']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $cCol; ?>-soft"
                                          id="cat-<?php echo $des['id']; ?>">
                                        <?php echo sanitize($cat); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input"
                                               id="view-<?php echo $des['id']; ?>"
                                               <?php echo $des['desi_view'] ? 'checked' : ''; ?>
                                               onchange="toggleView(<?php echo $des['id']; ?>, this.checked ? 1 : 0)">
                                        <label class="form-check-label" for="view-<?php echo $des['id']; ?>">
                                            <span class="badge bg-<?php echo $des['desi_view'] ? 'success' : 'secondary'; ?>"
                                                  id="badge-<?php echo $des['id']; ?>">
                                                <?php echo $des['desi_view'] ? 'Show' : 'Hide'; ?>
                                            </span>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo (int)$des['emp_count']; ?></span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick='showEditModal(<?php echo json_encode(
                                                ["id" => (int)$des["id"], "name" => $des["name"], "worker_category" => $cat],
                                                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                                            ); ?>)'
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($des['emp_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="deleteDesignation(<?php echo $des['id']; ?>)"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
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

<!-- Info Card -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h6><i class="bi bi-info-circle me-2"></i>How it works</h6>
                <ul class="mb-0 text-muted small">
                    <li><strong>Worker Category</strong> — When an employee is assigned this designation, their
                        Worker Category is auto-filled (used for Minimum Wage lookup &amp; salary calculation).</li>
                    <li><strong>Show in App</strong> — <span class="badge bg-success-soft">Show</span> makes the
                        designation visible in the ESS App dropdowns; <span class="badge bg-secondary-soft">Hide</span>
                        keeps it in the system but hidden from the app.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Designation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addForm">
                    <div class="mb-3">
                        <label class="form-label required">Designation Name</label>
                        <input type="text" class="form-control" name="name" required
                               placeholder="e.g. Security Guard, Supervisor, Housekeeping …">
                    </div>
                    <div class="mb-2">
                        <label class="form-label required">Worker Category</label>
                        <select class="form-select" name="worker_category" required>
                            <?php foreach ($WORKER_CATEGORIES as $wc): ?>
                            <option value="<?php echo $wc; ?>"><?php echo $wc; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Auto-applied to the employee when this designation is selected.
                            Used for Minimum Wage &amp; salary calculation.
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addDesignation()">
                    <i class="bi bi-check-lg me-1"></i>Add
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Designation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="mb-3">
                        <label class="form-label required">Designation Name</label>
                        <input type="text" class="form-control" name="name" id="edit-name" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label required">Worker Category</label>
                        <select class="form-select" name="worker_category" id="edit-worker_category" required>
                            <?php foreach ($WORKER_CATEGORIES as $wc): ?>
                            <option value="<?php echo $wc; ?>"><?php echo $wc; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Changing this will <strong>not</strong> retroactively update existing employees —
                            it only applies to employees assigned this designation going forward.
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateDesignation()">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode(generateCSRFToken()); ?>;
const AJAX_URL    = 'index.php?page=employee/designation';

function toggleView(id, status) {
    fetch(AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_view&id=' + id + '&status=' + status + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('badge-' + id);
            badge.textContent = status ? 'Show' : 'Hide';
            badge.className = 'badge bg-' + (status ? 'success' : 'secondary');
            showToast('success', 'Status updated successfully');
        } else {
            showToast('error', data.message);
            document.getElementById('view-' + id).checked = !status;
        }
    });
}

function showAddModal() {
    document.getElementById('addForm').reset();
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function addDesignation() {
    const form = document.getElementById('addForm');
    const name = form.name.value.trim();
    if (!name) { showToast('error', 'Designation name is required'); return; }
    const formData = new FormData(form);
    formData.append('action', 'add');
    formData.append('csrf_token', CSRF_TOKEN);

    fetch(AJAX_URL, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation added');
            location.reload();
        } else {
            showToast('error', data.message);
        }
    });
}

function showEditModal(d) {
    document.getElementById('edit-id').value = d.id;
    document.getElementById('edit-name').value = d.name;
    document.getElementById('edit-worker_category').value = d.worker_category || 'Unskilled';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function updateDesignation() {
    const form = document.getElementById('editForm');
    const name = form.name.value.trim();
    if (!name) { showToast('error', 'Designation name is required'); return; }
    const formData = new FormData(form);
    formData.append('action', 'update');
    formData.append('csrf_token', CSRF_TOKEN);

    fetch(AJAX_URL, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation updated');
            location.reload();
        } else {
            showToast('error', data.message);
        }
    });
}

function deleteDesignation(id) {
    if (!confirm('Are you sure you want to delete this designation?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch(AJAX_URL, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation deleted');
            location.reload();
        } else {
            showToast('error', data.message);
        }
    });
}
</script>
