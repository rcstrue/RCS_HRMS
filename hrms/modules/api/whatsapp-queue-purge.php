<?php
/**
 * HRMS Proxy: WhatsApp Queue Purge
 * POST — admin, CSRF protected; cancels ALL pending queued/sending/retry_wait
 * rows in whatsapp_logs.
 *
 * Used to safely drain the queue before re-linking a blocked WhatsApp account,
 * so the bot doesn't immediately blast 100+ messages on a fresh link (which
 * would re-trigger the block).
 *
 * Already-sent and permanently-failed rows are NOT touched.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';

session_start();
if (!isset($_SESSION['user_id'])||empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$allowed=['admin','hr_executive','hr'];
if (!in_array($_SESSION['role_code']??'',$allowed,true)){ http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit; }

if (!validateCSRFToken($_POST['csrf_token']??'')) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
}

// Use the existing queue function — it logs each cancelled row to the JSONL
// audit trail and sets status='cancelled' (distinct from 'failed').
$purged = waQueueCancelAllPending();

echo json_encode([
    'success' => $purged > 0 || true,  // success even if 0 rows (queue was empty)
    'purged'  => $purged,
    'message' => $purged > 0
        ? "Purged {$purged} pending message(s). Queue is now empty — safe to re-link WhatsApp."
        : 'Queue was already empty. Nothing to purge.',
]);
