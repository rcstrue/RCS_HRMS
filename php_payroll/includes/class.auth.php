<?php
/**
 * RCS HRMS Pro - Authentication Class
 * Handles user authentication, authorization, and session management
 */

// Constant to avoid string duplication
define('SQL_WHERE_USER_ID', 'id = :id');

class Auth {
    private $db;
    private $user = null;
    
    // Role hierarchy for access control
    private $roleHierarchy = [
        'admin' => 100,
        'hr_executive' => 80,
        'manager' => 60,
        'supervisor' => 40,
        'worker' => 20
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->checkSession();
    }
    
    // Check existing session
    private function checkSession() {
        if (isset($_SESSION['user_id'])) {
            $user = $this->db->fetch(
                "SELECT u.id, u.username, u.email, u.role_id, u.first_name, u.last_name, u.is_active,
                        r.role_name, r.role_code
                 FROM users u 
                 LEFT JOIN roles r ON u.role_id = r.id
                 WHERE u.id = :id AND u.is_active = 1",
                ['id' => $_SESSION['user_id']]
            );
            
            if ($user) {
                $this->user = $user;
            }
        }
    }
    
    // Login user
    public function login($username, $password, $remember = false) {
        // Get user with role info
        $user = $this->db->fetch(
            "SELECT u.id, u.username, u.email, u.password, u.role_id, u.first_name, u.last_name, u.is_active,
                    r.role_name, r.role_code
             FROM users u 
             LEFT JOIN roles r ON u.role_id = r.id 
             WHERE (u.username = :username OR u.email = :email)",
            ['username' => $username, 'email' => $username]
        );
        
        // Check if user exists
        if (!$user) {
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }
        
        // Check if active
        if (empty($user['is_active'])) {
            return ['success' => false, 'error' => 'Account is inactive.'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_code'] = $user['role_code'] ?? 'worker';
        $_SESSION['role_name'] = $user['role_name'] ?? 'User';
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';
        
        // Update last login
        try {
            $this->db->update('users', [
                'last_login' => date('Y-m-d H:i:s')
            ], SQL_WHERE_USER_ID, ['id' => $user['id']]);
        } catch (Exception $e) {
            // Ignore if last_login column doesn't exist
        }
        
        $this->user = $user;
        
        return [
            'success' => true,
            'message' => 'Login successful.',
            'user' => $user
        ];
    }
    
    // Logout user
    public function logout() {
        $_SESSION = [];
        session_destroy();
        $this->user = null;
    }
    
    // Check if user is logged in
    public function isLoggedIn() {
        return $this->user !== null || isset($_SESSION['user_id']);
    }
    
    // Get current user
    public function getUser() {
        return $this->user;
    }
    
    // Get user ID
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    // Get user role
    public function getRole() {
        return $_SESSION['role_code'] ?? null;
    }
    
    // Check if user has specific role
    public function hasRole($role) {
        return isset($_SESSION['role_code']) && $_SESSION['role_code'] === $role;
    }
    
    // Check if user has role level or higher
    public function hasRoleLevel($role) {
        if (!isset($_SESSION['role_code'])) {
            return false;
        }
        
        $userLevel = $this->roleHierarchy[$_SESSION['role_code']] ?? 0;
        $requiredLevel = $this->roleHierarchy[$role] ?? 0;
        
        return $userLevel >= $requiredLevel;
    }
    
    // Change password
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->db->fetch(
            "SELECT password FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $this->db->update('users', [
            'password' => $hashedPassword
        ], SQL_WHERE_USER_ID, ['id' => $userId]);
        
        return ['success' => true, 'message' => 'Password changed successfully.'];
    }
    
