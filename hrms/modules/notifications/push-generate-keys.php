<?php
/**
 * Generate VAPID keys — accessed via index.php?page=notifications/push-generate-keys
 */
if (!in_array($_SESSION['role_code'] ?? '', ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied.');
    redirect('index.php?page=dashboard');
}

require_once APP_ROOT . '/includes/class.webpush.php';

try {
    $keys = WebPush::generateVapidKeys();
    updateSetting('push_vapid_public_key', $keys['public_key']);
    updateSetting('push_vapid_private_key', $keys['private_key']);
    updateSetting('push_vapid_subject', 'mailto:hr@rcsfacility.com');
    setFlash('success', 'VAPID keys generated successfully! Push notifications are ready.');
} catch (\Throwable $e) {
    setFlash('error', 'Failed to generate VAPID keys: ' . $e->getMessage());
}
redirect('index.php?page=notifications/center&tab=push');
