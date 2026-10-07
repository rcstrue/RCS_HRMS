<?php
/**
 * WhatsApp Persistent Queue — server-to-server endpoint (bot <-> HRMS)
 *
 * The Node bot owns the single sender/worker loop; this endpoint owns ALL
 * database access. The bot calls it over HTTP with X-API-Key.
 *
 * Security:
 *  - Standalone (outside the module router) so no PHP session is required.
 *  - Requires X-API-Key matching the configured bot key.
 *  - Never returns the API key, credentials, or auth-session data.
 *
 * Actions (?action=):
 *   config          GET   pacing + campaign limits for the worker
 *   status          GET   queue counters (safe, no secrets)
 *   claim           POST  atomically claim ONE due message (queued -> sending)
 *   report          POST  outcome of a claimed message + retry policy
 *   enqueue         POST  insert messages into the persistent queue
 *   pause           POST  pause the queue (paused=1)
 *   resume          POST  resume the queue (paused=0)
 *   requeue_stale   POST  move abandoned 'sending' rows back to queued
 *   cancel          POST  cancel a campaign's waiting messages
 *   retry_failed    POST  re-queue permanently failed messages
 */

define('RCS_HRMS', true);
define('APP_ROOT', __DIR__);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/whatsapp.php';

header('Content-Type: application/json');

// ── Auth: validate X-API-Key against the configured bot key ──
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($provided === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing API key']);
    exit;
}

$botKey = '';
try {
    $rows = $db->fetchAll("SELECT setting_value FROM settings WHERE setting_key = 'notif_wa_bot_key'");
    foreach ($rows as $r) {
        if (!empty($r['setting_value'])) { $botKey = (string)$r['setting_value']; break; }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration unavailable']);
    exit;
}

if ($botKey === '' || !hash_equals($botKey, (string)$provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// ── Parse request ──
$action = $_GET['action'] ?? '';
$raw    = file_get_contents('php://input');
$input  = json_decode($raw ?: '{}', true);
if (!is_array($input)) { $input = []; }
if (empty($action) && isset($input['action'])) { $action = (string)$input['action']; }

try {
    switch ($action) {

        // Pacing + limits for the worker (no secrets).
        case 'config': {
            echo json_encode(['success' => true, 'config' => waQueueConfig()]);
            break;
        }

        // Queue counters.
        case 'status': {
            echo json_encode(['success' => true, 'queue' => waQueueStats()]);
            break;
        }

        // Atomically claim ONE due message. Returns {message:null} when idle.
        case 'claim': {
            $row = waQueueClaim();
            if (!$row) {
                echo json_encode(['success' => true, 'message' => null, 'queue' => waQueueStats()]);
                break;
            }
            // Send only what the bot needs — no internal columns leak.
            echo json_encode([
                'success' => true,
                'message' => [
                    'log_id'  => (int)$row['id'],
                    'number'  => (string)$row['mobile'],
                    'text'    => (string)$row['message'],
                    'attempt' => (int)$row['attempts'] + 1,
                    'campaign_id' => $row['campaign_id'],
                ],
            ]);
            break;
        }

        // Report outcome of a claimed message.
        case 'report': {
            $logId = (int)($input['log_id'] ?? 0);
            if ($logId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'log_id is required']);
                break;
            }
            $ok        = !empty($input['ok']);
            $messageId = isset($input['messageId']) ? (string)$input['messageId'] : null;
            $error     = isset($input['error']) && $input['error'] !== null ? (string)$input['error'] : null;

            $result = waQueueReport($logId, $ok, $messageId, $error);
            $result['queue'] = waQueueStats();
            echo json_encode($result);
            break;
        }

        // Insert messages into the persistent queue (used by the bot's /send-bulk
        // forwarder and by HRMS callers that prefer queueing directly).
        case 'enqueue': {
            $messages = $input['messages'] ?? [];
            if (!is_array($messages) || empty($messages)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'messages array is required']);
                break;
            }
            $campaignId = isset($input['campaign_id']) ? (string)$input['campaign_id'] : null;
            $employeeId = isset($input['employee_id']) ? (int)$input['employee_id'] : null;

            $res = waQueueEnqueue($messages, $campaignId, $employeeId);
            echo json_encode([
                'success'     => $res['success'],
                'queued'      => $res['queued'],
                'skipped'     => $res['skipped'],
                'campaign_id' => $res['campaign_id'],
            ]);
            break;
        }

        case 'pause': {
            waQueuePause(true);
            echo json_encode(['success' => true, 'paused' => true, 'queue' => waQueueStats()]);
            break;
        }

        case 'resume': {
            waQueuePause(false);
            echo json_encode(['success' => true, 'paused' => false, 'queue' => waQueueStats()]);
            break;
        }

        case 'requeue_stale': {
            $sec = isset($input['older_than_s']) ? (int)$input['older_than_s'] : 300;
            $n = waQueueRequeueStale($sec);
            echo json_encode(['success' => true, 'requeued' => $n, 'queue' => waQueueStats()]);
            break;
        }

        case 'cancel': {
            $cid = (string)($input['campaign_id'] ?? '');
            if ($cid === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'campaign_id is required']);
                break;
            }
            $n = waQueueCancelCampaign($cid);
            echo json_encode(['success' => true, 'cancelled' => $n, 'queue' => waQueueStats()]);
            break;
        }

        case 'retry_failed': {
            $cid = isset($input['campaign_id']) ? (string)$input['campaign_id'] : null;
            $n = waQueueRetryFailed($cid);
            echo json_encode(['success' => true, 'requeued' => $n, 'queue' => waQueueStats()]);
            break;
        }

        default: {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Queue operation failed']);
    error_log('wa-queue error: ' . $e->getMessage());
}
