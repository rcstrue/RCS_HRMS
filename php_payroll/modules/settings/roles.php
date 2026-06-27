<?php
/**
 * RCS HRMS Pro - Role Management
 * Updated: Syncs permissions to role_menu_permissions for sidebar visibility
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Manage Roles';

// Get all roles
$roles = $db->fetchAll("SELECT * FROM roles ORDER BY level DESC");

// Define available permissions
$availablePermissions = [
    'dashboard' => ['view' => 'View Dashboard'],
    'employee' => [
        'view' => 'View Employees',
        'add' => 'Add Employee',
        'edit' => 'Edit Employee',
        'delete' => 'Delete Employee',
        'import' => 'Import Employees',
        'export' => 'Export Employees'
    ],
    'attendance' => [
        'view' => 'View Attendance',
        'add' => 'Add Attendance',
        'edit' => 'Edit Attendance',
        'import' => 'Import Attendance',
        'export' => 'Export Attendance'
    ],
    'payroll' => [
        'view' => 'View Payroll',
        'process' => 'Process Payroll',
        'approve' => 'Approve Payroll',
        'export' => 'Export Payroll'
    ],
    'client' => [
        'view' => 'View Clients',
        'add' => 'Add Client',
        'edit' => 'Edit Client',
        'delete' => 'Delete Client'
    ],
    'unit' => [
        'view' => 'View Units',
        'add' => 'Add Unit',
        'edit' => 'Edit Unit',
        'delete' => 'Delete Unit'
    ],
    'compliance' => [
        'view' => 'View Compliance',
        'manage' => 'Manage Compliance',
        'file' => 'File Returns'
    ],
    'reports' => [
        'view' => 'View Reports',
        'export' => 'Export Reports'
    ],
    'settings' => [
        'view' => 'View Settings',
        'manage' => 'Manage Settings'
    ],
    'users' => [
        'view' => 'View Users',
        'add' => 'Add User',
        'edit' => 'Edit User',
        'delete' => 'Delete User'
    ],
    'roles' => [
        'view' => 'View Roles',
        'add' => 'Add Role',
        'edit' => 'Edit Role',
        'delete' => 'Delete Role'
    ]
];

/**
 * Check if role_menu_permissions table exists
 */
