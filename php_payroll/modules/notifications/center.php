<?php
/**
 * RCS HRMS Pro - Notification Center
 * Send SMS, Email, WhatsApp notifications
 * 
 * This page is accessed via the main router (index.php?page=notifications/center)
 * Config, database, and auth are already initialized by index.php
 */

$pageTitle = 'Notification Center';

// Check access - only admin and hr_executive can access this page
if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied. You do not have permission to access this page.');
    redirect('index.php?page=dashboard');
}

$notification = new Notification();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_sms') {
        $mobile = sanitize($_POST['mobile']);
        $message = sanitize($_POST['message']);
        $result = $notification->sendSMS($mobile, $message);
        
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=notifications/center&tab=sms');
    }
    
    if ($action === 'send_email') {
        $to = sanitize($_POST['email']);
        $subject = sanitize($_POST['subject']);
        $body = $_POST['body']; // Don't sanitize HTML
        
        $result = $notification->sendEmail($to, $subject, $body);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect('index.php?page=notifications/center&tab=email');
    }
    
    if ($action === 'send_whatsapp') {
        $mobile = sanitize($_POST['mobile']);
        $message = sanitize($_POST['message']);
        $result = $notification->sendWhatsApp($mobile, $message);
        
        if ($result['manual']) {
            // Store link for display
            $_SESSION['whatsapp_link'] = $result['link'];
            $_SESSION['whatsapp_qr'] = $result['qr_code'];
            setFlash('info', 'WhatsApp link generated. Click the link or scan QR code below.');
        } else {
            setFlash($result['success'] ? 'success' : 'error', $result['message']);
        }
        redirect('index.php?page=notifications/center&tab=whatsapp');
    }
    
    if ($action === 'bulk_sms') {
        $month = (int)$_POST['month'];
        $year = (int)$_POST['year'];
        $type = sanitize($_POST['sms_type']);
        
        // Get employees - use prepare/execute for PDO
        $empStmt = $db->prepare("SELECT e.full_name, e.mobile_number, p.net_salary
             FROM employees e
             LEFT JOIN payroll p ON e.employee_code = p.employee_id
             WHERE e.status = 'approved' AND p.month = ? AND p.year = ?");
        $empStmt->execute([$month, $year]);
        $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent = 0;
        $failed = 0;
        
        foreach ($employees as $emp) {
            if (!empty($emp['mobile_number'])) {
                $message = $notification->getSMSTemplate($type, [
                    'name' => $emp['full_name'],
                    'amount' => number_format($emp['net_salary'], 0),
                    'month' => date('F Y', mktime(0, 0, 0, $month, 1, $year))
                ]);
                
                $result = $notification->sendSMS($emp['mobile_number'], $message);
                if ($result['success']) {
                    $sent++;
                } else {
                    $failed++;
                }
                
                // Delay to avoid rate limiting
                usleep(500000); // 0.5 seconds
            }
        }
        
        setFlash('success', "Bulk SMS sent: $sent successful, $failed failed");
        redirect('index.php?page=notifications/center&tab=bulk');
    }
    
    if ($action === 'send_payslips') {
        $month = (int)$_POST['payslip_month'];
        $year = (int)$_POST['payslip_year'];
        
        // Get payroll records - use prepare/execute for PDO
        $payrollStmt = $db->prepare("SELECT p.id, e.id as employee_id, e.full_name, e.personal_email
             FROM payroll p
             JOIN employees e ON p.employee_id = e.employee_code
             WHERE p.month = ? AND p.year = ?");
        $payrollStmt->execute([$month, $year]);
        $payrolls = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent = 0;
        $failed = 0;
        
        foreach ($payrolls as $p) {
            if (!empty($p['personal_email'])) {
                $result = $notification->sendPayslipEmail($p['employee_id'], $p['id']);
                if ($result['success']) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }
        
        setFlash('success', "Payslips sent: $sent emails sent, $failed failed");
        redirect('index.php?page=notifications/center&tab=bulk');
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'sms';

// Lazy-load notification logs only when viewing the logs tab
$logs = [];
if ($tab === 'logs') {
    try {
        $logsStmt = $db->query("SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 100");
        $logs = $logsStmt ? $logsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        $logs = [];
    }
}

// Load WhatsApp bot status once (cached for 60s in session to avoid slow cURL on every load)
$waBot = $_SESSION['wa_bot_cache'] ?? null;
if (!$waBot || (time() - ($_SESSION['wa_bot_cache_time'] ?? 0)) > 60) {
    $waBot = $notification->getWhatsAppBotStatus();
    $_SESSION['wa_bot_cache'] = $waBot;
    $_SESSION['wa_bot_cache_time'] = time();
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-bell me-2"></i>Notification Center
            </h1>
            <p class="text-muted">Send SMS, Email, and WhatsApp notifications</p>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" id="notifTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'sms' ? 'active' : ''; ?>" href="?page=notifications/center&tab=sms">
            <i class="bi bi-phone me-1"></i>Send SMS
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'email' ? 'active' : ''; ?>" href="?page=notifications/center&tab=email">
            <i class="bi bi-envelope me-1"></i>Send Email
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'whatsapp' ? 'active' : ''; ?>" href="?page=notifications/center&tab=whatsapp">
            <i class="bi bi-whatsapp me-1"></i>WhatsApp
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'bulk' ? 'active' : ''; ?>" href="?page=notifications/center&tab=bulk">
            <i class="bi bi-people me-1"></i>Bulk Actions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'push' ? 'active' : ''; ?>" href="?page=notifications/center&tab=push">
            <i class="bi bi-bell me-1"></i>Push Notifications
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'logs' ? 'active' : ''; ?>" href="?page=notifications/center&tab=logs">
            <i class="bi bi-clock-history me-1"></i>Logs
        </a>
    </li>
</ul>

<div class="row">
    <div class="col-lg-8">
        
        <!-- SMS Tab -->
        <?php if ($tab == 'sms'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-phone me-2"></i>Send SMS</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Free SMS:</strong> Using Fast2SMS API (Free tier available). 
                    Get your API key from <a href="https://docs.fast2sms.com" target="_blank">Fast2SMS</a>
                </div>
                
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="send_sms">
                    
                    <div class="mb-3">
                        <label class="form-label required">Mobile Number</label>
                        <input type="text" class="form-control" name="mobile" 
                               placeholder="Enter 10-digit mobile number" required
                               pattern="[0-9]{10}" maxlength="10">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Message</label>
                        <textarea class="form-control" name="message" rows="3" required
                                  maxlength="160" placeholder="Enter message (max 160 characters)"></textarea>
                        <small class="text-muted">Character count: <span id="smsCharCount">0</span>/160</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Send SMS
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Email Tab -->
        <?php if ($tab == 'email'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-envelope me-2"></i>Send Email</h5>
            </div>
            <div class="card-body">
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="send_email">
                    
                    <div class="mb-3">
                        <label class="form-label required">To Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Subject</label>
                        <input type="text" class="form-control" name="subject" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Message</label>
                        <textarea class="form-control" name="body" rows="8" required></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Send Email
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- WhatsApp Tab -->
        <?php if ($tab == 'whatsapp'): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-whatsapp me-2"></i>WhatsApp Message</h5>
                <span class="badge bg-<?php echo $waBot['connected'] ? 'success' : 'secondary'; ?>">
                    <?php echo $waBot['connected'] ? '<i class="bi bi-check-circle me-1"></i>Bot Connected — Auto Send ON' : '<i class="bi bi-exclamation-circle me-1"></i>Bot Offline — Link Mode'; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($waBot['connected']): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>WhatsApp Bot Connected!</strong> Messages will be sent automatically from your WhatsApp.
                    <?php if (!empty($waBot['name'])): ?>
                    <br>Phone: <strong><?php echo sanitize($waBot['name']); ?></strong> (<?php echo sanitize($waBot['phone']); ?>)
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>WhatsApp Bot is not connected.</strong> 
                    <?php echo sanitize($waBot['message']); ?>
                    <br><small>Go to <a href="index.php?page=settings/notifications">Settings → Notifications</a> to configure the bot URL & API key, then open the bot dashboard to scan QR.</small>
                </div>
                <?php endif; ?>
                
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="send_whatsapp">
                    
                    <div class="mb-3">
                        <label class="form-label required">Mobile Number</label>
                        <input type="text" class="form-control" name="mobile" 
                               placeholder="Enter 10-digit mobile number" required
                               pattern="[0-9]{10}" maxlength="10">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Message</label>
                        <textarea class="form-control" name="message" rows="3" required
                                  placeholder="Enter your message"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-whatsapp me-1"></i><?php echo $waBot['connected'] ? 'Send WhatsApp Message' : 'Generate WhatsApp Link'; ?>
                    </button>
                </form>
                
                <?php if (isset($_SESSION['whatsapp_link'])): ?>
                <div class="mt-4 p-3 border rounded bg-light">
                    <h5>WhatsApp Link Generated!</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <p class="text-break">
                                <a href="<?php echo $_SESSION['whatsapp_link']; ?>" target="_blank" class="btn btn-success">
                                    <i class="bi bi-whatsapp me-1"></i>Open WhatsApp
                                </a>
                            </p>
                            <p class="small text-muted">Click the button to open WhatsApp with the message pre-filled</p>
                        </div>
                        <div class="col-md-4 text-center">
                            <img src="<?php echo $_SESSION['whatsapp_qr']; ?>" alt="WhatsApp QR Code" class="img-fluid">
                            <p class="small text-muted">Scan to send</p>
                        </div>
                    </div>
                </div>
                <?php 
                unset($_SESSION['whatsapp_link'], $_SESSION['whatsapp_qr']);
                endif; 
                ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Bulk Actions Tab -->
        <?php if ($tab == 'bulk'): ?>
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="bi bi-phone me-2"></i>Bulk SMS</h5>
            </div>
            <div class="card-body">
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="bulk_sms">
                    
                    <div class="mb-3">
                        <label class="form-label">SMS Type</label>
                        <select class="form-select" name="sms_type">
                            <option value="salary_credit">Salary Credit Notification</option>
                            <option value="attendance_alert">Attendance Alert</option>
                            <option value="pf_update">PF Update</option>
                        </select>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="year">
                                <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Send bulk SMS to all employees?')">
                        <i class="bi bi-send me-1"></i>Send Bulk SMS
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="bi bi-envelope me-2"></i>Send Payslips by Email</h5>
            </div>
            <div class="card-body">
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="send_payslips">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="payslip_month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="payslip_year">
                                <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success" onclick="return confirm('Send payslips to all employees via email?')">
                        <i class="bi bi-envelope me-1"></i>Send Payslips
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Push Notifications Tab -->
        <?php if ($tab == 'push'): ?>
        <?php
        // Self-heal tables
        try { $db->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` VARCHAR(20) NOT NULL,
            `endpoint` VARCHAR(500) NOT NULL,
            `p256dh_key` VARCHAR(200) NOT NULL,
            `auth_key` VARCHAR(200) NOT NULL,
            `user_agent` VARCHAR(500) DEFAULT '',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_endpoint` (`endpoint`(255)),
            INDEX `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) {}

        try { $db->exec("CREATE TABLE IF NOT EXISTS `push_notification_queue` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `body` TEXT NOT NULL,
            `url` VARCHAR(500) DEFAULT '/',
            `icon` VARCHAR(500) DEFAULT '/logo.png',
            `target` VARCHAR(50) DEFAULT 'all',
            `employee_ids` TEXT DEFAULT NULL,
            `status` ENUM('pending','sending','completed','failed') DEFAULT 'pending',
            `sent_count` INT UNSIGNED DEFAULT 0,
            `failed_count` INT UNSIGNED DEFAULT 0,
            `expired_count` INT UNSIGNED DEFAULT 0,
            `errors` TEXT DEFAULT NULL,
            `scheduled_at` DATETIME DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            `created_by` VARCHAR(50) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_status` (`status`),
            INDEX `idx_scheduled` (`scheduled_at`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) {}

        // Handle push actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $pushAction = $_POST['action'];

            if ($pushAction === 'send_push') {
                $title = $_POST['push_title'] ?? '';
                $body  = $_POST['push_body'] ?? '';
                $url   = $_POST['push_url'] ?? '/';
                $target = $_POST['push_target'] ?? 'all';
                $employeeIds = $_POST['push_employee_ids'] ?? '';
                $schedule = $_POST['push_schedule'] ?? '';

                if (empty($title) || empty($body)) {
                    setFlash('error', 'Title and body are required.');
                    redirect('index.php?page=notifications/center&tab=push');
                }

                $db->insert('push_notification_queue', [
                    'title'        => $title,
                    'body'         => $body,
                    'url'          => $url,
                    'target'       => $target,
                    'employee_ids' => $target === 'selected' ? $employeeIds : null,
                    'status'       => empty($schedule) ? 'pending' : 'pending',
                    'scheduled_at' => empty($schedule) ? null : $schedule . ':00',
                    'created_by'   => $_SESSION['first_name'] ?? $_SESSION['user_id'] ?? 'admin',
                ]);

                setFlash('success', 'Push notification queued' . (empty($schedule) ? '' : ' for ' . sanitize($schedule)) . '.');
                redirect('index.php?page=notifications/center&tab=push');
            }

            if ($pushAction === 'send_now') {
                $queueId = (int)($_POST['queue_id'] ?? 0);
                if ($queueId) {
                    setFlash('info', 'Processing push notification #' . $queueId . '...');
                    redirect('index.php?page=notifications/center&tab=push&process=' . $queueId);
                }
            }
        }

        // Process push notification immediately if requested
        if (isset($_GET['process'])) {
            $queueId = (int)$_GET['process'];
            $item = $db->fetch("SELECT * FROM push_notification_queue WHERE id = ?", [$queueId]);
            if ($item && $item['status'] === 'pending') {
                require_once APP_ROOT . '/includes/class.webpush.php';
                $vapidPriv = getSetting('push_vapid_private_key');
                $vapidPub  = getSetting('push_vapid_public_key');
                $vapidSub  = getSetting('push_vapid_subject') ?: 'mailto:hr@rcsfacility.com';

                if (!$vapidPriv || !$vapidPub) {
                    setFlash('error', 'VAPID keys not configured. Generate them first.');
                    redirect('index.php?page=notifications/center&tab=push');
                }

                set_time_limit(300); // 5 min for push sends (uncatchable fatal if exceeded)

                try {
                    $wp = new WebPush($vapidPriv, $vapidPub, $vapidSub);

                    // Get subscriptions based on target
                    $subs = [];
                    if ($item['target'] === 'all') {
                        $subs = $db->fetchAll("SELECT * FROM push_subscriptions");
                    } elseif ($item['target'] === 'selected' && !empty($item['employee_ids'])) {
                        $ids = explode(',', $item['employee_ids']);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $subs = $db->fetchAll("SELECT * FROM push_subscriptions WHERE employee_id IN ($placeholders)", $ids);
                    }

                    if (empty($subs)) {
                        $db->exec("UPDATE push_notification_queue SET status = 'failed', errors = 'No subscribers found', sent_at = NOW() WHERE id = ?", [$queueId]);
                        setFlash('warning', 'No push subscribers found.');
                        redirect('index.php?page=notifications/center&tab=push');
                    }

                    $db->exec("UPDATE push_notification_queue SET status = 'sending' WHERE id = ?", [$queueId]);

                    $stats = $wp->sendBatch($subs, $item['title'], $item['body'], $item['url'], $item['icon']);

                    // Clean expired subscriptions (tracked during sendBatch, no second pass needed)
                    if (!empty($stats['expired_endpoints'])) {
                        $epPh = implode(',', array_fill(0, count($stats['expired_endpoints']), '?'));
                        $db->exec("DELETE FROM push_subscriptions WHERE endpoint IN ($epPh)", $stats['expired_endpoints']);
                    }

                    $db->exec("UPDATE push_notification_queue SET status = 'completed', sent_count = ?, failed_count = ?, expired_count = ?, errors = ?, sent_at = NOW() WHERE id = ?", [
                        $stats['sent'], $stats['failed'], $stats['expired'],
                        json_encode(array_slice($stats['errors'], 0, 10)), $queueId
                    ]);

                    setFlash('success', "Push sent: {$stats['sent']} delivered, {$stats['failed']} failed, {$stats['expired']} expired.");
                } catch (\Throwable $e) {
                    $db->exec("UPDATE push_notification_queue SET status = 'failed', errors = ? WHERE id = ?", [$e->getMessage(), $queueId]);
                    setFlash('error', 'Push failed: ' . $e->getMessage());
                }
                redirect('index.php?page=notifications/center&tab=push');
            }
        }

        // Get stats
        $subscriberCount = (int)($db->fetchColumn("SELECT COUNT(*) FROM (SELECT COUNT(*) c FROM push_subscriptions GROUP BY employee_id) t") ?: 0);
        $subscriptionCount = (int)($db->fetchColumn("SELECT COUNT(*) FROM push_subscriptions") ?: 0);
        $pendingCount = (int)($db->fetchColumn("SELECT COUNT(*) FROM push_notification_queue WHERE status = 'pending'") ?: 0);
        $vapidPubKey = getSetting('push_vapid_public_key');
        $vapidConfigured = !empty($vapidPubKey);

        // Get recent push history
        $pushHistory = $db->fetchAll("SELECT * FROM push_notification_queue ORDER BY created_at DESC LIMIT 20") ?: [];
        ?>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-bell me-2"></i>Push Notification Setup</h5>
                <?php if (!$vapidConfigured): ?>
                <a href="index.php?page=notifications/push-generate-keys" class="btn btn-warning btn-sm"
                   onclick="if(!confirm('Generate new VAPID keys? This only needs to be done once.')) return false;">
                    <i class="bi bi-key me-1"></i>Generate VAPID Keys
                </a>
                <?php else: ?>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>VAPID Configured</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-people-fill text-primary" style="font-size:1.5rem;"></i>
                            <h4 class="mb-0"><?php echo $subscriberCount; ?></h4>
                            <small class="text-muted">Subscribed Employees</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-phone text-success" style="font-size:1.5rem;"></i>
                            <h4 class="mb-0"><?php echo $subscriptionCount; ?></h4>
                            <small class="text-muted">Active Devices</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-clock text-warning" style="font-size:1.5rem;"></i>
                            <h4 class="mb-0"><?php echo $pendingCount; ?></h4>
                            <small class="text-muted">Pending in Queue</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-send me-2"></i>Send Push Notification</h5>
            </div>
            <div class="card-body">
                <?php if (!$vapidConfigured): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>VAPID keys not configured.</strong> Push notifications require VAPID keys to work.
                    Employees must also subscribe from the ESS app.
                    <br><a href="index.php?page=notifications/push-generate-keys" class="btn btn-warning btn-sm mt-2"
                       onclick="if(!confirm('Generate VAPID keys now?')) return false;">
                        <i class="bi bi-key me-1"></i>Generate Keys Now
                    </a>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="action" value="send_push">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="push_title" required
                                       placeholder="e.g., Salary Credited" maxlength="100">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="push_body" rows="3" required
                                          placeholder="e.g., Your salary for June 2025 has been credited." maxlength="500"></textarea>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Target</label>
                                <select class="form-select" name="push_target" id="pushTarget">
                                    <option value="all">All Subscribers</option>
                                    <option value="selected">Specific Employees</option>
                                </select>
                            </div>
                            <div class="mb-3 d-none" id="employeeIdsGroup">
                                <label class="form-label">Employee IDs</label>
                                <input type="text" class="form-control" name="push_employee_ids"
                                       placeholder="Comma-separated: 4,12,25">
                                <small class="text-muted">Enter employee IDs from HRMS</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Deep Link URL</label>
                                <input type="text" class="form-control" name="push_url" value="/#ess"
                                       placeholder="/#ess">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Schedule (optional)</label>
                                <input type="datetime-local" class="form-control" name="push_schedule">
                                <small class="text-muted">Leave empty to send immediately</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?php echo !$vapidConfigured ? 'disabled' : ''; ?>>
                            <i class="bi bi-send me-1"></i>Queue Push Notification
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Push History -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Push History</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Target</th>
                                <th>Sent</th>
                                <th>Failed</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pushHistory)): ?>
                            <tr><td colspan="8" class="text-center py-3 text-muted">No push notifications yet</td></tr>
                            <?php else: foreach ($pushHistory as $ph): ?>
                            <tr>
                                <td>#<?php echo $ph['id']; ?></td>
                                <td><strong><?php echo sanitize($ph['title']); ?></strong><br><small class="text-muted"><?php echo sanitize(substr($ph['body'], 0, 60)); ?>...</small></td>
                                <td><span class="badge bg-secondary"><?php echo sanitize($ph['target']); ?></span></td>
                                <td><?php echo (int)$ph['sent_count']; ?></td>
                                <td><?php echo (int)$ph['failed_count']; ?></td>
                                <td>
                                    <?php
                                    $statusColors = ['pending' => 'warning', 'sending' => 'info', 'completed' => 'success', 'failed' => 'danger'];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$ph['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($ph['status']); ?>
                                    </span>
                                </td>
                                <td><small><?php echo $ph['scheduled_at'] ? formatDateTime($ph['scheduled_at']) : 'Immediate'; ?></small></td>
                                <td>
                                    <?php if ($ph['status'] === 'pending' && $vapidConfigured): ?>
                                    <form method="POST" class="d-inline">
                                        <?php echo getCSRFTokenField(); ?>
                                        <input type="hidden" name="action" value="send_now">
                                        <input type="hidden" name="queue_id" value="<?php echo $ph['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Send Now">
                                            <i class="bi bi-lightning"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Cron Setup Instructions -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-terminal me-2"></i>Cron Job Setup</h5>
            </div>
            <div class="card-body">
                <p>For scheduled push notifications, add this cron job:</p>
                <pre class="bg-dark text-light p-3 rounded"><code>*/5 * * * * cd /home/user/public_html/hrms && php scripts/cron-push-notifications.php >> /dev/null 2>&1</code></pre>
                <small class="text-muted">This runs every 5 minutes and processes any pending push notifications whose scheduled time has arrived.</small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Logs Tab -->
        <?php if ($tab == 'logs'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Notification Logs</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Recipient</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No notifications sent yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php
                                    $icons = ['sms' => 'phone text-primary', 'email' => 'envelope text-success', 'whatsapp' => 'whatsapp text-success'];
                                    ?>
                                    <i class="bi bi-<?php echo $icons[$log['type']] ?? 'bell'; ?>"></i>
                                    <?php echo ucfirst($log['type']); ?>
                                </td>
                                <td><?php echo sanitize($log['recipient']); ?></td>
                                <td><small><?php echo sanitize(substr($log['message'], 0, 50)); ?>...</small></td>
                                <td>
                                    <?php
                                    $statusColors = ['sent' => 'success', 'failed' => 'danger', 'link_generated' => 'info'];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$log['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($log['status']); ?>
                                    </span>
                                </td>
                                <td><small><?php echo formatDate($log['created_at'], 'd-m-Y H:i'); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- WhatsApp Bot Status -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="bi bi-whatsapp me-2"></i>WhatsApp Bot</h5>
            </div>
            <div class="card-body">
                <?php if ($waBot['connected']): ?>
                <div class="text-center mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:2rem;"></i>
                    <h6 class="mt-2 mb-0 text-success">Connected</h6>
                    <small class="text-muted"><?php echo sanitize($waBot['name'] ?? ''); ?> (<?php echo sanitize($waBot['phone'] ?? ''); ?>)</small>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <small>Messages Sent</small>
                        <strong><?php echo $waBot['messagesSent'] ?? 0; ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <small>Queue</small>
                        <strong><?php echo $waBot['queueLength'] ?? 0; ?></strong>
                    </li>
                </ul>
                <?php else: ?>
                <div class="text-center mb-3">
                    <i class="bi bi-qr-code text-muted" style="font-size:2rem;"></i>
                    <h6 class="mt-2 mb-0">Not Connected</h6>
                    <small class="text-muted"><?php echo sanitize($waBot['message']); ?></small>
                </div>
                <?php endif; ?>
                
                <a href="index.php?page=settings/notifications" class="btn btn-outline-primary w-100 mt-2">
                    <i class="bi bi-gear me-1"></i>Configure Bot Settings
                </a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-gear me-2"></i>Quick Settings</h5>
            </div>
            <div class="card-body">
                <a href="index.php?page=notifications/bulk-email" class="btn btn-outline-success w-100 mb-2">
                    <i class="bi bi-envelope-paper me-1"></i>Bulk Email Campaign
                </a>
                <a href="index.php?page=settings/notifications" class="btn btn-outline-primary w-100 mb-2">
                    <i class="bi bi-gear me-1"></i>Configure All API Keys
                </a>
                
                <hr>
                
                <h6>SMS Provider Setup</h6>
                <ol class="small text-muted">
                    <li>Sign up at <a href="https://www.fast2sms.com" target="_blank">Fast2SMS</a></li>
                    <li>Get your API key from Dashboard</li>
                    <li>Add API key in Settings</li>
                </ol>
                
                <h6 class="mt-3">WhatsApp (Free)</h6>
                <p class="small text-muted">No setup required! Uses WhatsApp Web links.</p>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Usage Tips</h5>
            </div>
            <div class="card-body">
                <ul class="small">
                    <li>SMS: Limited free credits on Fast2SMS</li>
                    <li>Email: Unlimited via Gmail/SMTP</li>
                    <li>WhatsApp: 100% free via links</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
// Use $inlineJS so jQuery code runs inside footer's $(document).ready()
$inlineJS = "
    // SMS character counter
    var smsArea = document.querySelector('textarea[name=\"message\"]');
    var smsCount = document.getElementById('smsCharCount');
    if (smsArea && smsCount) {
        smsArea.addEventListener('input', function() { smsCount.textContent = this.value.length; });
    }
    // Push target toggle
    var pushSel = document.getElementById('pushTarget');
    var empGroup = document.getElementById('employeeIdsGroup');
    if (pushSel && empGroup) {
        pushSel.addEventListener('change', function() {
            empGroup.classList.toggle('d-none', this.value !== 'selected');
        });
    }
";
?>
