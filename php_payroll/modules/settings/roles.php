<?php
/**
 * RCS HRMS Pro - Role Management
 * Manages roles and their permissions.
 * Permissions are mapped 1:1 with sidebar menu modules.
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Manage Roles';

// Get all roles
$roles = $db->fetchAll("SELECT * FROM roles ORDER BY level DESC");

/**
 * Available permissions - mapped to sidebar menu modules.
 * When a module has 'view' permission, its sidebar menu item becomes visible.
 * Sub-actions (add, edit, delete, etc.) control button-level access within pages.
 *
 * To add a new module: add an entry below AND add the sidebar menu item in header.php.
 */
$availablePermissions = [
    'dashboard' => [
        'view' => 'View Dashboard',
    ],
    'employee' => [
        'view'   => 'View Employees',
        'add'    => 'Add Employee',
        'edit'   => 'Edit Employee',
        'delete' => 'Delete Employee',
        'import' => 'Import Employees',
        'export' => 'Export Employees',
    ],
    'attendance' => [
        'view'   => 'View Attendance',
        'add'    => 'Add Attendance',
        'edit'   => 'Edit Attendance',
        'import' => 'Import Attendance',
        'export' => 'Export Attendance',
    ],
    'payroll' => [
        'view'    => 'View Payroll',
        'process' => 'Process Payroll',
        'approve' => 'Approve Payroll',
        'export'  => 'Export Payroll',
    ],
    'client' => [
        'view'   => 'View Clients',
        'add'    => 'Add Client',
        'edit'   => 'Edit Client',
        'delete' => 'Delete Client',
    ],
    'unit' => [
        'view'   => 'View Units',
        'add'    => 'Add Unit',
        'edit'   => 'Edit Unit',
        'delete' => 'Delete Unit',
    ],
    'forms' => [
        'view'   => 'View Statutory Forms',
        'manage' => 'Manage Forms',
    ],
    'leave' => [
        'view'   => 'View Leave',
        'manage' => 'Manage Leave',
    ],
    'report' => [
        'view'   => 'View Reports',
        'export' => 'Export Reports',
    ],
    'settings' => [
        'view'   => 'View Settings',
        'manage' => 'Manage Settings',
    ],
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
 * Sync role permissions to role_menu_permissions table for sidebar visibility.
 * Maps each permission module to its corresponding sidebar menu key.
 */
if (!function_exists('syncMenuPermissions')) {
    function syncMenuPermissions($db, $roleId, $permissions) {
        if (!menuPermissionsTableExists($db)) {
            return false;
        }
        
        // Map permission module keys to sidebar menu keys
        $menuMap = [
            'dashboard'  => 'dashboard',
            'employee'   => 'employee',
            'attendance' => 'attendance',
            'payroll'    => 'payroll',
            'client'     => 'client',
            'unit'       => 'unit',
            'forms'      => 'forms',
            'leave'      => 'leave',
            'report'     => 'report',
            'settings'   => 'settings',
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
                
                $db->insert('role_menu_permissions', [
                    'role_id'     => $roleId,
                    'menu_key'    => $menuKey,
                    'submenu_key' => null,
                    'is_visible'  => $isVisible,
                    'can_view'    => $isVisible,
                    'can_add'     => isset($permissions[$menuKey]['add']) ? 1 : 0,
                    'can_edit'    => isset($permissions[$menuKey]['edit']) ? 1 : 0,
                    'can_delete'  => isset($permissions[$menuKey]['delete']) ? 1 : 0,
                ]);
                
                // Handle submenus
                if (!empty($menuInfo['submenus'])) {
                    foreach ($menuInfo['submenus'] as $submenuKey => $submenuInfo) {
                        $db->insert('role_menu_permissions', [
                            'role_id'     => $roleId,
                            'menu_key'    => $menuKey,
                            'submenu_key' => $submenuKey,
                            'is_visible'  => $isVisible,
                            'can_view'    => $isVisible,
                            'can_add'     => 0,
                            'can_edit'    => 0,
                            'can_delete'  => 0,
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
        
        $existing = $db->fetch("SELECT id FROM roles WHERE role_code = :code", ['code' => $roleCode]);
        
        if ($existing) {
            setFlash('error', 'Role code already exists!');
        } else {
            $db->insert('roles', [
                'role_name'   => $roleName,
                'role_code'   => $roleCode,
                'description' => $description,
                'permissions' => $permissionsJson,
                'level'       => $level,
                'is_active'   => $isActive,
            ]);
            
            $roleId = $db->lastInsertId();
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
            'role_name'   => $roleName,
            'description' => $description,
            'permissions' => $permissionsJson,
            'level'       => $level,
            'is_active'   => $isActive,
        ], 'id = :id', ['id' => $roleId]);
        
        syncMenuPermissions($db, $roleId, $permissions);
        logActivity('update', 'roles', $roleId, "Updated role: $roleName");
        setFlash('success', 'Role updated successfully!');
        redirect('index.php?page=settings/roles');
    }
    
    if ($action === 'delete' && isset($_POST['role_id'])) {
        $roleId = (int)$_POST['role_id'];
        $userCount = $db->fetch("SELECT COUNT(*) as count FROM users WHERE role_id = :id", ['id' => $roleId]);
        
        if ($userCount['count'] > 0) {
            setFlash('error', 'Cannot delete role. It is assigned to ' . $userCount['count'] . ' user(s).');
        } else {
            try {
                if (menuPermissionsTableExists($db)) {
                    $db->query("DELETE FROM role_menu_permissions WHERE role_id = :id", ['id' => $roleId]);
                }
            } catch (Exception $e) {}
            
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

// Module icons for the permission display
$moduleIcons = [
    'dashboard'  => 'bi-speedometer2',
    'employee'   => 'bi-people',
    'attendance' => 'bi-calendar-check',
    'payroll'    => 'bi-cash-stack',
    'client'     => 'bi-building',
    'unit'       => 'bi-geo-alt',
    'forms'      => 'bi-file-earmark-text',
    'leave'      => 'bi-calendar-x',
    'report'     => 'bi-graph-up',
    'settings'   => 'bi-gear',
];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Manage Roles</h4>
                <small class="text-muted">Permissions map directly to sidebar menu modules</small>
            </div>
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
                                    <?php 
                                    $moduleCount = 0;
                                    foreach ($perms as $module => $actions) {
                                        if (isset($actions['view'])) $moduleCount++;
                                    }
                                    if ($moduleCount > 0): ?>
                                    <small class="text-muted d-block mt-1"><?php echo $moduleCount; ?> module(s) accessible</small>
                                    <?php endif; ?>
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

<?php
// Helper to render permission checkboxes (reused in add + edit modals)
function renderPermissionCheckboxes($availablePermissions, $moduleIcons, $prefix = 'perm') {
    foreach ($availablePermissions as $module => $perms):
        $icon = $moduleIcons[$module] ?? 'bi-box';
?>
        <div class="mb-3">
            <div class="d-flex align-items-center mb-1">
                <i class="bi <?php echo $icon; ?> me-2 text-primary"></i>
                <strong class="text-uppercase small"><?php echo ucfirst($module); ?></strong>
                <button type="button" class="btn btn-link btn-sm p-0 ms-auto" 
                        onclick="toggleModulePerms('<?php echo $prefix; ?>', '<?php echo $module; ?>')">
                    Toggle All
                </button>
            </div>
            <div class="row ms-3">
                <?php foreach ($perms as $perm => $label): ?>
                <div class="col-lg-4 col-md-6 col-sm-6 mb-1">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input <?php echo $prefix; ?>-<?php echo $module; ?>" 
                               name="permissions[<?php echo $module; ?>_<?php echo $perm; ?>]" 
                               id="<?php echo $prefix; ?>_<?php echo $module; ?>_<?php echo $perm; ?>">
                        <label class="form-check-label small" for="<?php echo $prefix; ?>_<?php echo $module; ?>_<?php echo $perm; ?>">
                            <?php echo $label; ?>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
<?php endforeach;
}
?>

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
                            <label class="form-label fw-bold"><i class="bi bi-key me-1"></i>Permissions</label>
                            <small class="text-muted d-block mb-2">Each module maps to a sidebar menu. "View" controls sidebar visibility; other actions control button-level access.</small>
                            <div class="border rounded p-3" style="max-height: 350px; overflow-y: auto;">
                                <?php renderPermissionCheckboxes($availablePermissions, $moduleIcons, 'perm'); ?>
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
                            <label class="form-label fw-bold"><i class="bi bi-key me-1"></i>Permissions</label>
                            <div class="border rounded p-3" style="max-height: 350px; overflow-y: auto;">
                                <?php renderPermissionCheckboxes($availablePermissions, $moduleIcons, 'edit_perm'); ?>
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
    
    var perms = JSON.parse(role.permissions || '{}');
    document.querySelectorAll('[id^="edit_perm_"]').forEach(function(cb) { cb.checked = false; });
    
    for (var mod in perms) {
        for (var action in perms[mod]) {
            var cb = document.getElementById('edit_perm_' + mod + '_' + action);
            if (cb) cb.checked = perms[mod][action];
        }
    }
    
    new bootstrap.Modal(document.getElementById('editRoleModal')).show();
}

function deleteRole(id, name) {
    document.getElementById('delete_role_id').value = id;
    document.getElementById('delete_role_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteRoleModal')).show();
}

function toggleModulePerms(prefix, module) {
    var boxes = document.querySelectorAll('.' + prefix + '-' + module);
    var allChecked = Array.from(boxes).every(function(cb) { return cb.checked; });
    boxes.forEach(function(cb) { cb.checked = !allChecked; });
}

$(document).ready(function() {
    $('#rolesTable').DataTable({
        order: [[4, 'desc']]
    });
});
</script>