if (!function_exists('menuPermissionsTableExists')) {
    function menuPermissionsTableExists($db) {
        try {
            $result = $db->fetch("SHOW TABLES LIKE 'role_menu_permissions'");
            return !empty($result);
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Sync permissions to role_menu_permissions table
 * This ensures sidebar shows/hides menus correctly
 */
if (!function_exists('syncMenuPermissions')) {
    function syncMenuPermissions($db, $roleId, $permissions) {
    // Check if table exists first
    if (!menuPermissionsTableExists($db)) {
        return false;
    }
    
    // Map permission modules to menu keys
    $menuMap = [
        'dashboard' => 'dashboard',
        'employee' => 'employee',
        'client' => 'client',
        'attendance' => 'attendance',
        'payroll' => 'payroll',
        'compliance' => 'compliance',
        'reports' => 'report',
        'settings' => 'settings',
    ];
    
    // Get all menus from auth
    global $auth;
    $menus = $auth ? $auth->getAllMenus() : [];
    
    if (empty($menus)) {
        return false;
    }
    
    try {
        // Clear existing permissions for this role
        $db->query("DELETE FROM role_menu_permissions WHERE role_id = :role_id", ['role_id' => $roleId]);
        
        // Insert new permissions
        foreach ($menus as $menuKey => $menuInfo) {
            $isVisible = 0;
            
            // Check if user has view permission for this menu's module
            foreach ($menuMap as $permModule => $menuMatch) {
                if (strpos($menuKey, $menuMatch) !== false || $menuKey === $menuMatch) {
                    if (isset($permissions[$permModule]['view']) && $permissions[$permModule]['view']) {
                        $isVisible = 1;
                    }
                    break;
                }
            }
            
            // Insert menu permission
            $db->insert('role_menu_permissions', [
                'role_id' => $roleId,
                'menu_key' => $menuKey,
                'submenu_key' => null,
                'is_visible' => $isVisible,
                'can_view' => $isVisible,
                'can_add' => isset($permissions[$menuKey]['add']) ? 1 : 0,
                'can_edit' => isset($permissions[$menuKey]['edit']) ? 1 : 0,
                'can_delete' => isset($permissions[$menuKey]['delete']) ? 1 : 0
            ]);
            
            // Handle submenus
            if (!empty($menuInfo['submenus'])) {
                foreach ($menuInfo['submenus'] as $submenuKey => $submenuInfo) {
                    // Submenu visible if parent is visible
                    $subVisible = $isVisible;
                    
                    $db->insert('role_menu_permissions', [
                        'role_id' => $roleId,
                        'menu_key' => $menuKey,
                        'submenu_key' => $submenuKey,
                        'is_visible' => $subVisible,
                        'can_view' => $subVisible,
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0
                    ]);
                }
            }
        }
        return true;
    } catch (Exception $e) {
        error_log('syncMenuPermissions error: ' . $e->getMessage());
        return false;
    }
}
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $roleName = sanitize($_POST['role_name']);
        $roleCode = sanitize($_POST['role_code']);
        $description = sanitize($_POST['description'] ?? '');
        $level = (int)$_POST['level'];
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        // Collect permissions
        $permissions = [];
        foreach ($availablePermissions as $module => $perms) {
            foreach ($perms as $perm => $label) {
                $key = $module . '_' . $perm;
                if (isset($_POST['permissions'][$key])) {
                    $permissions[$module][$perm] = true;
                }
            }
        }
        $permissionsJson = json_encode($permissions);
        
        // Check if role_code already exists
        $existing = $db->fetch("SELECT id FROM roles WHERE role_code = :code", ['code' => $roleCode]);
        
        if ($existing) {
            setFlash('error', 'Role code already exists!');
        } else {
            $db->insert('roles', [
                'role_name' => $roleName,
                'role_code' => $roleCode,
                'description' => $description,
                'permissions' => $permissionsJson,
                'level' => $level,
                'is_active' => $isActive
            ]);
            
            $roleId = $db->lastInsertId();
            
            // Sync to menu permissions
            syncMenuPermissions($db, $roleId, $permissions);
            
            logActivity('create', 'roles', $roleId, "Created role: $roleName");
            setFlash('success', 'Role created successfully!');
        }
        redirect('index.php?page=settings/roles');
    }
    
    if ($action === 'edit' && isset($_POST['role_id'])) {
        $roleId = (int)$_POST['role_id'];
        $roleName = sanitize($_POST['role_name']);
        $description = sanitize($_POST['description'] ?? '');
        $level = (int)$_POST['level'];
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        // Collect permissions
        $permissions = [];
        foreach ($availablePermissions as $module => $perms) {
            foreach ($perms as $perm => $label) {
                $key = $module . '_' . $perm;
                if (isset($_POST['permissions'][$key])) {
                    $permissions[$module][$perm] = true;
                }
            }
        }
        $permissionsJson = json_encode($permissions);
        
        $db->update('roles', [
            'role_name' => $roleName,
            'description' => $description,
            'permissions' => $permissionsJson,
            'level' => $level,
            'is_active' => $isActive
        ], 'id = :id', ['id' => $roleId]);
        
        // Sync to menu permissions
        syncMenuPermissions($db, $roleId, $permissions);
        
        logActivity('update', 'roles', $roleId, "Updated role: $roleName");
        setFlash('success', 'Role updated successfully!');
        redirect('index.php?page=settings/roles');
    }
    
    if ($action === 'delete' && isset($_POST['role_id'])) {
        $roleId = (int)$_POST['role_id'];
        
        // Check if role is assigned to any user
        $userCount = $db->fetch("SELECT COUNT(*) as count FROM users WHERE role_id = :id", ['id' => $roleId]);
        
        if ($userCount['count'] > 0) {
            setFlash('error', 'Cannot delete role. It is assigned to ' . $userCount['count'] . ' user(s).');
        } else {
            // Delete role menu permissions first (if table exists)
            try {
                if (menuPermissionsTableExists($db)) {
                    $db->query("DELETE FROM role_menu_permissions WHERE role_id = :id", ['id' => $roleId]);
                }
            } catch (Exception $e) {
                // Ignore if table doesn't exist
            }
            
            // Delete role
            $db->delete('roles', 'id = :id', ['id' => $roleId]);
            
            logActivity('delete', 'roles', $roleId, "Deleted role");
            setFlash('success', 'Role deleted successfully!');
        }
        redirect('index.php?page=settings/roles');
    }
    
    if ($action === 'toggle' && isset($_POST['role_id'])) {
        $roleId = (int)$_POST['role_id'];
        $role = $db->fetch("SELECT * FROM roles WHERE id = :id", ['id' => $roleId]);
        
        if ($role) {
            $newStatus = $role['is_active'] ? 0 : 1;
            $db->update('roles', ['is_active' => $newStatus], 'id = :id', ['id' => $roleId]);
            setFlash('success', 'Role status updated!');
        }
        redirect('index.php?page=settings/roles');
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Manage Roles</h4>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                <i class="bi bi-plus-lg me-1"></i>Add Role
            </button>
        </div>
        
        <?php $flash = getFlash(); ?>
        <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show">
            <?php echo sanitize($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="rolesTable">
                        <thead>
                            <tr>
                                <th>Role Name</th>
                                <th>Code</th>
                                <th>Description</th>
                                <th>Permissions</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                            <?php 
                            $perms = json_decode($role['permissions'] ?? '{}', true);
                            $permCount = 0;
                            foreach ($perms as $module => $actions) {
                                $permCount += count($actions);
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo sanitize($role['role_name']); ?></strong>
                                    <?php if ($role['role_code'] === 'admin'): ?>
                                    <span class="badge bg-danger ms-1">System</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo sanitize($role['role_code']); ?></code></td>
                                <td><?php echo sanitize($role['description'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-primary"><?php echo $permCount; ?> permissions</span>
                                </td>
                                <td><?php echo $role['level']; ?></td>
                                <td>
                                    <?php if ($role['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($role['role_code'] !== 'admin'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editRole(<?php echo htmlspecialchars(json_encode($role)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo sanitize($role['role_name']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted">Protected</span>
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

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <?php echo getCSRFTokenField(); ?>
                
                <div class="modal-header">
                    <h5 class="modal-title">Add New Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Role Name *</label>
                            <input type="text" name="role_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role Code *</label>
                            <input type="text" name="role_code" class="form-control" required pattern="[a-z_]+" placeholder="lowercase_with_underscores">
                            <small class="text-muted">Lowercase letters and underscores only</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Level</label>
                            <input type="number" name="level" class="form-control" value="1" min="1" max="100">
                            <small class="text-muted">Higher level = more access</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" name="is_active" class="form-check-input" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label"><strong>Permissions</strong></label>
                            <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($availablePermissions as $module => $perms): ?>
                                <div class="mb-3">
                                    <strong class="text-primary text-uppercase"><?php echo $module; ?></strong>
                                    <div class="row ms-2 mt-1">
                                        <?php foreach ($perms as $perm => $label): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" name="permissions[<?php echo $module; ?>_<?php echo $perm; ?>]" id="perm_<?php echo $module; ?>_<?php echo $perm; ?>">
                                                <label class="form-check-label" for="perm_<?php echo $module; ?>_<?php echo $perm; ?>"><?php echo $label; ?></label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="role_id" id="edit_role_id">
                <?php echo getCSRFTokenField(); ?>
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Role Name *</label>
                            <input type="text" name="role_name" id="edit_role_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role Code</label>
                            <input type="text" id="edit_role_code" class="form-control" disabled>
                            <small class="text-muted">Role code cannot be changed</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Level</label>
                            <input type="number" name="level" id="edit_level" class="form-control" min="1" max="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label"><strong>Permissions</strong></label>
                            <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($availablePermissions as $module => $perms): ?>
                                <div class="mb-3">
                                    <strong class="text-primary text-uppercase"><?php echo $module; ?></strong>
                                    <div class="row ms-2 mt-1">
                                        <?php foreach ($perms as $perm => $label): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input edit-perm" name="permissions[<?php echo $module; ?>_<?php echo $perm; ?>]" id="edit_perm_<?php echo $module; ?>_<?php echo $perm; ?>">
                                                <label class="form-check-label" for="edit_perm_<?php echo $module; ?>_<?php echo $perm; ?>"><?php echo $label; ?></label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="role_id" id="delete_role_id">
                <?php echo getCSRFTokenField(); ?>
                
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Delete Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the role: <strong id="delete_role_name"></strong>?</p>
                    <p class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editRole(role) {
    document.getElementById('edit_role_id').value = role.id;
    document.getElementById('edit_role_name').value = role.role_name;
    document.getElementById('edit_role_code').value = role.role_code;
    document.getElementById('edit_description').value = role.description || '';
    document.getElementById('edit_level').value = role.level || 1;
    document.getElementById('edit_is_active').checked = role.is_active == 1;
    
    // Parse permissions and check boxes
    const perms = JSON.parse(role.permissions || '{}');
    
    // Uncheck all first
    document.querySelectorAll('.edit-perm').forEach(cb => cb.checked = false);
    
    // Check based on permissions
    for (const [module, actions] of Object.entries(perms)) {
        for (const [action, enabled] of Object.entries(actions)) {
            const key = `${module}_${action}`;
            const cb = document.getElementById(`edit_perm_${module}_${action}`);
            if (cb) cb.checked = enabled;
        }
    }
    
    new bootstrap.Modal(document.getElementById('editRoleModal')).show();
}

function deleteRole(id, name) {
    document.getElementById('delete_role_id').value = id;
    document.getElementById('delete_role_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteRoleModal')).show();
}

$(document).ready(function() {
    $('#rolesTable').DataTable({
        order: [[4, 'desc']]
    });
});
</script>
