<?php
/**
 * ESS API — Centralized Authorization Guards
 *
 * Single source of truth for role-based access control (RBAC) and ownership
 * checks across the ESS API. Included after config.php + helpers.php.
 *
 * Provides:
 *   - requireRole(array $roles, ?mysqli $conn = null): string  — requireAuth + role gate
 *   - scopedEmployeeId(string $authId, array $managerRoles, ?mysqli $conn = null): string
 *       IDOR guard for GET endpoints: returns $authId unless caller has an elevated role
 *   - requireOwnershipOrRole(string $authId, string $ownerId, array $managerRoles, ?mysqli $conn = null): void
 *       Ownership gate for PUT/DELETE on a specific resource
 *
 * Design notes:
 *   - All functions call requireAuth() internally (or expect an already-authed $authId).
 *   - A mysqli connection may be passed in to avoid opening a second one; if omitted,
 *     a transient connection is opened and closed for the role lookup.
 *   - On failure these functions call jsonOutput() + exit, so callers can treat them
 *     as guard clauses.
 *
 * Role hierarchy (ESS):
 *   admin > regional_manager > manager > supervisor > employee
 *
 * Convenience role sets are provided as constants for reuse across endpoints.
 */

if (!defined('ESS_GUARD_ROLES_ADMIN')) {
    define('ESS_GUARD_ROLES_ADMIN', ['admin', 'regional_manager']);           // org-wide admin actions
    define('ESS_GUARD_ROLES_HR', ['admin', 'regional_manager', 'hr']);        // HR-style actions
    define('ESS_GUARD_ROLES_MANAGER', ['admin', 'regional_manager', 'manager']); // approve / view team
    define('ESS_GUARD_ROLES_SUPERVISOR', ['admin', 'regional_manager', 'manager', 'supervisor']); // view team
}

if (!function_exists('_guard_lookupRole')) {
    /**
     * Internal: look up the caller's ESS role. Opens a transient connection if
     * none is provided. Returns a lowercased role string (or '' on failure).
     */
    function _guard_lookupRole(string $authId, ?mysqli $conn = null): string
    {
        $ownConn = false;
        if ($conn === null) {
            $conn = getDbConnection();
            $ownConn = true;
        }
        $role = strtolower((string) (getEmployeeRole($conn, $authId) ?? ''));
        if ($ownConn) {
            $conn->close();
        }
        return $role;
    }
}

if (!function_exists('requireRole')) {
    /**
     * Require an authenticated JWT AND that the caller's role is in $allowedRoles.
     * Returns the authenticated employee_id on success; exits with 403 on failure.
     *
     * @param array    $allowedRoles Lowercased role strings, e.g. ESS_GUARD_ROLES_MANAGER
     * @param ?mysqli  $conn          Optional existing connection to reuse
     * @return string  The authenticated employee_id
     */
    function requireRole(array $allowedRoles, ?mysqli $conn = null): string
    {
        $authId = requireAuth();
        $role   = _guard_lookupRole($authId, $conn);
        if (!in_array($role, $allowedRoles, true)) {
            jsonOutput([
                'success' => false,
                'error'   => 'Forbidden: this action requires one of: ' . implode(', ', $allowedRoles),
            ], 403);
        }
        return $authId;
    }
}

if (!function_exists('scopedEmployeeId')) {
    /**
     * IDOR guard for GET list endpoints.
     *
     * Returns the employee_id the caller is permitted to query:
     *   - If no employee_id is supplied, or it equals the caller's own id, returns $authId.
     *   - If a different employee_id is supplied, the caller must hold one of
     *     $managerRoles; otherwise the request is rejected with 403.
     *
     * This replaces the insecure pattern:  $queryEmployeeId = $_GET['employee_id'] ?? $authId;
     *
     * @param string   $authId        The authenticated employee_id (from requireAuth)
     * @param array    $managerRoles  Roles allowed to query other users' data
     * @param ?mysqli  $conn          Optional existing connection to reuse
     * @return string  The employee_id safe to query
     */
    function scopedEmployeeId(string $authId, array $managerRoles, ?mysqli $conn = null): string
    {
        $requested = $_GET['employee_id'] ?? $authId;
        if ($requested === $authId || $requested === '') {
            return $authId;
        }
        // Requesting SOMEONE ELSE's data — require an elevated role.
        $role = _guard_lookupRole($authId, $conn);
        if (!in_array($role, $managerRoles, true)) {
            jsonOutput([
                'success' => false,
                'error'   => 'Forbidden: you can only access your own records.',
            ], 403);
        }
        return $requested;
    }
}

if (!function_exists('requireOwnershipOrRole')) {
    /**
     * Ownership guard for PUT/DELETE on a specific resource.
     *
     * Allows the action if $resourceOwnerId === $authId (the owner) OR the caller
     * holds one of $managerRoles. Otherwise exits with 403.
     *
     * @param string   $authId          The authenticated employee_id
     * @param string   $resourceOwnerId The owner/assignee of the resource being modified
     * @param array    $managerRoles    Roles allowed to act on resources they don't own
     * @param ?mysqli  $conn            Optional existing connection to reuse
     */
    function requireOwnershipOrRole(string $authId, string $resourceOwnerId, array $managerRoles, ?mysqli $conn = null): void
    {
        if ($resourceOwnerId !== '' && $resourceOwnerId === $authId) {
            return; // owner — allowed
        }
        $role = _guard_lookupRole($authId, $conn);
        if (!in_array($role, $managerRoles, true)) {
            jsonOutput([
                'success' => false,
                'error'   => 'Forbidden: you do not own this resource.',
            ], 403);
        }
    }
}
