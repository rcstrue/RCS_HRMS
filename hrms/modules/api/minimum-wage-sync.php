<?php
/**
 * RCS HRMS Pro - Minimum Wage Sync API Endpoint
 *
 * Standalone JSON endpoint — routed via index.php?page=api/minimum-wage-sync
 * The framework sets Content-Type: application/json and exits before
 * any HTML template, so this file returns ONLY JSON.
 *
 * POST actions:
 *   ajax_action=list-states    — Slugs the caller should walk (chunked runs)
 *   ajax_action=run-sync       — Sync ONE state (state=<slug>), or a bounded
 *                                slice of all states when state is omitted
 *   ajax_action=run-slug-setup — Auto-populate Simpliance slugs
 *
 * Every response carries a fresh `nonce`, because a full 36-state run takes
 * minutes and the page nonce is only minted for a 5-minute window.
 */

// Auth: matches the compliance module access table in index.php
// ($moduleConfig['compliance'] = admin, hr_executive, hr). Previously this file
// demanded 'admin', so HR / HR-Executive users — who can open the page — always
// got "Access denied. Admin only." from the buttons.
$mwRole = $_SESSION['role_code'] ?? '';
if (!in_array($mwRole, ['admin', 'hr_executive', 'hr'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Compliance access required.']);
    exit;
}

$validWindow = 300;

/**
 * Mint the request nonce for the current session.
 * SHA-256 hex — safe from COMODO WAF Rule 211220.
 */
function mwSyncNonce() {
    return hash('sha256', session_id() . '|' . floor(time() / 300));
}

/**
 * Validate a nonce against the current and the previous window.
 * A 5-minute window with no tolerance means a page left open for a few minutes
 * fails with "Invalid or expired request" the moment the button is clicked.
 */
function mwSyncNonceValid($nonce) {
    if (!is_string($nonce) || $nonce === '') return false;
    $now = (int)floor(time() / 300);
    foreach ([$now, $now - 1] as $window) {
        if (hash_equals(hash('sha256', session_id() . '|' . $window), $nonce)) {
            return true;
        }
    }
    return false;
}

/**
 * Emit a JSON payload, always refreshed with the current nonce.
 */
function mwSyncRespond(array $payload) {
    $payload['nonce'] = mwSyncNonce();
    echo json_encode($payload);
}

if (!mwSyncNonceValid($_POST['sync_nonce'] ?? '')) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired request. Please refresh the page and try again.',
    ]);
    exit;
}

$action = $_POST['ajax_action'] ?? '';

// Load the sync class
require_once APP_ROOT . '/includes/class.minimumwagesync.php';
$sync = new MinimumWageSync($db);

switch ($action) {

    case 'list-states':
        // Lets the browser sync one state per request so no single HTTP request
        // can outlive the web-server / proxy timeout.
        $stateFilter = trim((string)($_POST['state'] ?? '')) ?: null;
        mwSyncRespond($sync->listStates($stateFilter));
        break;

    case 'run-sync':
        set_time_limit(300);
        $state  = trim((string)($_POST['state'] ?? ''));
        $state  = ($state === '' || $state === 'all') ? null : $state;
        $dryRun = !empty($_POST['dry_run']);

        // Bulk runs stop after the class time budget and hand back the states
        // still to do; a named state always runs to completion.
        $budget = $state === null ? MinimumWageSync::REQUEST_TIME_BUDGET : 0;
        mwSyncRespond($sync->runSync($state, $dryRun, $budget));
        break;

    case 'run-slug-setup':
        try {
            $output = $sync->ensureSlugs();
            mwSyncRespond([
                'success' => true,
                'message' => 'Slug setup completed.',
                'output'  => implode("\n", $output),
            ]);
        } catch (Exception $e) {
            mwSyncRespond([
                'success' => false,
                'message' => 'Slug setup failed.',
                'error'   => $e->getMessage(),
            ]);
        }
        break;

    default:
        mwSyncRespond(['success' => false, 'message' => 'Unknown action.']);
        break;
}