    // Create new user
    public function createUser($data) {
        $exists = $this->db->fetch(
            "SELECT id FROM users WHERE username = :username OR email = :email",
            ['username' => $data['username'], 'email' => $data['email']]
        );
        
        if ($exists) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        
        $userId = $this->db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $hashedPassword,
            'role_id' => $data['role_id'] ?? 5,
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'is_active' => 1
        ]);
        
        if ($userId) {
            return ['success' => true, 'message' => 'User created successfully.', 'user_id' => $userId];
        }
        
        return ['success' => false, 'message' => 'Failed to create user.'];
    }
    
    // Reset password (admin only)
    public function resetPassword($userId, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $result = $this->db->update('users', [
            'password' => $hashedPassword
        ], SQL_WHERE_USER_ID, ['id' => $userId]);
        
        if ($result !== false) {
            return ['success' => true, 'message' => 'Password reset successfully.'];
        }
        
        return ['success' => false, 'message' => 'Failed to reset password.'];
    }
    
    // Get all users (admin/hr)
    public function getUsers($filters = []) {
        $sql = "SELECT u.id, u.username, u.email, u.role_id, u.is_active, u.last_login, 
                       u.created_at, u.first_name, u.last_name, r.role_name, r.role_code
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['role_id'])) {
            $sql .= " AND u.role_id = :role_id";
            $params['role_id'] = $filters['role_id'];
        }
        
        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = :is_active";
            $params['is_active'] = $filters['is_active'];
        }
        
        $sql .= " ORDER BY u.created_at DESC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    // Get all users - alias for getUsers (backward compatibility)
    public function getAllUsers($activeOnly = true) {
        $filters = [];
        if ($activeOnly) {
            $filters['is_active'] = 1;
        }
        return $this->getUsers($filters);
    }
    
    // Get user by ID
    public function getUserById($userId) {
        return $this->db->fetch(
            "SELECT u.id, u.username, u.email, u.role_id, u.is_active, u.last_login, 
                    u.created_at, u.first_name, u.last_name, u.phone, r.role_name, r.role_code
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id",
            ['id' => $userId]
        );
    }
    
    // Update user
    public function updateUser($userId, $data) {
        $allowedFields = ['email', 'role_id', 'first_name', 'last_name', 'phone', 'is_active'];
        $updateData = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'message' => 'No data to update.'];
        }
        
        $result = $this->db->update('users', $updateData, SQL_WHERE_USER_ID, ['id' => $userId]);
        
        return ['success' => true, 'message' => 'User updated successfully.'];
    }
    
    // Delete user
    public function deleteUser($userId) {
        // Prevent deleting self
        if (isset($_SESSION['user_id']) && $userId == $_SESSION['user_id']) {
            return ['success' => false, 'message' => 'Cannot delete your own account.'];
        }
        
        $result = $this->db->delete('users', SQL_WHERE_USER_ID, ['id' => $userId]);
        
        return ['success' => true, 'message' => 'User deleted successfully.'];
    }
    
    // ============================================
    // MENU PERMISSION FUNCTIONS
    // ============================================
    
    /**
     * Get all available menu items with submenus
     */

    /**
     * Get all menus with submenus - COMPLETE LIST
     * Matches sidebar in header.php exactly
     */
    public function getAllMenus() {
        return [
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'parent' => null, 'order' => 1, 'submenus' => []],
            
            'employee' => ['label' => 'Employees', 'icon' => 'bi-people', 'parent' => null, 'order' => 2, 'submenus' => [
                'employee_list' => ['label' => 'All Employees', 'url' => 'employee/list'],
                'employee_add' => ['label' => 'Add Employee', 'url' => 'employee/add'],
                'employee_import' => ['label' => 'Import Employees', 'url' => 'employee/import'],
                'employee_documents' => ['label' => 'Documents', 'url' => 'employee/documents'],
                'employee_id_card' => ['label' => 'ID Card', 'url' => 'employee/id-card'],
            ]],
            
            'client' => ['label' => 'Clients & Units', 'icon' => 'bi-building', 'parent' => null, 'order' => 3, 'submenus' => [
                'client_list' => ['label' => 'Clients', 'url' => 'client/list'],
                'unit_list' => ['label' => 'Units', 'url' => 'unit/list'],
                'contract_list' => ['label' => 'Contracts', 'url' => 'contract/list'],
            ]],
            
            'attendance' => ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'parent' => null, 'order' => 4, 'submenus' => [
                'attendance_add' => ['label' => 'Add Attendance', 'url' => 'attendance/add'],
                'attendance_upload' => ['label' => 'Upload Attendance', 'url' => 'attendance/upload'],
                'attendance_view' => ['label' => 'View Attendance', 'url' => 'attendance/view'],
                'attendance_report' => ['label' => 'Attendance Report', 'url' => 'attendance/report'],
            ]],
            
            'advance' => ['label' => 'Advance', 'icon' => 'bi-wallet2', 'parent' => null, 'order' => 5, 'submenus' => [
                'advance_add' => ['label' => 'Add Advance', 'url' => 'advance/add'],
            ]],
            
            'payroll' => ['label' => 'Payroll', 'icon' => 'bi-cash-stack', 'parent' => null, 'order' => 6, 'submenus' => [
                'payroll_process' => ['label' => 'Process Payroll', 'url' => 'payroll/process'],
                'payroll_view' => ['label' => 'View Payroll', 'url' => 'payroll/view'],
                'payroll_salary_revision' => ['label' => 'Salary Revision', 'url' => 'payroll/salary-revision'],
                'payroll_payslips' => ['label' => 'Payslips', 'url' => 'payroll/payslips'],
                'payroll_bank_advice' => ['label' => 'Bank Advice', 'url' => 'payroll/bank-advice'],
            ]],
            
            'compliance' => ['label' => 'Compliance', 'icon' => 'bi-shield-check', 'parent' => null, 'order' => 7, 'submenus' => [
                'compliance_dashboard' => ['label' => 'Compliance Dashboard', 'url' => 'compliance/dashboard'],
                'compliance_pf' => ['label' => 'PF ECR Generator', 'url' => 'compliance/pf'],
                'compliance_esi' => ['label' => 'ESI Returns', 'url' => 'compliance/esi'],
                'compliance_pt' => ['label' => 'PT Returns', 'url' => 'compliance/pt'],
                'compliance_minimum_wages' => ['label' => 'Minimum Wages', 'url' => 'compliance/minimum-wages'],
                'compliance_mw_validation' => ['label' => 'MW Validation', 'url' => 'compliance/minimum-wage-check'],
            ]],
            
            'forms' => ['label' => 'Forms', 'icon' => 'bi-file-earmark-text', 'parent' => null, 'order' => 8, 'submenus' => [
                'forms_appointment' => ['label' => 'Appointment Letter', 'url' => 'forms/appointment'],
                'forms_form_v' => ['label' => 'Form V', 'url' => 'forms/form-v'],
                'forms_form_xvi' => ['label' => 'Form XVI', 'url' => 'forms/form-xvi'],
                'forms_form_xvii' => ['label' => 'Form XVII', 'url' => 'forms/form-xvii'],
                'forms_form_f2' => ['label' => 'Form F2', 'url' => 'forms/form-f2'],
                'forms_nomination' => ['label' => 'Nomination Forms', 'url' => 'forms/nomination'],
            ]],
            
            'deployment' => ['label' => 'Deployments', 'icon' => 'bi-geo-alt', 'parent' => null, 'order' => 9, 'submenus' => [
                'deployment_list' => ['label' => 'All Deployments', 'url' => 'deployment/list'],
                'deployment_add' => ['label' => 'New Deployment', 'url' => 'deployment/add'],
            ]],
            
            'requisition' => ['label' => 'Requisitions', 'icon' => 'bi-person-plus', 'parent' => null, 'order' => 10, 'submenus' => [
                'requisition_list' => ['label' => 'All Requisitions', 'url' => 'requisition/list'],
                'requisition_add' => ['label' => 'New Requisition', 'url' => 'requisition/add'],
            ]],
            
            'recruitment' => ['label' => 'Recruitment', 'icon' => 'bi-person-lines-fill', 'parent' => null, 'order' => 11, 'submenus' => [
                'recruitment_list' => ['label' => 'All Candidates', 'url' => 'recruitment/list'],
                'recruitment_add' => ['label' => 'Add Candidate', 'url' => 'recruitment/add'],
            ]],
            
            'billing' => ['label' => 'Billing', 'icon' => 'bi-receipt', 'parent' => null, 'order' => 12, 'submenus' => [
                'billing_list' => ['label' => 'Invoices', 'url' => 'billing/list'],
                'billing_gst_invoice' => ['label' => 'GST Invoice', 'url' => 'billing/gst-invoice'],
            ]],
            
            'timesheet' => ['label' => 'Timesheets', 'icon' => 'bi-table', 'parent' => null, 'order' => 13, 'submenus' => [
                'timesheet_list' => ['label' => 'All Timesheets', 'url' => 'timesheet/list'],
                'timesheet_create' => ['label' => 'Create Timesheet', 'url' => 'timesheet/create'],
            ]],
            
            'feedback' => ['label' => 'Client Feedback', 'icon' => 'bi-star', 'parent' => null, 'order' => 14, 'submenus' => []],
            
            'assets' => ['label' => 'Assets', 'icon' => 'bi-box-seam', 'parent' => null, 'order' => 15, 'submenus' => [
                'assets_list' => ['label' => 'All Assets', 'url' => 'assets/list'],
                'assets_issue' => ['label' => 'Issue Asset', 'url' => 'assets/issue'],
            ]],
            
            'helpdesk' => ['label' => 'Helpdesk', 'icon' => 'bi-headset', 'parent' => null, 'order' => 16, 'submenus' => []],
            
            'leave' => ['label' => 'Leave Management', 'icon' => 'bi-calendar-x', 'parent' => null, 'order' => 17, 'submenus' => []],
            
            'report' => ['label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'parent' => null, 'order' => 18, 'submenus' => [
                'report_employee' => ['label' => 'Employee Reports', 'url' => 'report/employee'],
                'report_attendance' => ['label' => 'Attendance Reports', 'url' => 'report/attendance'],
                'report_payroll' => ['label' => 'Payroll Reports', 'url' => 'report/payroll'],
                'report_compliance' => ['label' => 'Compliance Reports', 'url' => 'report/compliance'],
                'report_custom' => ['label' => 'Custom Report Builder', 'url' => 'report/custom'],
            ]],
            
            'settlement' => ['label' => 'F&F Settlement', 'icon' => 'bi-cash-coin', 'parent' => null, 'order' => 19, 'submenus' => []],
            
            'notifications' => ['label' => 'Notifications', 'icon' => 'bi-bell', 'parent' => null, 'order' => 20, 'submenus' => [
                'notifications_view' => ['label' => 'View Notifications', 'url' => 'notifications'],
                'notifications_center' => ['label' => 'Notification Center', 'url' => 'notifications/center'],
            ]],
            
            'settings' => ['label' => 'Settings', 'icon' => 'bi-gear', 'parent' => null, 'order' => 21, 'submenus' => [
                'settings_company' => ['label' => 'Company', 'url' => 'settings/company'],
                'settings_users' => ['label' => 'Users', 'url' => 'settings/users'],
                'settings_roles' => ['label' => 'Roles', 'url' => 'settings/roles'],
                'settings_payslip_templates' => ['label' => 'Payslip Templates', 'url' => 'settings/payslip-templates'],
                'settings_statutory' => ['label' => 'Statutory Rates', 'url' => 'settings/statutory'],
                'settings_image_tool' => ['label' => 'Image Tool', 'url' => 'settings/image-tool'],
            ]],
        ];
    }

    public function getActionTypes() {
        return [
            'can_view' => ['label' => 'View', 'icon' => 'bi-eye', 'description' => 'Can view records'],
            'can_add' => ['label' => 'Add', 'icon' => 'bi-plus-circle', 'description' => 'Can add/create new records'],
            'can_edit' => ['label' => 'Edit', 'icon' => 'bi-pencil', 'description' => 'Can edit existing records'],
            'can_delete' => ['label' => 'Delete', 'icon' => 'bi-trash', 'description' => 'Can delete records'],
            'can_export' => ['label' => 'Export', 'icon' => 'bi-download', 'description' => 'Can export data'],
            'can_import' => ['label' => 'Import', 'icon' => 'bi-upload', 'description' => 'Can import data'],
            'can_print' => ['label' => 'Print', 'icon' => 'bi-printer', 'description' => 'Can print data'],
        ];
    }
    
    /**
     * Create menu permissions table if not exists (enhanced version with submenus and actions)
     */
    public function ensureMenuPermissionsTable() {
        try {
            // Check if table exists with new structure
            $checkColumn = $this->db->fetch("SHOW COLUMNS FROM role_menu_permissions LIKE 'submenu_key'");
            
            if (!$checkColumn) {
                // Table exists but needs migration - add new columns
                try {
                    $this->db->query("ALTER TABLE role_menu_permissions 
                        ADD COLUMN submenu_key VARCHAR(100) DEFAULT NULL AFTER menu_key,
                        ADD COLUMN can_view TINYINT(1) DEFAULT 1 AFTER is_visible,
                        ADD COLUMN can_add TINYINT(1) DEFAULT 0 AFTER can_view,
                        ADD COLUMN can_edit TINYINT(1) DEFAULT 0 AFTER can_add,
                        ADD COLUMN can_delete TINYINT(1) DEFAULT 0 AFTER can_edit,
                        ADD COLUMN can_export TINYINT(1) DEFAULT 0 AFTER can_delete,
                        ADD COLUMN can_import TINYINT(1) DEFAULT 0 AFTER can_export,
                        ADD COLUMN can_print TINYINT(1) DEFAULT 0 AFTER can_import,
                        DROP INDEX uniq_role_menu,
                        ADD UNIQUE KEY uniq_role_menu_submenu (role_id, menu_key, submenu_key),
                        ADD INDEX idx_role_menu (role_id, menu_key),
                        ADD INDEX idx_role_submenu (role_id, submenu_key)");
                } catch (Exception $e) {
                    // Columns might already exist
                }
            }
        } catch (Exception $e) {
            // Table doesn't exist, create it
            try {
                $this->db->query("CREATE TABLE IF NOT EXISTS role_menu_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    role_id INT NOT NULL,
                    menu_key VARCHAR(50) NOT NULL COMMENT 'Main menu key',
                    submenu_key VARCHAR(100) DEFAULT NULL COMMENT 'Submenu key',
                    is_visible TINYINT(1) DEFAULT 1 COMMENT 'Visibility toggle',
                    can_view TINYINT(1) DEFAULT 1 COMMENT 'Can view records',
                    can_add TINYINT(1) DEFAULT 0 COMMENT 'Can add/create records',
                    can_edit TINYINT(1) DEFAULT 0 COMMENT 'Can edit records',
                    can_delete TINYINT(1) DEFAULT 0 COMMENT 'Can delete records',
                    can_export TINYINT(1) DEFAULT 0 COMMENT 'Can export data',
                    can_import TINYINT(1) DEFAULT 0 COMMENT 'Can import data',
                    can_print TINYINT(1) DEFAULT 0 COMMENT 'Can print data',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_role_menu_submenu (role_id, menu_key, submenu_key),
                    INDEX idx_role_menu (role_id, menu_key),
                    INDEX idx_role_submenu (role_id, submenu_key),
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Exception $e2) {
                // Table might have been created by another process
            }
        }
    }
    
    /**
     * Get menu permissions for a role (includes submenus and actions)
     */
    public function getRoleMenuPermissions($roleId) {
        $this->ensureMenuPermissionsTable();
        
        $permissions = $this->db->fetchAll(
            "SELECT menu_key, submenu_key, is_visible, can_view, can_add, can_edit, can_delete, can_export, can_import, can_print 
             FROM role_menu_permissions WHERE role_id = :role_id",
            ['role_id' => $roleId]
        );
        
        $result = [];
        foreach ($permissions as $perm) {
            $key = $perm['submenu_key'] ?: $perm['menu_key'];
            $result[$key] = [
                'menu_key' => $perm['menu_key'],
                'submenu_key' => $perm['submenu_key'],
                'is_visible' => (bool)$perm['is_visible'],
                'can_view' => (bool)$perm['can_view'],
                'can_add' => (bool)$perm['can_add'],
                'can_edit' => (bool)$perm['can_edit'],
                'can_delete' => (bool)$perm['can_delete'],
                'can_export' => (bool)$perm['can_export'],
                'can_import' => (bool)$perm['can_import'],
                'can_print' => (bool)$perm['can_print'],
            ];
        }
        
        return $result;
    }
    
    /**
     * Get all role menu permissions (for admin view) with submenus and actions
     */
    public function getAllRoleMenuPermissions() {
        $this->ensureMenuPermissionsTable();
        
        $roles = $this->db->fetchAll("SELECT id, role_name, role_code FROM roles WHERE is_active = 1 ORDER BY level DESC");
        $menus = $this->getAllMenus();
        
        $result = [];
        foreach ($roles as $role) {
            $result[$role['role_code']] = [
                'role_id' => $role['id'],
                'role_name' => $role['role_name'],
                'menus' => [],
                'submenus' => [],
                'actions' => []
            ];
            
            $permissions = $this->getRoleMenuPermissions($role['id']);
            
            // Process main menus
            foreach ($menus as $menuKey => $menuInfo) {
                // Default: admin sees all, others see based on hierarchy
                $defaultVisible = ($role['role_code'] === 'admin') ? true : false;
                $defaultActions = ($role['role_code'] === 'admin');
                
                if (isset($permissions[$menuKey])) {
                    $result[$role['role_code']]['menus'][$menuKey] = $permissions[$menuKey]['is_visible'];
                    $result[$role['role_code']]['actions'][$menuKey] = [
                        'can_view' => $permissions[$menuKey]['can_view'],
                        'can_add' => $permissions[$menuKey]['can_add'],
                        'can_edit' => $permissions[$menuKey]['can_edit'],
                        'can_delete' => $permissions[$menuKey]['can_delete'],
                        'can_export' => $permissions[$menuKey]['can_export'],
                        'can_import' => $permissions[$menuKey]['can_import'],
                        'can_print' => $permissions[$menuKey]['can_print'],
                    ];
                } else {
                    $result[$role['role_code']]['menus'][$menuKey] = $defaultVisible;
                    $result[$role['role_code']]['actions'][$menuKey] = [
                        'can_view' => $defaultActions,
                        'can_add' => $defaultActions,
                        'can_edit' => $defaultActions,
                        'can_delete' => $defaultActions,
                        'can_export' => $defaultActions,
                        'can_import' => $defaultActions,
                        'can_print' => $defaultActions,
                    ];
                }
                
                // Process submenus
                if (!empty($menuInfo['submenus'])) {
                    foreach ($menuInfo['submenus'] as $submenuKey => $submenuInfo) {
                        if (isset($permissions[$submenuKey])) {
                            $result[$role['role_code']]['submenus'][$submenuKey] = [
                                'is_visible' => $permissions[$submenuKey]['is_visible'],
                                'can_view' => $permissions[$submenuKey]['can_view'],
                                'can_add' => $permissions[$submenuKey]['can_add'],
                                'can_edit' => $permissions[$submenuKey]['can_edit'],
                                'can_delete' => $permissions[$submenuKey]['can_delete'],
                                'can_export' => $permissions[$submenuKey]['can_export'],
                                'can_import' => $permissions[$submenuKey]['can_import'],
                                'can_print' => $permissions[$submenuKey]['can_print'],
                            ];
                        } else {
                            $result[$role['role_code']]['submenus'][$submenuKey] = [
                                'is_visible' => $defaultVisible,
                                'can_view' => $defaultActions,
                                'can_add' => $defaultActions,
                                'can_edit' => $defaultActions,
                                'can_delete' => $defaultActions,
                                'can_export' => $defaultActions,
                                'can_import' => $defaultActions,
                                'can_print' => $defaultActions,
                            ];
                        }
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Save menu permissions for a role (supports submenus and actions)
     */
    public function saveRoleMenuPermissions($roleId, $permissions, $menuKey = null, $submenuKey = null) {
        $this->ensureMenuPermissionsTable();
        
        // If single permission update (from AJAX)
        if ($menuKey !== null) {
            $isVisible = isset($permissions['is_visible']) ? (int)$permissions['is_visible'] : 1;
            $canView = isset($permissions['can_view']) ? (int)$permissions['can_view'] : 1;
            $canAdd = isset($permissions['can_add']) ? (int)$permissions['can_add'] : 0;
            $canEdit = isset($permissions['can_edit']) ? (int)$permissions['can_edit'] : 0;
            $canDelete = isset($permissions['can_delete']) ? (int)$permissions['can_delete'] : 0;
            $canExport = isset($permissions['can_export']) ? (int)$permissions['can_export'] : 0;
            $canImport = isset($permissions['can_import']) ? (int)$permissions['can_import'] : 0;
            $canPrint = isset($permissions['can_print']) ? (int)$permissions['can_print'] : 0;
            
            $this->db->query(
                "INSERT INTO role_menu_permissions (role_id, menu_key, submenu_key, is_visible, can_view, can_add, can_edit, can_delete, can_export, can_import, can_print) 
                 VALUES (:role_id, :menu_key, :submenu_key, :is_visible, :can_view, :can_add, :can_edit, :can_delete, :can_export, :can_import, :can_print)
                 ON DUPLICATE KEY UPDATE 
                    is_visible = :is_visible2, 
                    can_view = :can_view2, 
                    can_add = :can_add2, 
                    can_edit = :can_edit2, 
                    can_delete = :can_delete2,
                    can_export = :can_export2,
                    can_import = :can_import2,
                    can_print = :can_print2",
                [
                    'role_id' => $roleId,
                    'menu_key' => $menuKey,
                    'submenu_key' => $submenuKey ?: $menuKey,
                    'is_visible' => $isVisible,
                    'can_view' => $canView,
                    'can_add' => $canAdd,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'can_export' => $canExport,
                    'can_import' => $canImport,
                    'can_print' => $canPrint,
                    'is_visible2' => $isVisible,
                    'can_view2' => $canView,
                    'can_add2' => $canAdd,
                    'can_edit2' => $canEdit,
                    'can_delete2' => $canDelete,
                    'can_export2' => $canExport,
                    'can_import2' => $canImport,
                    'can_print2' => $canPrint,
                ]
            );
            
            return ['success' => true, 'message' => 'Permission updated successfully.'];
        }
        
        // Bulk update from form submission
        foreach ($permissions as $key => $value) {
            // Determine if this is a menu or submenu permission
            $parts = explode('_', $key);
            $isSubmenu = count($parts) > 1;
            
            if ($isSubmenu) {
                // Find the parent menu key
                $menus = $this->getAllMenus();
                $parentMenu = null;
                foreach ($menus as $mKey => $mInfo) {
                    if (isset($mInfo['submenus'][$key])) {
                        $parentMenu = $mKey;
                        break;
                    }
                }
                
                if ($parentMenu) {
                    $this->db->query(
                        "INSERT INTO role_menu_permissions (role_id, menu_key, submenu_key, is_visible, can_view, can_add, can_edit, can_delete, can_export, can_import, can_print) 
                         VALUES (:role_id, :menu_key, :submenu_key, 1, 1, 0, 0, 0, 0, 0, 0)
                         ON DUPLICATE KEY UPDATE is_visible = :is_visible",
                        [
                            'role_id' => $roleId,
                            'menu_key' => $parentMenu,
                            'submenu_key' => $key,
                            'is_visible' => $value ? 1 : 0,
                        ]
                    );
                }
            } else {
                // Main menu permission
                $this->db->query(
                    "INSERT INTO role_menu_permissions (role_id, menu_key, submenu_key, is_visible, can_view, can_add, can_edit, can_delete, can_export, can_import, can_print) 
                     VALUES (:role_id, :menu_key, :submenu_key, :is_visible, 1, 0, 0, 0, 0, 0, 0)
                     ON DUPLICATE KEY UPDATE is_visible = :is_visible2",
                    [
                        'role_id' => $roleId,
                        'menu_key' => $key,
                        'submenu_key' => $key,
                        'is_visible' => $value ? 1 : 0,
                        'is_visible2' => $value ? 1 : 0,
                    ]
                );
            }
        }
        
        return ['success' => true, 'message' => 'Menu permissions saved successfully.'];
    }
    
    /**
     * Save detailed permissions (submenu with actions)
     */
    public function saveDetailedPermissions($roleId, $menuKey, $submenuKey, $data) {
        $this->ensureMenuPermissionsTable();
        
        $isVisible = isset($data['is_visible']) ? (int)$data['is_visible'] : 1;
        $canView = isset($data['can_view']) ? (int)$data['can_view'] : 1;
        $canAdd = isset($data['can_add']) ? (int)$data['can_add'] : 0;
        $canEdit = isset($data['can_edit']) ? (int)$data['can_edit'] : 0;
        $canDelete = isset($data['can_delete']) ? (int)$data['can_delete'] : 0;
        $canExport = isset($data['can_export']) ? (int)$data['can_export'] : 0;
        $canImport = isset($data['can_import']) ? (int)$data['can_import'] : 0;
        $canPrint = isset($data['can_print']) ? (int)$data['can_print'] : 0;
        
        $this->db->query(
            "INSERT INTO role_menu_permissions (role_id, menu_key, submenu_key, is_visible, can_view, can_add, can_edit, can_delete, can_export, can_import, can_print) 
             VALUES (:role_id, :menu_key, :submenu_key, :is_visible, :can_view, :can_add, :can_edit, :can_delete, :can_export, :can_import, :can_print)
             ON DUPLICATE KEY UPDATE 
                is_visible = :is_visible2, 
                can_view = :can_view2, 
                can_add = :can_add2, 
                can_edit = :can_edit2, 
                can_delete = :can_delete2,
                can_export = :can_export2,
                can_import = :can_import2,
                can_print = :can_print2",
            [
                'role_id' => $roleId,
                'menu_key' => $menuKey,
                'submenu_key' => $submenuKey ?: $menuKey,
                'is_visible' => $isVisible,
                'can_view' => $canView,
                'can_add' => $canAdd,
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'can_export' => $canExport,
                'can_import' => $canImport,
                'can_print' => $canPrint,
                'is_visible2' => $isVisible,
                'can_view2' => $canView,
                'can_add2' => $canAdd,
                'can_edit2' => $canEdit,
                'can_delete2' => $canDelete,
                'can_export2' => $canExport,
                'can_import2' => $canImport,
                'can_print2' => $canPrint,
            ]
        );
        
        return ['success' => true, 'message' => 'Permissions saved successfully.'];
    }
    
    /**
     * Check if current user can see a menu
     */
    public function canSeeMenu($menuKey) {
        // Admin sees everything
        if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'admin') {
            return true;
        }
        
        $this->ensureMenuPermissionsTable();
        
        $roleId = $_SESSION['role_id'] ?? null;
        if (!$roleId) {
            return false;
        }
        
        $permission = $this->db->fetch(
            "SELECT is_visible FROM role_menu_permissions WHERE role_id = :role_id AND menu_key = :menu_key AND (submenu_key IS NULL OR submenu_key = menu_key)",
            ['role_id' => $roleId, 'menu_key' => $menuKey]
        );
        
        // If no permission set, default to false (hide)
        if (!$permission) {
            return false;
        }
        
        return (bool)$permission['is_visible'];
    }
    
    /**
     * Check if current user can see a submenu
     */
    public function canSeeSubmenu($submenuKey) {
        // Admin sees everything
        if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'admin') {
            return true;
        }
        
        $this->ensureMenuPermissionsTable();
        
        $roleId = $_SESSION['role_id'] ?? null;
        if (!$roleId) {
            return false;
        }
        
        $permission = $this->db->fetch(
            "SELECT is_visible FROM role_menu_permissions WHERE role_id = :role_id AND submenu_key = :submenu_key",
            ['role_id' => $roleId, 'submenu_key' => $submenuKey]
        );
        
        // If no permission set, default to false (hide)
        if (!$permission) {
            return false;
        }
        
        return (bool)$permission['is_visible'];
    }
    
    /**
     * Check if current user can perform an action on a menu/submenu
     * @param string $action Action to check: 'view', 'add', 'edit', 'delete', 'export', 'import', 'print'
     * @param string $menuKey The menu key
     * @param string|null $submenuKey The submenu key (optional)
     * @return bool
     */
    public function canPerformAction($action, $menuKey, $submenuKey = null) {
        // Admin can do everything
        if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'admin') {
            return true;
        }
        
        $this->ensureMenuPermissionsTable();
        
        $roleId = $_SESSION['role_id'] ?? null;
        if (!$roleId) {
            return false;
        }
        
        $column = 'can_' . $action;
        $key = $submenuKey ?: $menuKey;
        
        $permission = $this->db->fetch(
            "SELECT $column FROM role_menu_permissions WHERE role_id = :role_id AND (submenu_key = :key OR (submenu_key IS NULL AND menu_key = :key2))",
            ['role_id' => $roleId, 'key' => $key, 'key2' => $key]
        );
        
        // If no permission set, default to false
        if (!$permission) {
            return false;
        }
        
        return (bool)$permission[$column];
    }
    
    /**
     * Check if user can view
     */
    public function canView($menuKey, $submenuKey = null) {
        return $this->canPerformAction('view', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can add
     */
    public function canAdd($menuKey, $submenuKey = null) {
        return $this->canPerformAction('add', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can edit
     */
    public function canEdit($menuKey, $submenuKey = null) {
        return $this->canPerformAction('edit', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can delete
     */
    public function canDelete($menuKey, $submenuKey = null) {
        return $this->canPerformAction('delete', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can export
     */
    public function canExport($menuKey, $submenuKey = null) {
        return $this->canPerformAction('export', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can import
     */
    public function canImport($menuKey, $submenuKey = null) {
        return $this->canPerformAction('import', $menuKey, $submenuKey);
    }
    
    /**
     * Check if user can print
     */
    public function canPrint($menuKey, $submenuKey = null) {
        return $this->canPerformAction('print', $menuKey, $submenuKey);
    }
    
    /**
     * Get all visible menus for current user
     */
    public function getVisibleMenus() {
        $menus = $this->getAllMenus();
        $visible = [];
        
        foreach ($menus as $key => $info) {
            if ($this->canSeeMenu($key)) {
                $visible[$key] = $info;
            }
        }
        
        return $visible;
    }
}
?>
