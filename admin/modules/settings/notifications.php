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

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    
    setFlash('success', 'Notification settings saved successfully!');
    redirect('index.php?page=settings/notifications');
}

// Load current settings
$settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'notif_%'");
$currentSettings = [];
foreach ($settings as $s) {
    $currentSettings[$s['setting_key']] = $s['setting_value'];
}

// Get WhatsApp bot status
$waBot = $notification->getWhatsAppBotStatus();
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
