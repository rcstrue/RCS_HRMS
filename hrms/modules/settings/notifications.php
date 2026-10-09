<?php
/**
 * RCS HRMS Pro - Notification Settings
 * Configure SMS, Email, WhatsApp Bot API keys
 */

$pageTitle = 'Notification Settings';

// Check access
if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied.');
    redirect('index.php?page=dashboard');
}

$notification = new Notification();

// WhatsApp helper functions (waApiCall, waGetConfig) live in a flat-function
// file that isn't autoloaded — load it once here so we can fetch live queue
// status from the bot.
if (!function_exists('waApiCall')) {
    require_once __DIR__ . '/../../includes/whatsapp.php';
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    $fields = [
        'notif_sms_api_key' => $_POST['sms_api_key'] ?? '',
        'notif_sms_provider' => $_POST['sms_provider'] ?? 'fast2sms',
        'notif_email_host' => $_POST['email_host'] ?? 'smtp.gmail.com',
        'notif_email_user' => $_POST['email_user'] ?? '',
        'notif_email_pass' => $_POST['email_pass'] ?? '',
        'notif_wa_bot_url' => rtrim($_POST['wa_bot_url'] ?? '', '/'),
        'notif_wa_bot_key' => $_POST['wa_bot_key'] ?? ''
    ];

    foreach ($fields as $key => $value) {
        if (!empty($value)) {
            updateSetting($key, $value);
        }
    }

    // Queue pacing config (bulk-safe redesign Option 2). All keys are
    // optional; missing keys fall back to the defaults in waQueueConfig().
    // Hard clamps there prevent an aggressive configuration from taking effect.
    $queueFields = [
        'wa_queue_interval_min_s'         => 'queue_interval_min_s',
        'wa_queue_interval_max_s'         => 'queue_interval_max_s',
        'wa_queue_batch_limit'            => 'queue_batch_limit',
        'wa_queue_batch_cooldown_s'       => 'queue_batch_cooldown_s',
        'wa_queue_day_limit'              => 'queue_day_limit',
        'wa_queue_connect_cooldown_s'     => 'queue_connect_cooldown_s',
        'wa_queue_drain_cap_per_connection' => 'queue_drain_cap_per_connection',
    ];
    foreach ($queueFields as $settingKey => $postKey) {
        $val = $_POST[$postKey] ?? '';
        if ($val !== '' && ctype_digit((string)$val)) {
            updateSetting($settingKey, (int)$val);
        }
    }
    // Pause/resume toggle for the queue (separate from per-connection drain cap pause).
    updateSetting('wa_queue_paused', isset($_POST['queue_paused']) ? '1' : '0');

    setFlash('success', 'Notification settings saved successfully!');
    redirect('index.php?page=settings/notifications');
}

// Load current settings
$settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'notif_%' OR setting_key LIKE 'wa_queue_%'");
$currentSettings = [];
foreach ($settings as $s) {
    $currentSettings[$s['setting_key']] = $s['setting_value'];
}

// Get WhatsApp bot status
$waBot = $notification->getWhatsAppBotStatus();

// Live queue status — fetched from the bot over the same X-API-Key auth used
// for /send-bulk. If the bot is unreachable, the panel degrades to "unknown".
$queueStatus = ['success' => false];
try {
    $queueStatus = waApiCall('/api/queue-status', [], 8);
    if (!isset($queueStatus['data'])) { $queueStatus = ['data' => [], 'success' => false]; }
} catch (Exception $e) {
    $queueStatus = ['data' => [], 'success' => false, 'error' => $e->getMessage()];
}
$q = $queueStatus['data'] ?? [];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title"><i class="bi bi-bell me-2"></i>Notification Settings</h1>
            <p class="text-muted">Configure SMS, Email, and WhatsApp Bot API settings</p>
        </div>
    </div>
</div>

