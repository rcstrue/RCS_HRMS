-- Migration: Add Bulk Edit submenu permission for existing roles
-- Run this once to add employee_bulk_edit to role_menu_permissions
-- Admin users always see all menus, so this is only needed for non-admin roles

-- Add to all existing non-admin roles
INSERT IGNORE INTO role_menu_permissions (role_id, menu_key, submenu_key, is_visible, can_view, can_edit, can_add, can_delete)
SELECT r.id, 'employee', 'employee_bulk_edit', 1, 1, 1, 0, 0
FROM roles r
WHERE r.role_code != 'admin'
AND NOT EXISTS (
    SELECT 1 FROM role_menu_permissions rmp 
    WHERE rmp.role_id = r.id 
    AND rmp.submenu_key = 'employee_bulk_edit'
);
