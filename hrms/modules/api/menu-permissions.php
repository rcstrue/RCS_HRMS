<?php
/**
 * RCS HRMS Pro - Menu Permissions API (Enhanced)
 * Handles AJAX requests for menu permission updates with submenus and actions
 */

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Only admin can manage permissions
if (!isset($_SESSION['role_code']) || $_SESSION['role_code'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'update_permission':
        // Validate CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrfToken)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
        $menuKey = sanitize($_POST['menu_key'] ?? '');
        $submenuKey = sanitize($_POST['submenu_key'] ?? '');
        $permissionType = sanitize($_POST['permission_type'] ?? 'is_visible'); // is_visible, can_view, can_add, etc.
        $value = !empty($_POST['value']) ? 1 : 0;
        
        if ($roleId <= 0 || empty($menuKey)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        // Build the permission data
        $permissionData = [$permissionType => $value];
        
        // Save the permission
        $result = $auth->saveRoleMenuPermissions($roleId, $permissionData, $menuKey, $submenuKey ?: null);
        echo json_encode($result);
        break;
        
    case 'update_all_permissions':
        // Validate CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrfToken)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
        $menuKey = sanitize($_POST['menu_key'] ?? '');
        $submenuKey = sanitize($_POST['submenu_key'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        
        if ($roleId <= 0 || empty($menuKey)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        // Sanitize permissions
        $sanitizedPermissions = [
            'is_visible' => !empty($permissions['is_visible']) ? 1 : 0,
            'can_view' => !empty($permissions['can_view']) ? 1 : 0,
            'can_add' => !empty($permissions['can_add']) ? 1 : 0,
            'can_edit' => !empty($permissions['can_edit']) ? 1 : 0,
            'can_delete' => !empty($permissions['can_delete']) ? 1 : 0,
            'can_export' => !empty($permissions['can_export']) ? 1 : 0,
            'can_import' => !empty($permissions['can_import']) ? 1 : 0,
            'can_print' => !empty($permissions['can_print']) ? 1 : 0,
        ];
        
        // Save the permission
        $result = $auth->saveDetailedPermissions($roleId, $menuKey, $submenuKey ?: null, $sanitizedPermissions);
        echo json_encode($result);
        break;
        
    case 'get_permissions':
        $roleId = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;
        
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role ID']);
            exit;
        }
        
        $permissions = $auth->getRoleMenuPermissions($roleId);
        echo json_encode(['success' => true, 'permissions' => $permissions]);
        break;
        
    case 'get_all_permissions':
        $allPermissions = $auth->getAllRoleMenuPermissions();
        echo json_encode(['success' => true, 'permissions' => $allPermissions]);
        break;
        
    case 'get_menu_structure':
        // Return the full menu structure with submenus
        $menus = $auth->getAllMenus();
        $actionTypes = $auth->getActionTypes();
        echo json_encode([
            'success' => true, 
            'menus' => $menus,
            'action_types' => $actionTypes
        ]);
        break;
        
    case 'copy_permissions':
        // Copy permissions from one role to another
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrfToken)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $sourceRoleId = isset($_POST['source_role_id']) ? (int)$_POST['source_role_id'] : 0;
        $targetRoleId = isset($_POST['target_role_id']) ? (int)$_POST['target_role_id'] : 0;
        
        if ($sourceRoleId <= 0 || $targetRoleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role IDs']);
            exit;
        }
        
        // Get source permissions
        $sourcePermissions = $auth->getRoleMenuPermissions($sourceRoleId);
        
        // Copy each permission to target role
        foreach ($sourcePermissions as $key => $perm) {
            $auth->saveDetailedPermissions(
                $targetRoleId,
                $perm['menu_key'],
                $perm['submenu_key'],
                $perm
            );
        }
        
        echo json_encode(['success' => true, 'message' => 'Permissions copied successfully.']);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
