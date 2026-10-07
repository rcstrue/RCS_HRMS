<?php
// SAFE comparison — never prints either secret
// Reads HRMS DB setting and compares length + match against bot reference

require_once dirname(__FILE__) . '/../../config/config.php';
require_once dirname(__FILE__) . '/../../includes/database.php';

$db = Database::getInstance();
$row = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'notif_wa_bot_key'");
$hrmsKeyLen = ($row && !empty($row['setting_value'])) ? strlen($row['setting_value']) : 0;

// Bot reference (from workspace wa.js line 14): uses process.env.WA_API_KEY || fallback
// We only compare lengths and report match status, never values
$fallbackRef = 'RCS_HRMS_SECURE_KEY_982374982374';
$fallbackLen = strlen($fallbackRef);

// Check if bot env is set (read-only check from server env if available)
// We don't access PM2 env directly; just report what the bot file says
$envWa = getenv('WA_API_KEY') ?: '';
$envLen = strlen($envWa);

echo "HRMS DB key length: $hrmsKeyLen\n";
echo "Bot fallback length (reference): $fallbackLen\n";
echo "Bot env WA_API_KEY length: $envLen (0 = not set in this env)\n";

if ($envLen > 0 && $envLen === $hrmsKeyLen) {
    echo "Status: BOT ENV KEY matches HRMS DB key length → likely aligned\n";
} elseif ($envLen === 0 && $hrmsKeyLen === $fallbackLen) {
    echo "Status: Bot will use fallback; HRMS DB matches fallback length → alignable\n";
} elseif ($envLen > 0 && $envLen !== $hrmsKeyLen) {
    echo "Status: MISMATCH — bot uses ENVT var (len $envLen) but HRMS DB is len $hrmsKeyLen\n";
    echo "Action: Set PM2 env WA_API_KEY to match HRMS DB, OR update DB to match env\n";
} else {
    echo "Status: CHECK REQUIRED — lengths differ or env unknown\n";
}
echo "Do NOT expose keys in output.\n";
