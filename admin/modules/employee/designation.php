<?php
/**
 * RCS HRMS Pro - Designation Management
 * Manage employee designations with portal visibility toggle
 */

$pageTitle = 'Manage Designations';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
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
    
    if ($_POST['action'] === 'add') {
        $name = sanitize($_POST['name']);
        
        try {
            $stmt = $db->prepare("INSERT INTO designations (name, desi_view) VALUES (?, 1)");
            $stmt->execute([$name]);
            
            echo json_encode(['success' => true, 'message' => 'Designation added', 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Check if designation is in use
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
    
    if ($_POST['action'] === 'update') {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        
        try {
            $stmt = $db->prepare("UPDATE designations SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            
            echo json_encode(['success' => true, 'message' => 'Designation updated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get all designations with employee count
$designations = $db->fetchAll(
    "SELECT d.*, 
            (SELECT COUNT(*) FROM employees e WHERE e.designation = d.name) as emp_count
     FROM designations d
     ORDER BY d.name"
);
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-briefcase me-2"></i>Manage Designations</h5>
                <button type="button" class="btn btn-primary btn-sm" onclick="showAddModal()">
                    <i class="bi bi-plus-lg me-1"></i>Add Designation
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Designation Name</th>
                                <th style="width: 120px;">Portal View</th>
                                <th style="width: 100px;">Employees</th>
                                <th style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($designations)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No designations found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($designations as $i => $des): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <span id="name-<?php echo $des['id']; ?>"><?php echo sanitize($des['name']); ?></span>
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
                                    <span class="badge bg-info"><?php echo $des['emp_count']; ?></span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="showEditModal(<?php echo $des['id']; ?>, '<?php echo sanitize($des['name']); ?>')"
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
                <h6><i class="bi bi-info-circle me-2"></i>Portal View Status</h6>
                <p class="mb-0 text-muted">
                    <strong>Show (1)</strong> - Designation will be visible in employee portal dropdowns.<br>
                    <strong>Hide (0)</strong> - Designation will be hidden from employee portal dropdowns but will remain in the system.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Designation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addForm">
                    <div class="mb-3">
                        <label class="form-label required">Designation Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addDesignation()">Add</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Designation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="mb-3">
                        <label class="form-label required">Designation Name</label>
                        <input type="text" class="form-control" name="name" id="edit-name" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateDesignation()">Update</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleView(id, status) {
    fetch('index.php?page=employee/designation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_view&id=' + id + '&status=' + status
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update badge
            const badge = document.getElementById('badge-' + id);
            badge.textContent = status ? 'Show' : 'Hide';
            badge.className = 'badge bg-' + (status ? 'success' : 'secondary');
            showToast('success', 'Status updated successfully');
        } else {
            showToast('error', data.message);
            // Revert checkbox
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
    const formData = new FormData(form);
    formData.append('action', 'add');
    
    fetch('index.php?page=employee/designation', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            showToast('error', data.message);
        }
    });
}

function showEditModal(id, name) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function updateDesignation() {
    const form = document.getElementById('editForm');
    const formData = new FormData(form);
    formData.append('action', 'update');
    
    fetch('index.php?page=employee/designation', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
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
    
    fetch('index.php?page=employee/designation', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            showToast('error', data.message);
        }
    });
}
</script>
