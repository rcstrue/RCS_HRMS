<?php
/**
 * RCS HRMS Pro - Minimum Wage Sync API Endpoint
 *
 * Standalone JSON endpoint — routed via index.php?page=api/minimum-wage-sync
 * The framework sets Content-Type: application/json and exits before
 * any HTML template, so this file returns ONLY JSON.
 *
 * POST actions:
 *   ajax_action=run-sync        — Sync all or single state
 *   ajax_action=run-slug-setup  — Auto-populate Simpliance slugs
 */

// Auth: admin only
if (!isset($_SESSION['role_code']) || $_SESSION['role_code'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

// Nonce validation (SHA-256 hex — safe from COMODO WAF Rule 211220)
$nonce = $_POST['sync_nonce'] ?? '';
$validWindow = 300;
$expected = hash('sha256', session_id() . '|' . floor(time() / $validWindow));
if (!hash_equals($expected, $nonce)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
    exit;
}

$action = $_POST['ajax_action'] ?? '';

// Load the sync class
require_once APP_ROOT . '/includes/class.minimumwagesync.php';
$sync = new MinimumWageSync($db);

switch ($action) {

    case 'run-sync':
        set_time_limit(300);
        $state  = $_POST['state'] ?? null;
        $dryRun = !empty($_POST['dry_run']);
        echo json_encode($sync->runSync($state, $dryRun));
        break;

    case 'run-slug-setup':
        try {
            $output = $sync->ensureSlugs();
            echo json_encode([
                'success' => true,
                'message' => 'Slug setup completed.',
                'output'  => implode("\n", $output),
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Slug setup failed.',
                'error'   => $e->getMessage(),
            ]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}