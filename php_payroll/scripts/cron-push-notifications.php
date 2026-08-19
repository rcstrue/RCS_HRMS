<?php
/**
 * RCS HRMS — Cron: Process Pending Push Notifications
 * 
 * Run via cron every 5 minutes:
 *   */5 * * * * cd /path/to/hrms && php scripts/cron-push-notifications.php
 *
 * Processes all push_notification_queue rows where:
 *   - status = 'pending'
 *   - scheduled_at IS NULL (immediate) OR scheduled_at <= NOW()
 */

define('RCS_HRMS', true);
require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/class.webpush.php';

$now = date('Y-m-d H:i:s');

// Get VAPID keys
$vapidPriv = getSetting('push_vapid_private_key');
$vapidPub  = getSetting('push_vapid_public_key');
$vapidSub  = getSetting('push_vapid_subject') ?: 'mailto:hr@rcsfacility.com';

if (!$vapidPriv || !$vapidPub) {
    exit("[cron-push] No VAPID keys configured. Generate them from the Notification Center.\n");
}

// Find pending notifications that are due
$pending = $db->fetchAll("
    SELECT * FROM push_notification_queue 
    WHERE status = 'pending' 
    AND (scheduled_at IS NULL OR scheduled_at <= ?)
    ORDER BY created_at ASC
    LIMIT 10
", [$now]);

if (empty($pending)) {
    exit("[cron-push] $now — No pending notifications.\n");
}

$wp = new WebPush($vapidPriv, $vapidPub, $vapidSub);

foreach ($pending as $item) {
    echo "[cron-push] Processing #{$item['id']}: {$item['title']}... ";

    try {
        $db->exec("UPDATE push_notification_queue SET status = 'sending' WHERE id = ?", [$item['id']]);

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
            $db->exec("UPDATE push_notification_queue SET status = 'failed', errors = 'No subscribers found', sent_at = NOW() WHERE id = ?", [$item['id']]);
            echo "No subscribers.\n";
            continue;
        }

        $stats = $wp->sendBatch($subs, $item['title'], $item['body'], $item['url'], $item['icon']);

        // Clean up expired subscriptions
        $expiredEndpoints = [];
        foreach ($subs as $sub) {
            $testResult = $wp->send($sub, '', '');
            if (!empty($testResult['expired'])) {
                $expiredEndpoints[] = $sub['endpoint'];
            }
        }
        if (!empty($expiredEndpoints)) {
            $epPh = implode(',', array_fill(0, count($expiredEndpoints), '?'));
            $db->exec("DELETE FROM push_subscriptions WHERE endpoint IN ($epPh)", $expiredEndpoints);
        }

        $db->exec("UPDATE push_notification_queue SET status = 'completed', sent_count = ?, failed_count = ?, expired_count = ?, errors = ?, sent_at = NOW() WHERE id = ?", [
            $stats['sent'], $stats['failed'], $stats['expired'],
            json_encode(array_slice($stats['errors'], 0, 10)), $item['id']
        ]);

        echo "Done: {$stats['sent']} sent, {$stats['failed']} failed, {$stats['expired']} expired.\n";

    } catch (\Throwable $e) {
        $db->exec("UPDATE push_notification_queue SET status = 'failed', errors = ? WHERE id = ?", [$e->getMessage(), $item['id']]);
        echo "ERROR: {$e->getMessage()}\n";
    }

    // Small delay between batches
    usleep(500000);
}

echo "[cron-push] Finished processing.\n";
