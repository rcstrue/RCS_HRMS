<?php
/**
 * RCS HRMS Pro - Designation Management
 *
 * Manage employee designations with:
 *   - worker_category (Unskilled / Semi-skilled / Skilled / Highly Skilled) —
 *     editable INLINE directly in the table row (no modal needed).
 *   - desi_view (Show in App toggle).
 *
 * NOTE: All AJAX (add / edit / delete / toggle / inline-category-update) is
 * routed through the dedicated api/designation endpoint. Posting to
 * employee/designation itself does NOT work because module pages are rendered
 * inside the HTML shell — by the time the page's PHP runs, headers have
 * already been sent, so `Content-Type: application/json` is ignored and the
 * JSON gets prefixed with HTML, breaking response.json() on the client.
 */

$pageTitle = 'Manage Designations';

// Canonical worker categories (must match employee form + minimum-wage lookup)
$WORKER_CATEGORIES = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly Skilled'];

// (designations.worker_category column managed in migrations)
$hasWorkerCategoryCol = true;
try {
    $designations = $db->fetchAll(
        "SELECT d.id, d.name, d.worker_category, d.desi_view,
                (SELECT COUNT(*) FROM employees e WHERE e.designation = d.name) as emp_count
         FROM designations d
         ORDER BY d.name"
    );
} catch (Exception $e) {
    $hasWorkerCategoryCol = false;
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
    Inline category editing is disabled until the migration is run:
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
                                <th style="width: 200px;">
                                    Worker Category
                                    <small class="text-muted d-block" style="font-weight:normal;">(change inline)</small>
                                </th>
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
                            <tr id="row-<?php echo (int)$des['id']; ?>">
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <span id="name-<?php echo (int)$des['id']; ?>"><?php echo sanitize($des['name']); ?></span>
                                </td>
                                <td>
                                    <?php if ($hasWorkerCategoryCol): ?>
                                    <select class="form-select form-select-sm cat-select"
                                            data-id="<?php echo (int)$des['id']; ?>"
                                            onchange="updateCategory(<?php echo (int)$des['id']; ?>, this)"
                                            style="max-width: 170px;">
                                        <?php foreach ($WORKER_CATEGORIES as $wc): ?>
                                        <option value="<?php echo $wc; ?>" <?php echo $cat === $wc ? 'selected' : ''; ?>>
                                            <?php echo $wc; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                    <span class="badge bg-<?php echo $cCol; ?>-soft"><?php echo sanitize($cat); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input"
                                               id="view-<?php echo (int)$des['id']; ?>"
                                               <?php echo $des['desi_view'] ? 'checked' : ''; ?>
                                               onchange="toggleView(<?php echo (int)$des['id']; ?>, this)">
                                        <label class="form-check-label" for="view-<?php echo (int)$des['id']; ?>">
                                            <span class="badge bg-<?php echo $des['desi_view'] ? 'success' : 'secondary'; ?>"
                                                  id="badge-<?php echo (int)$des['id']; ?>">
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
                                            title="Edit name / category">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($des['emp_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="deleteDesignation(<?php echo (int)$des['id']; ?>)"
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
                    <li><strong>Worker Category</strong> — Change it directly in the dropdown above (saves automatically).
                        When an employee is assigned this designation, their Worker Category is auto-filled
                        (used for Minimum Wage lookup &amp; salary calculation).</li>
                    <li><strong>Show in App</strong> — <span class="badge bg-success-soft">Show</span> makes the
                        designation visible in the ESS App dropdowns; <span class="badge bg-secondary">Hide</span>
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

<!-- Toast container (self-contained on this page) -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1090;">
    <div id="desigToast" class="toast align-items-center border-0 text-bg-success" role="alert"
         aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode(generateCSRFToken()); ?>;
// POST to the dedicated API endpoint — NOT to this page. The api/* endpoints
// are included by index.php WITHOUT the HTML wrapper, so JSON responses come
// back clean (no HTML shell prefix) and response.json() works.
const API_URL    = 'index.php?page=api/designation';

// ── Self-contained toast helper ───────────────────────────────────────
// showToast is NOT a global function in this app (only defined locally on a
// couple of pages). Define it here backed by the #desigToast container below.
function showToast(msg, type) {
    type = type || 'success';
    var el = document.getElementById('desigToast');
    if (!el) { console.log('[toast]', type, msg); return; } // graceful fallback
    el.className = 'toast align-items-center border-0 text-bg-' +
        (type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : 'success'));
    var body = el.querySelector('.toast-body');
    if (body) {
        var icon = type === 'error' ? 'x-circle' : (type === 'warning' ? 'exclamation-triangle' : 'check-circle');
        body.innerHTML = '<i class="bi bi-' + icon + ' me-1"></i> ' + msg;
    }
    try {
        bootstrap.Toast.getOrCreateInstance(el, { delay: 4000 }).show();
    } catch (e) {
        console.log('[toast]', type, msg);
    }
}

// Small helper: POST form-encoded body with CSRF in header (works for all actions)
function apiPost(body) {
    return fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: body
    }).then(r => r.json());
}

