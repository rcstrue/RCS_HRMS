<?php
/**
 * RCS HRMS Pro - Client List
 * 
 * Database: clients table has 'name' field (not 'client_name')
 */

$pageTitle = 'Clients';

// Handle add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $data = [
            'client_name' => sanitize($_POST['client_name']),
            'address' => sanitize($_POST['address'] ?? ''),
            'city' => sanitize($_POST['city'] ?? ''),
            'state' => sanitize($_POST['state'] ?? ''),
            'pincode' => sanitize($_POST['pincode'] ?? ''),
            'gst_number' => sanitize($_POST['gst_number'] ?? ''),
            'contact_person' => sanitize($_POST['contact_person'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        $result = $client->create($data);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=client/list');
    }

    if ($action === 'edit' && isset($_POST['client_id'])) {
        $data = [
            'client_name' => sanitize($_POST['client_name']),
            'address' => sanitize($_POST['address'] ?? ''),
            'city' => sanitize($_POST['city'] ?? ''),
            'state' => sanitize($_POST['state'] ?? ''),
            'pincode' => sanitize($_POST['pincode'] ?? ''),
            'gst_number' => sanitize($_POST['gst_number'] ?? ''),
            'contact_person' => sanitize($_POST['contact_person'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        $result = $client->update($_POST['client_id'], $data);
        setFlash('success', 'Client updated successfully!');
        redirect('index.php?page=client/list');
    }

    if ($action === 'delete' && isset($_POST['client_id'])) {
        $result = $client->delete($_POST['client_id']);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=client/list');
    }
}

// Get all clients
$clients = $client->getAll(false);
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-building me-2"></i>Clients</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Client
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="clientsTable">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Client Name</th>
                                <th>Contact Person</th>
                                <th>Phone</th>
                                <th class="text-center">Units</th>
                                <th class="text-center">Employees</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($clients)): ?>
                                <?php foreach ($clients as $c): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo sanitize($c['client_code'] ?? '-'); ?></span></td>
                                    <td><strong><?php echo sanitize($c['client_name'] ?? '-'); ?></strong></td>
                                    <td><?php echo sanitize($c['contact_person'] ?? '-'); ?></td>
                                    <td><?php echo sanitize($c['contact_phone'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <a href="index.php?page=unit/list&client=<?php echo $c['id']; ?>">
                                            <span class="badge bg-primary"><?php echo $c['unit_count'] ?? 0; ?></span>
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success"><?php echo $c['employee_count'] ?? 0; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo ($c['is_active'] ?? 1) ? 'success' : 'danger'; ?>">
                                            <?php echo ($c['is_active'] ?? 1) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick='editClient(<?php echo htmlspecialchars(json_encode($c)); ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="deleteClient(<?php echo $c['id']; ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No clients found. Click "Add Client" to create one.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">Client Name</label>
                            <input type="text" class="form-control" name="client_name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">GST Number</label>
                            <input type="text" class="form-control" name="gst_number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="pincode">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="clientActive" checked>
                        <label class="form-check-label" for="clientActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="client_id" id="edit_client_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">Client Name</label>
                            <input type="text" class="form-control" name="client_name" id="edit_client_name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">GST Number</label>
                            <input type="text" class="form-control" name="gst_number" id="edit_gst_number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" id="edit_city">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state" id="edit_state">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="pincode" id="edit_pincode">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person" id="edit_contact_person">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="edit_is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="client_id" id="delete_client_id">
</form>

<?php
// Page-specific JavaScript for DataTable initialization (wrapped in document.ready by footer)
$inlineJS = <<<'JS'
// Initialize DataTable
$('#clientsTable').DataTable({
    responsive: true,
    pageLength: 25,
    order: [[1, 'asc']]
});
JS;

// Extra JS with script tags (output after jQuery loads)
$extraJS = <<<'JS'
<script>
// Edit client function - must be global for onclick
function editClient(client) {
    $('#edit_client_id').val(client.id);
    $('#edit_client_name').val(client.client_name || client.name || '');
    $('#edit_address').val(client.address || '');
    $('#edit_city').val(client.city || '');
    $('#edit_state').val(client.state || '');
    $('#edit_pincode').val(client.pincode || '');
    $('#edit_gst_number').val(client.gst_number || '');
    $('#edit_contact_person').val(client.contact_person || '');
    $('#edit_phone').val(client.phone || client.contact_phone || '');
    $('#edit_email').val(client.email || client.contact_email || '');
    $('#edit_is_active').prop('checked', client.is_active == 1);
    new bootstrap.Modal('#editClientModal').show();
}

// Delete client function - must be global for onclick
function deleteClient(id) {
    if (confirm('Are you sure you want to delete this client?')) {
        $('#delete_client_id').val(id);
        $('#deleteForm').submit();
    }
}
</script>
JS;
?>
