<?php
/**
 * Shared Helpers for Expense Module
 * Include this at the top of every expense page (after $db guard).
 */

// (manager_advance_allocations, manager_ledger, expense_settlements,
//  ess_expenses column additions — all schema managed in migrations)

// Column-existence flags used by expense pages (guaranteed by migration)
$categoryColExists   = true;
$managerIdColExists  = true;
$monthColExists      = true;

// ============================================================================
// Shared helper: formatCurrency
// ============================================================================

if (!function_exists('formatCurrency')) {
    function formatCurrency($amt) {
        return '&#8377;' . number_format((float)$amt, 2);
    }
}

// Shared helper: build scope WHERE clause for announcements
// Returns array: ["(a.target_scope = 'all' OR ...)", [':scope_role1' => ...]]
if (!function_exists('annScopeWhere')) {
    function annScopeWhere($role, $uid) {
        $isAdmin = ($role === 'admin');
        $isManager = in_array($role, ['manager', 'regional_manager']);
        if ($isAdmin) {
            // Admin sees everything
            return ['', []];
        } elseif ($isManager) {
            // Manager sees: all + managers + own (created by self)
            return ["AND (a.target_scope = 'all' OR a.target_scope = 'managers' OR a.created_by = :selfid)", [':selfid' => $uid]];
        } else {
            // Others see: all + own (created by self)
            return ["AND (a.target_scope = 'all' OR a.created_by = :selfid)", [':selfid' => $uid]];
        }
    }
}
