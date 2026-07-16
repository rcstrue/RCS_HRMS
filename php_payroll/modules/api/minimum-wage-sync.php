<?php
/**
 * RCS HRMS Pro - Minimum Wage Sync API Endpoint (Pure PHP)
 * 
 * Triggers the minimum wage sync from the browser.
 * No Node.js required — uses PHP cURL + DOMDocument.
 * 
 * POST actions:
 *   run-sync        — Sync all states (or single state via $_POST['state'])
 *   run-slug-setup  — Add slug column + auto-populate slugs
 */

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Only admin can trigger sync
if (!isset($_SESSION['role_code']) || $_SESSION['role_code'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

// Validate request integrity (simple hash-based nonce, not CSRF token
// because COMODO WAF Rule 211220 blocks POST args containing '<?')
$nonce = $_POST['sync_nonce'] ?? '';
$validWindow = 300; // 5 minutes
$expected = hash('sha256', session_id() . '|' . floor(time() / $validWindow));
if (!hash_equals($expected, $nonce)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Load the sync class
require_once APP_ROOT . '/includes/class.minimumwagesync.php';

$sync = new MinimumWageSync($db);

switch ($action) {

    case 'run-sync':
        // Increase time limit for multiple states (5 minutes)
        set_time_limit(300);

        $state = $_POST['state'] ?? null;
        $dryRun = !empty($_POST['dry_run']);

        $result = $sync->runSync($state, $dryRun);
        echo json_encode($result);
        break;

    case 'run-slug-setup':
        try {
            $output = $sync->ensureSlugs();
            echo json_encode([
                'success' => true,
                'message' => 'Slug setup completed.',
                'output' => implode("\n", $output),
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Slug setup failed.',
                'error' => $e->getMessage(),
            ]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}