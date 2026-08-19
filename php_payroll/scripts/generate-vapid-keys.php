<?php
/**
 * RCS HRMS — Generate & store VAPID keys for Web Push
 * Run ONCE: php scripts/generate-vapid-keys.php
 * Or run from HRMS admin via: index.php?page=notifications/push&action=generate-keys
 */

// When run from CLI, bootstrap the app
if (php_sapi_name() === 'cli') {
    define('RCS_HRMS', true);
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/../includes/class.webpush.php';

try {
    $keys = WebPush::generateVapidKeys();

    // Store in settings table
    updateSetting('push_vapid_public_key', $keys['public_key']);
    updateSetting('push_vapid_private_key', $keys['private_key']);
    updateSetting('push_vapid_subject', 'mailto:hr@rcsfacility.com');

    if (php_sapi_name() === 'cli') {
        echo "VAPID keys generated and saved!\n";
        echo "Public Key:  " . $keys['public_key'] . "\n";
        echo "Private Key: " . $keys['private_key'] . "\n";
    } else {
        setFlash('success', 'VAPID keys generated successfully! Push notifications are ready.');
        redirect('index.php?page=notifications/center&tab=push');
    }
} catch (\Throwable $e) {
    if (php_sapi_name() === 'cli') {
        echo "ERROR: " . $e->getMessage() . "\n";
    } else {
        setFlash('error', 'Failed to generate VAPID keys: ' . $e->getMessage());
        redirect('index.php?page=notifications/center&tab=push');
    }
}