// ── Toggle Show in App ────────────────────────────────────────────────
function toggleView(id, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    // optimistic: badge updates immediately; reverted on failure
    const badge = document.getElementById('badge-' + id);
    const prevChecked = !checkbox.checked;
    apiPost('action=toggle_view&id=' + id + '&status=' + status)
    .then(data => {
        if (data.success) {
            badge.textContent = status ? 'Show' : 'Hide';
            badge.className = 'badge bg-' + (status ? 'success' : 'secondary');
            showToast('success', data.message || 'Status updated');
        } else {
            checkbox.checked = prevChecked; // revert
            showToast('error', data.message || 'Failed to update status');
        }
    })
    .catch(() => {
        checkbox.checked = prevChecked; // revert
        showToast('error', 'Network error — please try again');
    });
}

// ── Inline worker-category change (dropdown in the row) ───────────────
function updateCategory(id, select) {
    const newCat = select.value;
    const prevCat = select.dataset.prevValue || newCat;
    select.dataset.prevValue = newCat;
    select.classList.add('is-valid');
    apiPost('action=update_cat&id=' + id + '&worker_category=' + encodeURIComponent(newCat))
    .then(data => {
        if (data.success) {
            showToast('success', 'Category updated to ' + newCat);
            setTimeout(() => select.classList.remove('is-valid'), 1200);
        } else {
            select.value = prevCat; // revert
            showToast('error', data.message || 'Failed to update category');
        }
    })
    .catch(() => {
        select.value = prevCat; // revert
        showToast('error', 'Network error — please try again');
    });
}

// Remember the previous value of each inline category dropdown so we can revert on error
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.cat-select').forEach(function(s) {
        s.dataset.prevValue = s.value;
    });
});

// ── Add ───────────────────────────────────────────────────────────────
function showAddModal() {
    document.getElementById('addForm').reset();
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function addDesignation() {
    const form = document.getElementById('addForm');
    const name = form.name.value.trim();
    if (!name) { showToast('error', 'Designation name is required'); return; }
    const body = 'action=add&name=' + encodeURIComponent(name)
               + '&worker_category=' + encodeURIComponent(form.worker_category.value);
    apiPost(body)
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation added');
            location.reload();
        } else {
            showToast('error', data.message || 'Failed to add designation');
        }
    })
    .catch(() => showToast('error', 'Network error — please try again'));
}

// ── Edit (modal) ──────────────────────────────────────────────────────
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
    const id = form.id.value;
    const body = 'action=update&id=' + id
               + '&name=' + encodeURIComponent(name)
               + '&worker_category=' + encodeURIComponent(form.worker_category.value);
    apiPost(body)
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation updated');
            location.reload();
        } else {
            showToast('error', data.message || 'Failed to update designation');
        }
    })
    .catch(() => showToast('error', 'Network error — please try again'));
}

// ── Delete ────────────────────────────────────────────────────────────
function deleteDesignation(id) {
    if (!confirm('Are you sure you want to delete this designation?')) return;
    apiPost('action=delete&id=' + id)
    .then(data => {
        if (data.success) {
            showToast('success', 'Designation deleted');
            location.reload();
        } else {
            showToast('error', data.message || 'Failed to delete designation');
        }
    })
    .catch(() => showToast('error', 'Network error — please try again'));
}
</script>
