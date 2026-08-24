<?php
/**
 * RCS HRMS — Cron Worker: Process Pending Push Notifications
 * 
 * Run via cron every 2 minutes:
 *   */2 * * * * cd /path/to/hrms && php scripts/cron-push-notifications.php >> /var/log/push-cron.log 2>&1
 *
 * Architecture:
 *   - Admin queues notifications (status = 'pending')
 *   - This worker is the SOLE sender — never the browser
 *   - Atomic claim prevents duplicate sends (race-safe)
 *   - Failed sends retry with exponential backoff (up to max_attempts)
 *   - Stale 'sending' rows (crashed workers) are auto-recovered
 */

define('RCS_HRMS', true);
require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/class.webpush.php';

$now = date('Y-m-d H:i:s');
$MAX_BATCH = 10;
$RETRY_DELAY_BASE = 60; // seconds — delay = RETRY_DELAY_BASE * 2^(attempt-1)

// ── Self-heal: ensure retry columns exist ────────────────────────
try {
    $cols = $db->fetchAll("SHOW COLUMNS FROM push_notification_queue");
    $colNames = array_column($cols, 'Field');
    $migrations = [
        'attempt_count'  => "INT UNSIGNED DEFAULT 0",
        'max_attempts'   => "TINYINT UNSIGNED DEFAULT 5",
        'next_retry_at'  => "DATETIME DEFAULT NULL",
        'last_error'     => "TEXT DEFAULT NULL",
    ];
    foreach ($migrations as $col => $def) {
        if (!in_array($col, $colNames)) {
            $db->exec("ALTER TABLE push_notification_queue ADD COLUMN `$col` $def");
            echo "[cron-push] Migrated: added column `$col`\n";
        }
    }
} catch (\Throwable $e) {
    echo "[cron-push] Migration check failed: " . $e->getMessage() . "\n";
}

