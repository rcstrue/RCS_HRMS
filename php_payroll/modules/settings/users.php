<?php
/**
 * RCS HRMS Pro - User Management
 */

$pageTitle = 'Manage Users';

// Get all users
$users = $auth->getAllUsers(false);

// Get roles
$stmt = $db->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY level DESC");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle add/edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $data = [
            'username' => sanitize($_POST['username']),
            'email' => sanitize($_POST['email']),
            'password' => $_POST['password'],
            'role_id' => (int)$_POST['role_id'],
            'first_name' => sanitize($_POST['first_name']),
            'last_name' => sanitize($_POST['last_name']),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        
        $result = $auth->createUser($data);
        
        if (isset($result['success'])) {
            setFlash('success', 'User created successfully!');
        } else {
            setFlash('error', $result['error'] ?? 'Failed to create user');
        }
        redirect('index.php?page=settings/users');
    }
    
    if ($action === 'edit' && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        
        $stmt = $db->prepare(
            "UPDATE users SET 
                email = ?, role_id = ?, first_name = ?, last_name = ?, 
                phone = ?, is_active = ?
             WHERE id = ?"
        );
        
        $stmt->execute([
            sanitize($_POST['email']),
            (int)$_POST['role_id'],
            sanitize($_POST['first_name']),
            sanitize($_POST['last_name']),
            sanitize($_POST['phone'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0,
            $userId
        ]);
        
        // Update password if provided
        if (!empty($_POST['password'])) {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
        }
        
        setFlash('success', 'User updated successfully!');
        redirect('index.php?page=settings/users');
    }
    
    if ($action === 'delete' && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        
        // Prevent deleting self
        if ($userId == $_SESSION['user_id']) {
            setFlash('error', 'Cannot delete your own account!');
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            setFlash('success', 'User deleted successfully!');
        }
        redirect('index.php?page=settings/users');
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Manage Users</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-plus-lg me-1"></i>Add User
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><code><?php echo sanitize($u['username']); ?></code></td>
                                <td><?php echo sanitize($u['first_name'] . ' ' . $u['last_name']); ?></td>
                                <td><?php echo sanitize($u['email']); ?></td>
                                <td>
                                    <span class="badge bg-primary-soft"><?php echo sanitize($u['role_name']); ?></span>
                                </td>
                                <td><?php echo $u['last_login'] ? formatDate($u['last_login'], 'd-m-Y H:i') : 'Never'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="deleteUser(<?php echo $u['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Username</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Password</label>
                        <input type="password" class="form-control" name="password" required minlength="8">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">First Name</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Role</label>
                        <select class="form-select" name="role_id" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo sanitize($r['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="isActive" checked>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Email</label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="password" minlength="8">
                        <div class="form-text">Leave blank to keep current password</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="edit_last_name">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Role</label>
                        <select class="form-select" name="role_id" id="edit_role_id" required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo sanitize($r['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" id="edit_phone">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="edit_is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<?php
$inlineJS = <<<'JS'
// Global functions for onclick handlers
window.editUser = function(user) {
    $('#edit_user_id').val(user.id);
    $('#edit_username').val(user.username);
    $('#edit_email').val(user.email);
    $('#edit_first_name').val(user.first_name);
    $('#edit_last_name').val(user.last_name);
    $('#edit_role_id').val(user.role_id);
    $('#edit_phone').val(user.phone);
    $('#edit_is_active').prop('checked', user.is_active == 1);
    new bootstrap.Modal('#editUserModal').show();
};

window.deleteUser = function(userId) {
    if (confirm('Are you sure you want to delete this user?')) {
        $('#delete_user_id').val(userId);
        $('#deleteForm').submit();
    }
};
JS;
?>