<form method="POST">
            <?php echo getCSRFTokenField(); ?>
    <div class="row">
        <!-- WhatsApp Bot Settings -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-whatsapp me-2"></i>WhatsApp Bot (Auto-Send)</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>How it works:</strong> Run the WhatsApp Bot on your server/VPS, scan QR once with WhatsApp mobile, then all messages are sent automatically from your WhatsApp.
                    </div>
                    
                    <!-- Bot Status -->
                    <div class="mb-3 p-3 rounded border">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-<?php echo $waBot['connected'] ? 'success' : 'secondary'; ?>">
                                <?php echo $waBot['connected'] ? '🟢 Connected' : '🔴 Offline'; ?>
                            </span>
                            <span class="text-muted small"><?php echo sanitize($waBot['message']); ?></span>
                        </div>
                        <?php if ($waBot['connected']): ?>
                        <small class="text-muted">
                            <i class="bi bi-phone me-1"></i><?php echo sanitize($waBot['name'] ?? ''); ?> 
                            (<?php echo sanitize($waBot['phone'] ?? ''); ?>) |
                            Sent: <?php echo $waBot['messagesSent'] ?? 0; ?> |
                            Queue: <?php echo $waBot['queueLength'] ?? 0; ?>
                        </small>
                        <?php endif; ?>

                        <?php
                        // Recovery alert: when the bot is offline AND has a hard-stop
                        // reason (401 / device_removed / conflict), the session files
                        // are stale. The /api/login handler now auto-clears them, so
                        // the operator just needs to click "Login WhatsApp" — no
                        // terminal access required. This alert makes that clear.
                        $hardStop = !empty($q['hardStop']) ? (string)$q['hardStop'] : '';
                        // Post-block cooldown — the bot refuses login for 24h after
                        // a device_removed/401 hard-stop, to prevent the re-link →
                        // immediate re-block loop. The cooldown timestamp is
                        // exposed in /api/status.
                        $cooldownMs = isset($q['blockCooldownRemainingMs']) ? max(0, (int)$q['blockCooldownRemainingMs']) : 0;
                        $cooldownActive = $cooldownMs > 0;
                        $showPurgeTip = !$cooldownActive;  // show the "purge first" tip only when cooldown is NOT active
                        if (!$waBot['connected'] && $cooldownActive):
                        ?>
                        <div class="alert alert-warning mt-2 mb-0 small">
                            <i class="bi bi-shield-lock me-1"></i>
                            <strong>Post-block cooldown active.</strong>
                            WhatsApp was recently blocked (device_removed / 401).
                            Login is refused for <strong><?php echo ceil($cooldownMs / 3600000); ?>h</strong> more
                            (until <?php echo date('d M Y H:i', time() + $cooldownMs); ?>).
                            <hr class="my-2">
                            Re-linking immediately after a block often triggers another block.
                            <strong>Steps:</strong>
                            <ol class="mb-0">
                                <li>Wait for the cooldown to expire.</li>
                                <li>Go to <strong>Notifications → Send History</strong> and click <strong>Purge Queue</strong> to cancel all pending messages.</li>
                                <li>Then come back here and click <strong>Login WhatsApp</strong>.</li>
                            </ol>
                        </div>
                        <?php elseif (!$waBot['connected'] && $hardStop !== ''): ?>
                        <div class="alert alert-danger mt-2 mb-0 small">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <strong>WhatsApp session is invalid.</strong>
                            The bot was disconnected with: <code><?php echo sanitize($hardStop); ?></code>
                            <hr class="my-2">
                            <strong>Recovery:</strong> Click <strong>Login WhatsApp</strong> below —
                            it will automatically clear the stale session and generate a fresh QR
                            for you to scan. No terminal access needed.
                            <?php if ($showPurgeTip): ?>
                            <br><small class="text-muted">Tip: <strong>Purge the queue first</strong> (Send History tab → Purge Queue) so the fresh session doesn't immediately blast pending messages.</small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- WhatsApp Login / Logout / Reconnect Buttons -->
                    <div class="d-flex gap-2 mb-3">
                        <?php if ($waBot['connected']): ?>
                        <a href="#" onclick="confirmRefreshWA()" class="btn btn-outline-primary btn-sm" title="Refresh connection status">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh Status
                        </a>
                        <a href="#" onclick="showLogoutConfirm()" class="btn btn-outline-danger btn-sm" title="Log out of WhatsApp bot (requires re-login)">
                            <i class="bi bi-box-arrow-right me-1"></i>Logout WhatsApp
                        </a>
                        <?php else: ?>
                        <a href="#" onclick="showLoginWA()" class="btn btn-success btn-sm" title="Start WhatsApp login (QR scan)">
                            <i class="bi bi-qr-code me-1"></i>Login with QR
                        </a>
                        <a href="#" onclick="showPairingWA()" class="btn btn-outline-success btn-sm" title="Login with phone code (no QR scan needed)">
                            <i class="bi bi-key me-1"></i>Login with Phone Code
                        </a>
                        <a href="#" onclick="showReconnectWA()" class="btn btn-outline-secondary btn-sm" title="Reconnect to existing session">
                            <i class="bi bi-arrow-repeat me-1"></i>Reconnect
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bot API URL *</label>
                        <input type="url" class="form-control" name="wa_bot_url" 
                               value="<?php echo sanitize($currentSettings['notif_wa_bot_url'] ?? ''); ?>"
                               placeholder="http://your-server:3000">
                        <small class="text-muted">URL of your WhatsApp Bot service (e.g., http://localhost:3000)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bot API Key *</label>
                        <input type="text" class="form-control" name="wa_bot_key" 
                               value="<?php echo sanitize($currentSettings['notif_wa_bot_key'] ?? ''); ?>"
                               placeholder="rcs-hrms-secret-key-2026">
                        <small class="text-muted">Secret key for authenticating with the bot</small>
                    </div>
                    
                    <div class="alert" style="background:#e8f5e9;border-color:#c8e6c9;color:#2e7d32;">
                        <h6><i class="bi bi-lightbulb me-1"></i>Setup Steps</h6>
                        <ol class="small mb-0">
                            <li>Install Node.js on your server/VPS</li>
                            <li>Upload <code>whatsapp-bot/</code> folder to server</li>
                            <li>Run <code>npm install</code> then <code>npm start</code></li>
                            <li>Open <code>http://your-server:3000</code> in browser</li>
                            <li>Scan the QR code with WhatsApp mobile</li>
                            <li>Enter Bot URL and API Key above → Save</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- WhatsApp Queue Pacing (bulk-safe redesign Option 2) -->
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0"><i class="bi bi-speedometer2 me-2"></i>WhatsApp Queue Pacing &amp; Live Status</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small mb-3">
                        Controls how fast the bulk WhatsApp queue drains. The defaults are
                        conservative ("human-paced assistant") to avoid tripping WhatsApp's
                        automation heuristics. Leave a field blank to keep the saved value;
                        values are clamped server-side to safe ranges (interval ≥ 5s, batch ≤ 200,
                        day ≤ 2000, drain cap ≤ 500).
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Min interval (s)</label>
                            <input type="number" class="form-control" name="queue_interval_min_s" min="5" max="600"
                                   value="<?php echo (int)($currentSettings['wa_queue_interval_min_s'] ?? 20); ?>"
                                   placeholder="20">
                            <small class="text-muted">Lower bound of jittered gap between sends</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max interval (s)</label>
                            <input type="number" class="form-control" name="queue_interval_max_s" min="5" max="900"
                                   value="<?php echo (int)($currentSettings['wa_queue_interval_max_s'] ?? 45); ?>"
                                   placeholder="45">
                            <small class="text-muted">Upper bound of jittered gap between sends</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Batch limit</label>
                            <input type="number" class="form-control" name="queue_batch_limit" min="1" max="200"
                                   value="<?php echo (int)($currentSettings['wa_queue_batch_limit'] ?? 25); ?>"
                                   placeholder="25">
                            <small class="text-muted">Sends before the batch cooldown kicks in</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Batch cooldown (s)</label>
                            <input type="number" class="form-control" name="queue_batch_cooldown_s" min="0" max="3600"
                                   value="<?php echo (int)($currentSettings['wa_queue_batch_cooldown_s'] ?? 300); ?>"
                                   placeholder="300">
                            <small class="text-muted">Breather after each batch (e.g. 300 = 5 min)</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Day limit</label>
                            <input type="number" class="form-control" name="queue_day_limit" min="1" max="2000"
                                   value="<?php echo (int)($currentSettings['wa_queue_day_limit'] ?? 200); ?>"
                                   placeholder="200">
                            <small class="text-muted">Hard cap per WhatsApp number per day</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Connect cooldown (s)</label>
                            <input type="number" class="form-control" name="queue_connect_cooldown_s" min="0" max="600"
                                   value="<?php echo (int)($currentSettings['wa_queue_connect_cooldown_s'] ?? 120); ?>"
                                   placeholder="120">
                            <small class="text-muted">Settling time after (re)connect before the worker starts</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Drain cap / connection</label>
                            <input type="number" class="form-control" name="queue_drain_cap_per_connection" min="1" max="500"
                                   value="<?php echo (int)($currentSettings['wa_queue_drain_cap_per_connection'] ?? 50); ?>"
                                   placeholder="50">
                            <small class="text-muted">Max sends per single connection; prevents backlog burst on restart</small>
                        </div>
                        <div class="col-md-3 d-flex align-items-center">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="queue_paused" name="queue_paused"
                                       <?php echo (int)($currentSettings['wa_queue_paused'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="queue_paused">Pause queue globally</label>
                                <small class="d-block text-muted">Stops the worker; queued rows stay in the DB.</small>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="text-primary"><i class="bi bi-activity me-1"></i>Live Queue Status</h6>
                    <?php if (!empty($queueStatus['success']) && !empty($q)): ?>
                        <div class="row g-3 small">
                            <div class="col-md-2"><strong>Connection:</strong> <?php echo !empty($q['connected']) ? '<span class="text-success">connected</span>' : '<span class="text-danger">disconnected</span>'; ?></div>
                            <div class="col-md-2"><strong>Queued:</strong> <?php echo (int)($q['queued'] ?? 0); ?></div>
                            <div class="col-md-2"><strong>Sending:</strong> <?php echo (int)($q['sending'] ?? 0); ?></div>
                            <div class="col-md-2"><strong>Sent (today):</strong> <?php echo (int)($q['sent_today'] ?? 0); ?> / <?php echo (int)($q['day_limit'] ?? 0); ?></div>
                            <div class="col-md-2"><strong>Retry wait:</strong> <?php echo (int)($q['retry_wait'] ?? 0); ?></div>
                            <div class="col-md-2"><strong>Failed:</strong> <?php echo (int)($q['failed'] ?? 0); ?></div>
                            <div class="col-md-3"><strong>Queue paused:</strong> <?php echo !empty($q['paused']) ? '<span class="text-warning">yes (admin)</span>' : '<span class="text-success">no</span>'; ?></div>
                            <div class="col-md-3"><strong>Drain cap paused:</strong> <?php echo !empty($q['drainCapPaused']) ? '<span class="text-warning">yes (' . (int)($q['sentInConnection'] ?? 0) . ' sent this session)</span>' : '<span class="text-success">no</span>'; ?></div>
                            <div class="col-md-6"><strong>Hard stop reason:</strong>
                                <?php if (!empty($q['hardStop'])): ?>
                                    <span class="text-danger"><?php echo sanitize((string)$q['hardStop']); ?> — manual login required.</span>
                                <?php else: ?>
                                    <span class="text-success">none</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($q['day_limit_hit'])): ?>
                            <div class="alert alert-warning mt-3 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i>Daily limit reached — the queue will resume automatically at the next day boundary.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0 small"><i class="bi bi-info-circle me-1"></i>Could not reach the WhatsApp bot to fetch live status. Check the Bot API URL and that the bot process is running (PM2: <code>pm2 status whatsapp-bot</code>).</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SMS Settings -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-phone me-2"></i>SMS Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">SMS Provider</label>
                        <select class="form-select" name="sms_provider">
                            <option value="fast2sms" <?php echo ($currentSettings['notif_sms_provider'] ?? '') == 'fast2sms' ? 'selected' : ''; ?>>Fast2SMS (Free)</option>
                            <option value="textlocal" <?php echo ($currentSettings['notif_sms_provider'] ?? '') == 'textlocal' ? 'selected' : ''; ?>>TextLocal</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">API Key</label>
                        <input type="text" class="form-control" name="sms_api_key" 
                               value="<?php echo sanitize($currentSettings['notif_sms_api_key'] ?? ''); ?>"
                               placeholder="Enter your SMS API key">
                        <small class="text-muted">
                            Fast2SMS: Get from <a href="https://www.fast2sms.com" target="_blank">fast2sms.com</a><br>
                            TextLocal: Get from <a href="https://api.textlocal.in" target="_blank">textlocal.in</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Email Settings -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-envelope me-2"></i>Email Settings (SMTP)</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" class="form-control" name="email_host" 
                               value="<?php echo sanitize($currentSettings['notif_email_host'] ?? 'smtp.gmail.com'); ?>"
                               placeholder="smtp.gmail.com">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SMTP Username (Email)</label>
                        <input type="email" class="form-control" name="email_user" 
                               value="<?php echo sanitize($currentSettings['notif_email_user'] ?? ''); ?>"
                               placeholder="your-email@gmail.com">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SMTP Password (App Password)</label>
                        <input type="password" class="form-control" name="email_pass" 
                               value="<?php echo sanitize($currentSettings['notif_email_pass'] ?? ''); ?>"
                               placeholder="Your app password">
                        <small class="text-muted">
                            For Gmail: Use <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a>, not your regular password
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Save Button -->
    <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check-lg me-2"></i>Save All Settings
        </button>
    </div>
</form>

<!-- WhatsApp Login / QR Modal (hidden by default; shown via JS) -->
<div id="wa-login-modal" class="modal fade" tabindex="-1" aria-hidden="true" style="display:none;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-whatsapp me-2"></i>WhatsApp Login</h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeLoginWA()" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" id="wa-modal-body">
                <p class="text-muted">Connecting to WhatsApp bot...</p>
                <div id="wa-qr-area" style="margin:15px auto; max-width:260px; text-align:center;"></div>
                <p class="small text-muted" id="wa-qr-instruction">Open WhatsApp → Linked Devices → Link a Device → Scan QR</p>
                <div id="wa-modal-status" class="small mb-2 text-info">Waiting for QR code...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLoginWA()">Cancel</button>
                <button type="button" class="btn btn-outline-info" onclick="pollQR()">Refresh QR</button>
            </div>
        </div>
    </div>
</div>

<!-- WhatsApp Pairing Code Modal (alternative to QR — enter code on phone) -->
<div id="wa-pairing-modal" class="modal fade" tabindex="-1" aria-hidden="true" style="display:none;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Login with Phone Code</h5>
                <button type="button" class="btn-close btn-close-white" onclick="closePairingWA()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Enter phone number -->
                <div id="wa-pairing-step1">
                    <p class="text-muted small">Enter the WhatsApp number of the account you want to link. This is <strong>your own</strong> WhatsApp number (the one you'll use to send messages from), not a recipient.</p>
                    <div class="input-group mb-3">
                        <span class="input-group-text">+</span>
                        <input type="tel" class="form-control form-control-lg" id="wa-pairing-phone"
                               placeholder="917400135181"
                               value=""
                               maxlength="15">
                    </div>
                    <small class="text-muted d-block mb-3">Include country code (e.g. 91 for India). 10–15 digits, no spaces or +.</small>
                    <button type="button" class="btn btn-success btn-lg w-100" onclick="generatePairingCode()">
                        <i class="bi bi-key me-1"></i>Generate Pairing Code
                    </button>
                </div>

                <!-- Step 2: Display code + instructions -->
                <div id="wa-pairing-step2" style="display:none;">
                    <div class="alert alert-success text-center">
                        <strong>Pairing code generated!</strong>
                        <div class="my-3">
                            <span class="display-4 fw-bold text-success font-monospace" id="wa-pairing-code-display" style="letter-spacing:3px;">--------</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="copyPairingCode()">
                            <i class="bi bi-clipboard me-1"></i>Copy Code
                        </button>
                    </div>
                    <div class="small text-muted">
                        <h6 class="text-primary"><i class="bi bi-phone me-1"></i>On your phone:</h6>
                        <ol class="mb-2">
                            <li>Open <strong>WhatsApp</strong></li>
                            <li>Tap <strong>Settings</strong> (iPhone) or <strong>⋮</strong> menu (Android)</li>
                            <li>Tap <strong>Linked Devices</strong></li>
                            <li>Tap <strong>Link with phone number instead</strong></li>
                            <li>Enter the code above</li>
                        </ol>
                        <div class="alert alert-warning mb-0"><i class="bi bi-clock me-1"></i>The code expires in ~60 seconds. Enter it quickly on your phone.</div>
                    </div>
                    <div id="wa-pairing-status" class="text-center mt-3 small text-info">
                        <div class="spinner-border spinner-border-sm me-1"></span>Waiting for phone confirmation...
                    </div>
                </div>

                <!-- Error display -->
                <div id="wa-pairing-error" class="alert alert-danger mt-3 mb-0" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePairingWA()">Cancel</button>
                <button type="button" class="btn btn-outline-success" id="wa-pairing-regenerate" style="display:none;" onclick="generatePairingCode()">
                    <i class="bi bi-arrow-repeat me-1"></i>Generate New Code
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// WhatsApp Login / Logout / QR Polling (client-side only; server proxies all calls via PHP)
//
// QR login flow improvements:
//  - Handles the 429 post-block cooldown response (shows the wait message
//    instead of polling forever)
//  - Polls every 1500ms (was 2500ms) for faster QR detection
//  - 90-second timeout: if no connection after 90s, shows "QR expired" and
//    stops polling (Baileys QRs expire after ~20s; the bot auto-regenerates
//    but the user needs to know to re-scan)
//  - Shows "Connecting..." state after the QR disappears (Baileys is
//    doing the initial sync — this takes 5-15 seconds and the user must
//    NOT close the modal during this phase)
//  - Detects QR refresh (new QR string) and shows a brief "QR refreshed"
//    flash so the user knows to re-scan
let waLoginStartTime = 0;
let waLastQrString = null;
let waScanDetected = false;

function showLoginWA() {
    document.getElementById('wa-login-modal').style.display = 'block';
    document.getElementById('wa-login-modal').classList.add('show');
    document.getElementById('wa-qr-area').innerHTML = '<div class="spinner-border spinner-border-sm text-success"></div> <span>Waiting for QR...</span>';
    document.getElementById('wa-modal-status').textContent = 'Starting WhatsApp authentication...';
    document.getElementById('wa-modal-status').className = 'small mb-2 text-info';

    // Call PHP proxy to trigger /api/login server-side. We now AWAIT the
    // response so we can detect a 429 cooldown and show the right message
    // instead of polling forever.
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    fetch('index.php?page=api/whatsapp-login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + csrfToken
    }).then(r => {
        // Accept 200 (success), 429 (cooldown), 409 (already connecting),
        // and 502 (bot unreachable but PHP proxy returned our error JSON).
        // Other statuses (403, 500) are real errors.
        if (!r.ok && r.status !== 502 && r.status !== 429 && r.status !== 409) {
            throw new Error('Login request failed (HTTP ' + r.status + ')');
        }
        return r.text().then(text => {
            try { return JSON.parse(text); }
            catch { return { success: false, error: 'Bad response from bot' }; }
        });
    }).then(data => {
        // If the login was refused (e.g. cooldown active, bot unreachable),
        // show the error and DON'T start polling.
        if (data && data.error && data.error.includes('blocked')) {
            // Cooldown message from the bot
            document.getElementById('wa-qr-area').innerHTML = '<div class="text-warning"><i class="bi bi-shield-lock me-1"></i>Post-block cooldown active</div>';
            document.getElementById('wa-modal-status').innerHTML = data.error;
            document.getElementById('wa-modal-status').className = 'small mb-2 text-warning';
            return;
        }
        if (data && data.error && data.error.includes('unreachable')) {
            document.getElementById('wa-qr-area').innerHTML = '<div class="text-danger"><i class="bi bi-x-circle me-1"></i>Bot unreachable</div>';
            document.getElementById('wa-modal-status').innerHTML = 'Could not reach the WhatsApp bot. Check that PM2 is running: <code>pm2 status whatsapp-bot</code>';
            document.getElementById('wa-modal-status').className = 'small mb-2 text-danger';
            return;
        }
        // Login started OK — begin polling for QR
        waLoginStartTime = Date.now();
        waLastQrString = null;
        waScanDetected = false;
        window.waPollInterval = setInterval(pollQR, 1500);
        pollQR();
    }).catch(err => {
        document.getElementById('wa-qr-area').innerHTML = '<div class="text-danger"><i class="bi bi-x-circle me-1"></i>Request failed</div>';
        document.getElementById('wa-modal-status').textContent = err.message || 'Could not start login.';
        document.getElementById('wa-modal-status').className = 'small mb-2 text-danger';
    });
}

function closeLoginWA() {
    clearInterval(window.waPollInterval);
    document.getElementById('wa-login-modal').style.display = 'none';
    document.getElementById('wa-login-modal').classList.remove('show');
    waLastQrString = null;
    waScanDetected = false;
}

function pollQR() {
    // Timeout check — after 90 seconds, stop polling and tell the user
    // the QR expired. Baileys auto-regenerates QRs, but if the connection
    // hasn't succeeded in 90s something is wrong.
    if (waLoginStartTime > 0 && (Date.now() - waLoginStartTime) > 90000) {
        clearInterval(window.waPollInterval);
        document.getElementById('wa-qr-area').innerHTML = '<div class="text-warning"><i class="bi bi-clock me-1"></i>QR expired</div>';
        document.getElementById('wa-modal-status').innerHTML = 'QR login timed out after 90 seconds. Click "Refresh QR" to generate a new one.';
        document.getElementById('wa-modal-status').className = 'small mb-2 text-warning';
        return;
    }

    Promise.all([
        fetch('index.php?page=api/whatsapp-qr').then(r=>r.json()),
        fetch('index.php?page=api/whatsapp-status').then(r=>r.json())
    ]).then(([qr, status])=>{
        // Connection succeeded!
        if (status.success && status.connected) {
            clearInterval(window.waPollInterval);
            document.getElementById('wa-qr-area').innerHTML = '<div class="text-success"><i class="bi bi-check-circle-fill" style="font-size:2rem;"></i></div>';
            document.getElementById('wa-modal-status').innerHTML = '<strong class="text-success">WhatsApp connected successfully!</strong> Reloading page...';
            document.getElementById('wa-modal-status').className = 'small mb-2 text-success';
            setTimeout(() => { closeLoginWA(); location.reload(); }, 1500);
            return;
        }

        // Detect scan: if we had a QR but now it's gone AND we're not
        // connected, the user likely scanned it and Baileys is doing the
        // initial sync. This phase takes 5-15 seconds — the user must NOT
        // close the modal.
        if (waLastQrString && (!qr.success || !qr.available) && !waScanDetected) {
            waScanDetected = true;
            document.getElementById('wa-qr-area').innerHTML = '<div class="spinner-border text-success" style="width:3rem;height:3rem;"></div>';
            document.getElementById('wa-modal-status').innerHTML = '<strong class="text-success">Scan detected!</strong> Connecting to WhatsApp... <small class="text-muted">(do not close this window — initial sync takes 5-15 seconds)</small>';
            document.getElementById('wa-modal-status').className = 'small mb-2 text-success';
            return;
        }

        if (qr.success && qr.available && qr.qr) {
            // New QR — either first one or a refresh (Baileys regenerates
            // after ~20s if not scanned). If the QR string changed, show
            // a brief "QR refreshed" flash.
            if (waLastQrString && waLastQrString !== qr.qr) {
                const statusEl = document.getElementById('wa-modal-status');
                const oldHTML = statusEl.innerHTML;
                statusEl.innerHTML = '<strong class="text-warning">QR refreshed — scan the new one</strong>';
                setTimeout(() => { if (statusEl.innerHTML.includes('QR refreshed')) statusEl.innerHTML = oldHTML; }, 2000);
            }
            waLastQrString = qr.qr;
            // Convert QR string to image using qrserver (no bot key exposed)
            document.getElementById('wa-qr-area').innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data='+encodeURIComponent(qr.qr)+'" alt="WhatsApp QR" style="max-width:220px;">';
            if (!waScanDetected) {
                document.getElementById('wa-modal-status').textContent = 'Open WhatsApp → Settings → Linked Devices → Link a Device → Scan QR';
                document.getElementById('wa-modal-status').className = 'small mb-2 text-info';
            }
        } else if (!waScanDetected) {
            document.getElementById('wa-modal-status').textContent = 'Waiting for QR code from bot...';
            document.getElementById('wa-modal-status').className = 'small mb-2 text-info';
        }
    }).catch(()=>{ document.getElementById('wa-modal-status').textContent = 'Polling connection status...'; });
}

function showReconnectWA() { alert('Reconnect: use Login WhatsApp if session expired.'); }
function confirmRefreshWA() { if(confirm('Refresh WhatsApp bot status?')) { location.reload(); } }
function showLogoutConfirm() { if(confirm('Logout WhatsApp?\nMessages will stop being sent until WhatsApp is connected again.')) { fetch('index.php?page=api/whatsapp-logout',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+document.querySelector('input[name="csrf_token"]')?.value||''}); setTimeout(()=>location.reload(),800); } }

// ── Pairing Code Login (alternative to QR) ──────────────────────────────
// Flow: enter phone → bot generates 8-char code → user enters code on phone
// → WhatsApp links the session. No QR scanning required.
function showPairingWA() {
    // Reset modal to step 1
    document.getElementById('wa-pairing-step1').style.display = '';
    document.getElementById('wa-pairing-step2').style.display = 'none';
    document.getElementById('wa-pairing-regenerate').style.display = 'none';
    document.getElementById('wa-pairing-error').style.display = 'none';
    document.getElementById('wa-pairing-code-display').textContent = '--------';
    document.getElementById('wa-pairing-phone').value = '';
    // Show modal
    document.getElementById('wa-pairing-modal').style.display = 'block';
    document.getElementById('wa-pairing-modal').classList.add('show');
}
function closePairingWA() {
    clearInterval(window.waPairingPollInterval);
    document.getElementById('wa-pairing-modal').style.display = 'none';
    document.getElementById('wa-pairing-modal').classList.remove('show');
}

function generatePairingCode() {
    const phone = document.getElementById('wa-pairing-phone').value.replace(/[^0-9]/g, '');
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const errEl = document.getElementById('wa-pairing-error');
    errEl.style.display = 'none';

    if (phone.length < 10) {
        errEl.textContent = 'Please enter a valid phone number (10+ digits including country code).';
        errEl.style.display = '';
        return;
    }

    // Show loading state
    document.getElementById('wa-pairing-step1').style.display = 'none';
    document.getElementById('wa-pairing-step2').style.display = '';
    document.getElementById('wa-pairing-code-display').innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
    document.getElementById('wa-pairing-regenerate').style.display = 'none';
    document.getElementById('wa-pairing-status').innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Generating pairing code...';

    // Call PHP proxy → bot /api/pairing-code
    fetch('index.php?page=api/whatsapp-pairing-code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'phone=' + encodeURIComponent(phone) + '&csrf_token=' + csrfToken
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.code) {
            // Display the code (Baileys returns it with a hyphen, e.g. "1A2B-3C4D")
            document.getElementById('wa-pairing-code-display').textContent = data.code;
            document.getElementById('wa-pairing-status').innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Waiting for phone confirmation...';
            document.getElementById('wa-pairing-regenerate').style.display = '';
            // Start polling status to detect when the phone confirms the code
            window.waPairingPollInterval = setInterval(pollPairingStatus, 2500);
            pollPairingStatus();
        } else {
            // Error — go back to step 1
            document.getElementById('wa-pairing-step1').style.display = '';
            document.getElementById('wa-pairing-step2').style.display = 'none';
            errEl.textContent = data.error || 'Could not generate pairing code. Make sure the bot is reachable.';
            errEl.style.display = '';
        }
    })
    .catch(() => {
        document.getElementById('wa-pairing-step1').style.display = '';
        document.getElementById('wa-pairing-step2').style.display = 'none';
        errEl.textContent = 'Network error — could not reach the HRMS server.';
        errEl.style.display = '';
    });
}

function pollPairingStatus() {
    fetch('index.php?page=api/whatsapp-status')
        .then(r => r.json())
        .then(status => {
            if (status.success && status.connected) {
                // Pairing succeeded — close modal and reload page
                clearInterval(window.waPairingPollInterval);
                document.getElementById('wa-pairing-status').innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i>WhatsApp connected successfully!';
                setTimeout(() => { closePairingWA(); location.reload(); }, 1500);
            }
            // If not connected yet, keep polling — the spinner stays visible
        })
        .catch(() => { /* keep polling */ });
}

function copyPairingCode() {
    const code = document.getElementById('wa-pairing-code-display').textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
            // Brief visual feedback
            const btn = event.target.closest('button');
            const origHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied!';
            setTimeout(() => { btn.innerHTML = origHTML; }, 1500);
        });
    } else {
        // Fallback for older browsers
        const ta = document.createElement('textarea');
        ta.value = code;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}
</script>