// ── Recover stale 'sending' rows (worker crashed) ────────────────
// If a row has been 'sending' for more than 10 minutes, the worker
// likely crashed. Reset it to 'pending' so it can be retried.
try {
    $recovered = $db->query("
        UPDATE push_notification_queue 
        SET status = 'pending', next_retry_at = NULL 
        WHERE status = 'sending' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ")->rowCount();
    if ($recovered > 0) {
        echo "[cron-push] Recovered $recovered stale 'sending' row(s)\n";
    }
} catch (\Throwable $e) {
    // Non-critical, just log
    echo "[cron-push] Stale recovery check failed: " . $e->getMessage() . "\n";
}

// ── Get VAPID keys ────────────────────────────────────────────────
$vapidPriv = getSetting('push_vapid_private_key');
$vapidPub  = getSetting('push_vapid_public_key');
$vapidSub  = getSetting('push_vapid_subject') ?: 'mailto:hr@rcsfacility.com';

if (!$vapidPriv || !$vapidPub) {
    exit("[cron-push] $now — No VAPID keys configured. Generate them from the Notification Center.\n");
}

// ── Find due notifications ───────────────────────────────────────
// Conditions:
//   1. status = 'pending'
//   2. scheduled_at is NULL (immediate) or has passed
//   3. next_retry_at is NULL (first attempt) or has passed (retry due)
$pending = $db->fetchAll("
    SELECT * FROM push_notification_queue 
    WHERE status = 'pending' 
    AND (scheduled_at IS NULL OR scheduled_at <= ?)
    AND (next_retry_at IS NULL OR next_retry_at <= ?)
    ORDER BY created_at ASC
    LIMIT $MAX_BATCH
", [$now, $now]);

if (empty($pending)) {
    exit("[cron-push] $now — No pending notifications.\n");
}

echo "[cron-push] $now — Found " . count($pending) . " pending notification(s)\n";

$wp = new WebPush($vapidPriv, $vapidPub, $vapidSub);

foreach ($pending as $item) {
    $id = (int)$item['id'];
    $maxAttempts = (int)($item['max_attempts'] ?? 5);
    $currentAttempt = (int)($item['attempt_count'] ?? 0) + 1;

    // ── Atomic claim: pending → sending ───────────────────────────
    // This is the critical race-condition fix.
    // Only one worker can claim a given row because the WHERE clause
    // includes status = 'pending'. If two workers race, only the
    // first UPDATE matches 1 row; the second matches 0 rows.
    $claimStmt = $db->query("
        UPDATE push_notification_queue 
        SET status = 'sending', attempt_count = ? 
        WHERE id = ? AND status = 'pending'
    ", [$currentAttempt, $id]);
    $claimed = $claimStmt->rowCount();

    if ($claimed === 0) {
        echo "[cron-push] #{$id} Skipped — already claimed by another worker\n";
        continue;
    }

    echo "[cron-push] #{$id} Claimed (attempt {$currentAttempt}/{$maxAttempts}): {$item['title']}... ";

    try {
        // Get target subscriptions
        $subs = [];
        if ($item['target'] === 'all') {
            $subs = $db->fetchAll("SELECT * FROM push_subscriptions");
        } elseif ($item['target'] === 'selected' && !empty($item['employee_ids'])) {
            $ids = array_map('trim', explode(',', $item['employee_ids']));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $subs = $db->fetchAll("SELECT * FROM push_subscriptions WHERE employee_id IN ($ph)", $ids);
        }

        if (empty($subs)) {
            $db->query("
                UPDATE push_notification_queue 
                SET status = 'failed', last_error = 'No subscribers found', sent_at = NOW() 
                WHERE id = ?
            ", [$id]);
            echo "No subscribers.\n";
            continue;
        }

        $stats = $wp->sendBatch($subs, $item['title'], $item['body'], $item['url'], $item['icon']);

        // Clean expired subscriptions (tracked during sendBatch, no second pass)
        if (!empty($stats['expired_endpoints'])) {
            $epPh = implode(',', array_fill(0, count($stats['expired_endpoints']), '?'));
            $db->query("DELETE FROM push_subscriptions WHERE endpoint IN ($epPh)", $stats['expired_endpoints']);
            echo "(cleaned " . count($stats['expired_endpoints']) . " expired) ";
        }

        // Determine result
        $allFailed = ($stats['sent'] === 0 && count($subs) > 0);
        $anyError = !empty($stats['errors']);

        if ($allFailed && $anyError) {
            // ── All sends failed — schedule retry or mark permanent failure
            if ($currentAttempt < $maxAttempts) {
                // Exponential backoff: 60s, 120s, 240s, 480s...
                $retryDelay = $RETRY_DELAY_BASE * pow(2, $currentAttempt - 1);
                $retryDelay = min($retryDelay, 3600); // cap at 1 hour

                $db->query("
                    UPDATE push_notification_queue 
                    SET status = 'pending', 
                        last_error = ?, 
                        next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                        failed_count = ?
                    WHERE id = ?
                ", [
                    json_encode(array_slice($stats['errors'], 0, 5)),
                    $retryDelay,
                    $stats['failed'],
                    $id
                ]);
                echo "ALL FAILED — will retry in {$retryDelay}s (attempt {$currentAttempt}/{$maxAttempts})\n";
            } else {
                // Max attempts reached — permanent failure
                $db->query("
                    UPDATE push_notification_queue 
                    SET status = 'failed', 
                        last_error = ?, 
                        errors = ?, 
                        sent_at = NOW()
                    WHERE id = ?
                ", [
                    'Max attempts (' . $maxAttempts . ') reached. Last: ' . json_encode(array_slice($stats['errors'], 0, 5)),
                    json_encode(array_slice($stats['errors'], 0, 10)),
                    $id
                ]);
                echo "PERMANENTLY FAILED after {$maxAttempts} attempts\n";
            }
        } else {
            // ── At least some succeeded — mark completed ─────────────
            $db->query("
                UPDATE push_notification_queue 
                SET status = 'completed', 
                    sent_count = ?, 
                    failed_count = ?, 
                    expired_count = ?, 
                    errors = ?, 
                    sent_at = NOW()
                WHERE id = ?
            ", [
                $stats['sent'],
                $stats['failed'],
                $stats['expired'],
                json_encode(array_slice($stats['errors'], 0, 10)),
                $id
            ]);
            echo "Done: {$stats['sent']} sent, {$stats['failed']} failed, {$stats['expired']} expired.\n";
        }

    } catch (\Throwable $e) {
        // Exception during send — retry or fail
        $errorMsg = $e->getMessage();
        if ($currentAttempt < $maxAttempts) {
            $retryDelay = $RETRY_DELAY_BASE * pow(2, $currentAttempt - 1);
            $retryDelay = min($retryDelay, 3600);
            $db->query("
                UPDATE push_notification_queue 
                SET status = 'pending', 
                    last_error = ?, 
                    next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ", [$errorMsg, $retryDelay, $id]);
            echo "EXCEPTION — will retry in {$retryDelay}s: $errorMsg\n";
        } else {
            $db->query("
                UPDATE push_notification_queue 
                SET status = 'failed', last_error = ?, sent_at = NOW() 
                WHERE id = ?
            ", [$errorMsg, $id]);
            echo "EXCEPTION — PERMANENTLY FAILED: $errorMsg\n";
        }
    }

    // Small delay between notifications
    usleep(200000);
}

echo "[cron-push] Finished.\n";
