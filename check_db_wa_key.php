<?php
require_once __DIR__ . '/hrms/config/config.php';
require_once __DIR__ . '/hrms/includes/database.php';
$db = Database::getInstance();
$r = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'notif_wa_bot_key'");
if (!empty($r['setting_value'])) {
    echo "DB notif_wa_bot_key exists. Length: " . strlen($r['setting_value']) . "\n";
    echo "Status: DB key configured (value not shown per security rule)\n";
} else {
    echo "DB notif_wa_bot_key is empty/missing\n";
}
