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
             LEFT JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE e.status = 'approved' AND pp.month = ? AND pp.year = ?");
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
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE pp.month = ? AND pp.year = ?");
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

// Get notification logs — wrap in try-catch since table may not exist yet
$logs = [];
try {
    $logsStmt = $db->query("SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 100");
    $logs = $logsStmt ? $logsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    // notification_logs table does not exist yet — show empty logs
    $logs = [];
}

// Get current tab
$tab = $_GET['tab'] ?? 'sms';
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
        <?php $waBot = $notification->getWhatsAppBotStatus(); ?>
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
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-phone me-2"></i>Bulk SMS</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
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
                                <div class="col-6">
                                    <label class="form-label">Month</label>
                                    <select class="form-select" name="month">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Year</label>
                                    <select class="form-select" name="year">
                                        <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100" onclick="return confirm('Send bulk SMS to all employees?')">
                                <i class="bi bi-send me-1"></i>Send Bulk SMS
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-envelope me-2"></i>Send Payslips by Email</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="send_payslips">
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">Month</label>
                                    <select class="form-select" name="payslip_month">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Year</label>
                                    <select class="form-select" name="payslip_year">
                                        <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Send payslips to all employees via email?')">
                                <i class="bi bi-envelope me-1"></i>Send Payslips
                            </button>
                        </form>
                    </div>
                </div>
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
                <?php $waBot = $notification->getWhatsAppBotStatus(); ?>
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

<script>
$(document).ready(function() {
    $('textarea[name="message"]').on('input', function() {
        $('#smsCharCount').text($(this).val().length);
    });
});
</script>
