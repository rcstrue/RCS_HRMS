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
                            <i class="bi bi-phone me-1"></i>Login WhatsApp
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

<script>
// WhatsApp Login / Logout / QR Polling (client-side only; server proxies all calls via PHP)
function showLoginWA() {
    document.getElementById('wa-login-modal').style.display = 'block';
    document.getElementById('wa-login-modal').classList.add('show');
    // Call PHP proxy to trigger /api/login server-side (does not expose bot URL/key to browser)
    fetch('index.php?page=api/whatsapp-login', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+document.querySelector('input[name="csrf_token"]')?.value||''}).catch(()=>{});
    document.getElementById('wa-qr-area').innerHTML = '<div class="spinner-border spinner-border-sm text-success"></div> <span>Waiting for QR...</span>';
    document.getElementById('wa-modal-status').textContent = 'Starting WhatsApp authentication...';
    // Start polling for QR
    window.waPollInterval = setInterval(pollQR, 2500);
    pollQR();
}
function closeLoginWA() { clearInterval(window.waPollInterval); document.getElementById('wa-login-modal').style.display='none'; document.getElementById('wa-login-modal').classList.remove('show'); }

function pollQR() {
    Promise.all([
        fetch('index.php?page=api/whatsapp-qr').then(r=>r.json()),
        fetch('index.php?page=api/whatsapp-status').then(r=>r.json())
    ]).then(([qr, status])=>{
        if (status.success && status.connected) {
            closeLoginWA();
            location.reload();
            return;
        }
        if (qr.success && qr.available && qr.qr) {
            // Convert QR string to image using qrserver (no bot key exposed)
            document.getElementById('wa-qr-area').innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data='+encodeURIComponent(qr.qr)+'" alt="WhatsApp QR" style="max-width:220px;">';
            document.getElementById('wa-modal-status').textContent = 'Scan this QR with WhatsApp → Linked Devices';
        } else {
            document.getElementById('wa-modal-status').textContent = 'Waiting for new QR...';
        }
    }).catch(()=>{ document.getElementById('wa-modal-status').textContent = 'Polling connection status...'; });
}

function showReconnectWA() { alert('Reconnect: use Login WhatsApp if session expired.'); }
function confirmRefreshWA() { if(confirm('Refresh WhatsApp bot status?')) { location.reload(); } }
function showLogoutConfirm() { if(confirm('Logout WhatsApp?\nMessages will stop being sent until WhatsApp is connected again.')) { fetch('index.php?page=api/whatsapp-logout',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+document.querySelector('input[name="csrf_token"]')?.value||''}); setTimeout(()=>location.reload(),800); } }
</script>
