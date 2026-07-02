<?php
/**
 * RCS HRMS Pro - Bulk Email Campaign
 * Send personalized HTML emails to employees
 * Features: email validation, checkbox select, batch send, sent/rejected/pending tabs
 */

$pageTitle = 'Bulk Email Campaign';

// Check access
if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied.');
    redirect('index.php?page=dashboard');
}

$notification = new Notification();

// Ensure email log table exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS bulk_email_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_subject VARCHAR(500) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        recipient_name VARCHAR(255),
        status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
        error_message TEXT,
        sent_by INT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ---- Helper: Validate email properly ----
function isValidBulkEmail($email) {
    $email = trim($email);
    if (empty($email)) return false;
    // Basic format check
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email)) return false;
    // Check for common bad patterns
    $badPatterns = ['test@', 'example@', 'noreply@noreply', '@abc.com', 'noemail@', 'null@', 'undefined@', '.@', '@.'];
    $emailLower = strtolower($email);
    foreach ($badPatterns as $bp) {
        if (strpos($emailLower, $bp) !== false) return false;
    }
    // Must have a real TLD
    $parts = explode('.', $email);
    $tld = end($parts);
    if (strlen($tld) < 2) return false;
    return true;
}

// ---- Handle POST Actions ----
$resultMessage = '';
$resultType = '';
$currentTab = 'all'; // default tab for results

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Preview: generate sample emails for review before sending
    if ($action === 'preview') {
        $source = $_POST['source'] ?? 'employees';
        $subject = $_POST['subject'] ?? '';
        $bodyTemplate = $_POST['body_template'] ?? '';
        $filterStatus = $_POST['filter_status'] ?? '';
        $filterClient = (int)($_POST['filter_client'] ?? 0);
        $filterUnit = (int)($_POST['filter_unit'] ?? 0);
        
        $rawRecipients = [];
        
        if ($source === 'employees') {
            $sql = "SELECT e.id, e.full_name, e.email, e.employee_code, e.designation, e.department,
                           e.mobile_number, e.date_of_birth,
                           c.name as client_name, u.name as unit_name, u.address as site_name
                    FROM employees e
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE e.email IS NOT NULL AND e.email != ''";
            $params = [];
            
            if ($filterStatus) {
                $sql .= " AND e.status = :status";
                $params['status'] = $filterStatus;
            }
            if ($filterClient) {
                $sql .= " AND e.client_id = :client_id";
                $params['client_id'] = $filterClient;
            }
            if ($filterUnit) {
                $sql .= " AND e.unit_id = :unit_id";
                $params['unit_id'] = $filterUnit;
            }
            $sql .= " ORDER BY e.email ASC";
            $rawRecipients = $db->fetchAll($sql, $params);
        }
        
        // Validate emails — split into valid and invalid
        $recipients = [];
        $rejected = [];
        foreach ($rawRecipients as $r) {
            if (isValidBulkEmail($r['email'])) {
                $recipients[] = $r;
            } else {
                $rejected[] = $r;
            }
        }
        
        // Store preview data in session
        $_SESSION['bulk_email_preview'] = [
            'source' => $source,
            'subject' => $subject,
            'body_template' => $bodyTemplate,
            'recipients' => $recipients,
            'rejected' => $rejected,
            'count' => count($recipients),
            'rejected_count' => count($rejected)
        ];
        
        $totalRaw = count($rawRecipients);
        $resultMessage = "Found $totalRaw emails: <b>" . count($recipients) . " valid</b>, <b>" . count($rejected) . " rejected</b> (invalid email format). Review below.";
        $resultType = count($rejected) > 0 ? 'warning' : 'info';
    }
    
    // Actually send the emails (only selected ones)
    if ($action === 'send_bulk') {
        $preview = $_SESSION['bulk_email_preview'] ?? null;
        
        if (!$preview) {
            setFlash('error', 'No preview data. Please preview first.');
            redirect('index.php?page=notifications/bulk-email');
        }
        
        // Get selected indices from checkboxes
        $selectedIndices = $_POST['selected_emails'] ?? [];
        if (!is_array($selectedIndices)) $selectedIndices = [];
        
        // Filter recipients to only selected ones
        $toSend = [];
        $skipped = [];
        foreach ($preview['recipients'] as $idx => $r) {
            if (in_array((string)$idx, $selectedIndices)) {
                $toSend[] = $r;
            } else {
                $skipped[] = $r;
            }
        }
        
        if (empty($toSend)) {
            setFlash('error', 'No recipients selected. Please check at least one.');
            redirect('index.php?page=notifications/bulk-email');
        }
        
        $sent = 0;
        $failed = 0;
        $sentList = [];
        $failedList = [];
        
        $batchSize = 50; // send in batches to avoid timeout
        $totalBatches = ceil(count($toSend) / $batchSize);
        
        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $batchStart = $batch * $batchSize;
            $batchEnd = min($batchStart + $batchSize, count($toSend));
            
            for ($i = $batchStart; $i < $batchEnd; $i++) {
                $r = $toSend[$i];
                $email = $r['email'];
                
                // Double-validate before sending
                if (!isValidBulkEmail($email)) {
                    $failed++;
                    $failedList[] = ['email' => $email, 'name' => $r['full_name'] ?? $r['name'] ?? '', 'reason' => 'Invalid email'];
                    continue;
                }
                
                // Format DOB
                $rawDob = $r['date_of_birth'] ?? $r['dob'] ?? '';
                $formattedDob = '';
                if ($rawDob) {
                    try {
                        $dobObj = new DateTime($rawDob);
                        $formattedDob = $dobObj->format('d/m/Y');
                    } catch (Exception $e) {
                        $formattedDob = $rawDob;
                    }
                }
                
                $mobile = $r['mobile_number'] ?? $r['mobile'] ?? '';
                
                $replacements = [
                    '{{name}}'        => $r['full_name'] ?? $r['name'] ?? 'Employee',
                    '{{unit}}'        => $r['unit_name'] ?? '',
                    '{{site}}'        => $r['site_name'] ?? $r['unit_name'] ?? '',
                    '{{client}}'      => $r['client_name'] ?? '',
                    '{{designation}}' => $r['designation'] ?? '',
                    '{{department}}'  => $r['department'] ?? '',
                    '{{code}}'        => $r['employee_code'] ?? $r['member_id'] ?? '',
                    '{{mobile}}'      => $mobile,
                    '{{dob}}'         => $formattedDob,
                    '{{Name}}'        => $r['full_name'] ?? $r['name'] ?? 'Employee',
                    '{{Unit}}'        => $r['unit_name'] ?? '',
                    '{{Site}}'        => $r['site_name'] ?? $r['unit_name'] ?? '',
                    '{{Client}}'      => $r['client_name'] ?? '',
                    '{{Designation}}' => $r['designation'] ?? '',
                    '{{Department}}'  => $r['department'] ?? '',
                    '{{Code}}'        => $r['employee_code'] ?? $r['member_id'] ?? '',
                    '{{Mobile}}'      => $mobile,
                    '{{DOB}}'         => $formattedDob,
                ];
                
                $finalSubject = str_replace(array_keys($replacements), array_values($replacements), $preview['subject']);
                $finalBody = str_replace(array_keys($replacements), array_values($replacements), $preview['body_template']);
                
                $result = $notification->sendEmail($email, $finalSubject, $finalBody);
                
                if ($result['success']) {
                    $sent++;
                    $sentList[] = ['email' => $email, 'name' => $r['full_name'] ?? $r['name'] ?? ''];
                } else {
                    $failed++;
                    $failedList[] = ['email' => $email, 'name' => $r['full_name'] ?? $r['name'] ?? '', 'reason' => $result['message'] ?? 'Send failed'];
                }

                // Log each send attempt to database
                try {
                    $db->query("INSERT INTO bulk_email_logs (campaign_subject, recipient_email, recipient_name, status, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?)",
                        [$finalSubject, $email, $r['full_name'] ?? $r['name'] ?? '', $result['success'] ? 'sent' : 'failed', $result['success'] ? null : ($result['message'] ?? 'Send failed'), $_SESSION['user_id'] ?? null]);
                } catch(Exception $logErr) {}
                
                usleep(200000); // 0.2 second delay
            }
            
            // Flush progress — prevent timeout on large batches
            if (function_exists('fastcgi_finish_request')) {
                // Not flushing mid-execution, just continuing
            }
        }

        // Log skipped emails
        foreach ($skipped as $sk) {
            try {
                $db->query("INSERT INTO bulk_email_logs (campaign_subject, recipient_email, recipient_name, status, sent_by) VALUES (?, ?, ?, 'skipped', ?)",
                    [$preview['subject'], $sk['email'], $sk['full_name'] ?? $sk['name'] ?? '', $_SESSION['user_id'] ?? null]);
            } catch(Exception $e) {}
        }

        // Store results in session for viewing in tabs
        $_SESSION['bulk_email_results'] = [
            'sent' => $sentList,
            'failed' => $failedList,
            'skipped' => $skipped,
            'rejected' => $preview['rejected'] ?? [],
            'total_sent' => $sent,
            'total_failed' => $failed,
            'total_skipped' => count($skipped),
            'total_rejected' => count($preview['rejected'] ?? []),
            'subject' => $preview['subject'],
            'source' => $preview['source']
        ];
        
        unset($_SESSION['bulk_email_preview']);
        
        $totalAll = $sent + $failed + count($skipped) + count($preview['rejected'] ?? []);
        $resultMessage = "<b>Campaign Complete!</b> Total: $totalAll | <span class='text-success'>Sent: $sent</span> | <span class='text-danger'>Failed: $failed</span> | <span class='text-warning'>Skipped: " . count($skipped) . "</span> | <span class='text-secondary'>Rejected (invalid): " . count($preview['rejected'] ?? []) . "</span>";
        $resultType = 'success';
        $currentTab = 'sent';
    }
    
    // Discard preview
    if ($action === 'discard') {
        unset($_SESSION['bulk_email_preview']);
        unset($_SESSION['bulk_email_results']);
        redirect('index.php?page=notifications/bulk-email');
    }
}

// ---- Load Data ----
$preview = $_SESSION['bulk_email_preview'] ?? null;
$results = $_SESSION['bulk_email_results'] ?? null;

// Load clients and units for filters
$clients = $db->fetchAll("SELECT id, name FROM clients ORDER BY name ASC");
$units = $db->fetchAll("SELECT id, name, client_id FROM units ORDER BY name ASC");

// Count total recipients per source
$employeeCount = $db->fetchColumn("SELECT COUNT(*) FROM employees WHERE email IS NOT NULL AND email != '' AND status = 'approved'");
$epfoCount = 0; // EPFO module not available

// Load email history
$emailHistory = $db->fetchAll("SELECT campaign_subject, recipient_email, recipient_name, status, error_message, sent_at 
    FROM bulk_email_logs ORDER BY sent_at DESC LIMIT 100");
$totalSent = (int)$db->fetchColumn("SELECT COUNT(*) FROM bulk_email_logs WHERE status = 'sent'");
$totalFailed = (int)$db->fetchColumn("SELECT COUNT(*) FROM bulk_email_logs WHERE status = 'failed'");
$totalSkipped = (int)$db->fetchColumn("SELECT COUNT(*) FROM bulk_email_logs WHERE status = 'skipped'");
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-envelope-paper me-2"></i>Bulk Email Campaign
            </h1>
            <p class="text-muted">Send personalized HTML emails to employees</p>
        </div>
        <div class="col-auto">
            <div class="d-flex gap-2">
                <span class="badge bg-primary fs-6">
                    <i class="bi bi-people me-1"></i>Employees: <?php echo $employeeCount; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<?php if ($resultMessage): ?>
<div class="alert alert-<?php echo $resultType; ?> alert-dismissible fade show" role="alert">
    <?php echo $resultMessage; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($results): ?>
<!-- ==================== RESULTS SCREEN WITH TABS ==================== -->
<div class="card mb-3">
    <div class="card-header p-0">
        <ul class="nav nav-tabs" id="resultTabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentTab == 'all' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tabAll">
                    <i class="bi bi-list-ul me-1"></i>All
                    <span class="badge bg-secondary"><?php echo $results['total_sent'] + $results['total_failed'] + $results['total_skipped'] + $results['total_rejected']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $currentTab == 'sent' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tabSent">
                    <i class="bi bi-check-circle me-1 text-success"></i>Sent
                    <span class="badge bg-success"><?php echo $results['total_sent']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $currentTab == 'failed' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tabFailed">
                    <i class="bi bi-x-circle me-1 text-danger"></i>Failed
                    <span class="badge bg-danger"><?php echo $results['total_failed']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tabSkipped">
                    <i class="bi bi-skip-forward me-1 text-warning"></i>Skipped (unchecked)
                    <span class="badge bg-warning text-dark"><?php echo $results['total_skipped']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tabRejected">
                    <i class="bi bi-ban me-1 text-secondary"></i>Rejected (invalid email)
                    <span class="badge bg-dark"><?php echo $results['total_rejected']; ?></span>
                </a>
            </li>
        </ul>
    </div>
    <div class="tab-content">
        <!-- All Tab -->
        <div class="tab-pane fade <?php echo $currentTab == 'all' ? 'show active' : ''; ?>" id="tabAll">
            <?php renderResultTable('all', $results); ?>
        </div>
        <!-- Sent Tab -->
        <div class="tab-pane fade <?php echo $currentTab == 'sent' ? 'show active' : ''; ?>" id="tabSent">
            <?php renderResultTable('sent', $results); ?>
        </div>
        <!-- Failed Tab -->
        <div class="tab-pane fade <?php echo $currentTab == 'failed' ? 'show active' : ''; ?>" id="tabFailed">
            <?php renderResultTable('failed', $results); ?>
        </div>
        <!-- Skipped Tab -->
        <div class="tab-pane fade" id="tabSkipped">
            <?php renderResultTable('skipped', $results); ?>
        </div>
        <!-- Rejected Tab -->
        <div class="tab-pane fade" id="tabRejected">
            <?php renderResultTable('rejected', $results); ?>
        </div>
    </div>
</div>

<div class="d-flex gap-3">
    <a href="index.php?page=notifications/bulk-email" class="btn btn-primary btn-lg flex-grow-1">
        <i class="bi bi-plus-circle me-2"></i>New Campaign
    </a>
    <form method="POST">
        <input type="hidden" name="action" value="discard">
        <button type="submit" class="btn btn-outline-danger btn-lg">
            <i class="bi bi-trash me-2"></i>Clear Results
        </button>
    </form>
</div>

<?php elseif (!$preview): ?>
<!-- ==================== EMAIL HISTORY ==================== -->
<?php if (!empty($emailHistory)): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Email History</h6>
        <div class="d-flex gap-2">
            <span class="badge bg-success"><i class="bi bi-check me-1"></i><?php echo $totalSent; ?> Sent</span>
            <span class="badge bg-danger"><i class="bi bi-x me-1"></i><?php echo $totalFailed; ?> Failed</span>
            <span class="badge bg-warning text-dark"><i class="bi bi-skip-forward me-1"></i><?php echo $totalSkipped; ?> Skipped</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Time</th><th>Subject</th><th>Recipient</th><th>Email</th><th>Status</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($emailHistory as $log): ?>
                    <tr>
                        <td class="text-nowrap small"><?php echo date('d M Y H:i', strtotime($log['sent_at'])); ?></td>
                        <td class="small"><?php echo htmlspecialchars(mb_strimwidth($log['campaign_subject'], 0, 40, '...')); ?></td>
                        <td><?php echo sanitize($log['recipient_name'] ?? ''); ?></td>
                        <td class="small"><code><?php echo sanitize($log['recipient_email']); ?></code></td>
                        <td>
                            <?php if ($log['status'] === 'sent'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                            <?php elseif ($log['status'] === 'failed'): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                            <?php else: ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning"><i class="bi bi-skip-forward me-1"></i>Skipped</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo sanitize($log['error_message'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-3">
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
        No email history yet. Send your first campaign to see results here.
    </div>
</div>
<?php endif; ?>

<!-- ==================== COMPOSE SCREEN ==================== -->
<form method="POST">
    <input type="hidden" name="action" value="preview">
    
    <div class="row">
        <!-- Left: Compose -->
        <div class="col-lg-8">
            <!-- Source Selection -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-database me-2"></i>Recipient Source</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Data Source</label>
                            <select class="form-select" name="source" id="dataSource">
                                <option value="employees">Employees (<?php echo $employeeCount; ?> with email)</option>
                            </select>
                        </div>
                        
                        <div id="employeeFilters" class="col-md-6">
                            <label class="form-label">Status Filter</label>
                            <select class="form-select" name="filter_status">
                                <option value="">All Statuses</option>
                                <option value="approved" selected>Approved Only</option>
                                <option value="pending_hr_verification">Pending Verification</option>
                                <option value="inactive">Inactive</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6" id="clientFilter">
                            <label class="form-label">Client</label>
                            <select class="form-select" name="filter_client" onchange="loadUnits(this.value)">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6" id="unitFilter">
                            <label class="form-label">Unit / Site</label>
                            <select class="form-select" name="filter_unit" id="unitSelect">
                                <option value="">All Units</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" data-client="<?php echo $u['client_id']; ?>">
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Email Compose -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-pencil-square me-2"></i>Compose Email</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Subject *</label>
                        <input type="text" class="form-control" name="subject" id="emailSubject"
                               placeholder="e.g. Important Update for {{name}} - {{unit}}" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email Body (HTML) * <span class="badge bg-info">HTML supported</span></label>
                        
                        <!-- Placeholder buttons -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{name}}')">
                                <i class="bi bi-person me-1"></i>Name
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{mobile}}')">
                                <i class="bi bi-phone me-1"></i>Mobile
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{dob}}')">
                                <i class="bi bi-calendar3 me-1"></i>DOB
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{unit}}')">
                                <i class="bi bi-building me-1"></i>Unit
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{site}}')">
                                <i class="bi bi-geo-alt me-1"></i>Site
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{client}}')">
                                <i class="bi bi-briefcase me-1"></i>Client
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{designation}}')">
                                <i class="bi bi-tag me-1"></i>Designation
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{department}}')">
                                <i class="bi bi-diagram-3 me-1"></i>Department
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="insertPlaceholder('{{code}}')">
                                <i class="bi bi-upc me-1"></i>Emp Code
                            </button>
                        </div>
                        
                        <textarea class="form-control font-monospace" name="body_template" id="emailBody" rows="16" required
                                  placeholder="Dear {{name}},<br><br>Write your HTML email body here...<br><br>Use &lt;b&gt;, &lt;i&gt;, &lt;br&gt;, &lt;p&gt;, etc. for formatting.<br><br>Mobile: {{mobile}}<br>DOB: {{dob}}<br><br>Thank you."></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">Use placeholder buttons to insert variables. HTML tags are supported for formatting.</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleLivePreview()">
                                <i class="bi bi-eye me-1"></i>Toggle Preview
                            </button>
                        </div>
                    </div>

                    <!-- Live HTML Preview -->
                    <div id="livePreviewBox" class="mb-3" style="display:none;">
                        <label class="form-label text-success"><i class="bi bi-eye me-1"></i>Live HTML Preview (sample data)</label>
                        <div id="livePreviewContent" class="border rounded p-3" style="background:#fff;max-height:350px;overflow-y:auto;min-height:100px;"></div>
                    </div>
                    
                    <!-- Quick Templates -->
                    <div class="mb-3">
                        <label class="form-label">Quick Templates</label>
                        <div class="row g-2">
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-danger fw-bold" onclick="loadTemplate('kyc_pending')">
                                    <i class="bi bi-exclamation-diamond me-1"></i>KYC Pending
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="loadTemplate('general')">
                                    <i class="bi bi-file-text me-1"></i>General Notice
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="loadTemplate('pf_update')">
                                    <i class="bi bi-shield-check me-1"></i>PF Update
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="loadTemplate('esi_update')">
                                    <i class="bi bi-heart-pulse me-1"></i>ESI Update
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="loadTemplate('holiday')">
                                    <i class="bi bi-calendar-event me-1"></i>Holiday Notice
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadTemplate('policy')">
                                    <i class="bi bi-journal-text me-1"></i>Policy Update
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Preview Button -->
            <button type="submit" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-eye me-2"></i>Generate Preview — Review Recipients Before Sending
            </button>
        </div>
        
        <!-- Right: Info -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Placeholders</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Tag</th><th>Replaced With</th></tr></thead>
                        <tbody>
                            <tr><td><code>{{name}}</code></td><td>Full Name</td></tr>
                            <tr class="table-info"><td><code>{{mobile}}</code></td><td>Mobile No.</td></tr>
                            <tr class="table-info"><td><code>{{dob}}</code></td><td>Date of Birth (DD/MM/YYYY)</td></tr>
                            <tr><td><code>{{unit}}</code></td><td>Unit Name</td></tr>
                            <tr><td><code>{{site}}</code></td><td>Site Address</td></tr>
                            <tr><td><code>{{client}}</code></td><td>Client Name</td></tr>
                            <tr><td><code>{{designation}}</code></td><td>Job Title</td></tr>
                            <tr><td><code>{{department}}</code></td><td>Department</td></tr>
                            <tr><td><code>{{code}}</code></td><td>Emp Code / Member ID</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Tips</h5>
                </div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li>Click placeholder buttons to insert at cursor</li>
                        <li><strong>HTML supported</strong> — use <code>&lt;b&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;p&gt;</code>, etc.</li>
                        <li>Use <strong>Toggle Preview</strong> to see rendered HTML</li>
                        <li>Recipients sorted by <strong>email ASC</strong></li>
                        <li><strong>Invalid emails auto-rejected</strong> before preview</li>
                        <li><strong>9000+ emails</strong> — sent in batches of 50</li>
                        <li>Uncheck recipients in preview to skip them</li>
                        <li>DOB formatted as <strong>DD/MM/YYYY</strong></li>
                    </ul>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-code-slash me-2"></i>HTML Examples</h5>
                </div>
                <div class="card-body small">
                    <code>
                        &lt;b&gt;Bold&lt;/b&gt;<br>
                        &lt;i&gt;Italic&lt;/i&gt;<br>
                        &lt;br&gt; — line break<br>
                        &lt;p&gt;Paragraph&lt;/p&gt;<br>
                        &lt;ul&gt;&lt;li&gt;Item&lt;/li&gt;&lt;/ul&gt;<br>
                        &lt;span style="color:red"&gt;Red&lt;/span&gt;<br>
                        &lt;a href="url"&gt;Link&lt;/a&gt;
                    </code>
                </div>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<!-- ==================== PREVIEW SCREEN WITH CHECKBOXES ==================== -->

<!-- Summary bar -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card border-success text-center p-2">
            <div class="fs-4 fw-bold text-success"><?php echo $preview['count']; ?></div>
            <small>Valid Recipients</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger text-center p-2">
            <div class="fs-4 fw-bold text-danger"><?php echo $preview['rejected_count'] ?? 0; ?></div>
            <small>Rejected (invalid email)</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-primary text-center p-2">
            <div class="fs-4 fw-bold text-primary" id="selectedCount"><?php echo $preview['count']; ?></div>
            <small>Selected to Send</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning text-center p-2">
            <div class="fs-4 fw-bold text-warning" id="unselectedCount">0</div>
            <small>Unselected (will skip)</small>
        </div>
    </div>
</div>

<!-- Tab nav -->
<ul class="nav nav-tabs mb-3" id="previewTabs">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabValidRecipients">
            <i class="bi bi-check2-square me-1"></i>Valid Recipients
            <span class="badge bg-success"><?php echo $preview['count']; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabInvalidEmails">
            <i class="bi bi-x-octagon me-1 text-danger"></i>Rejected Emails (invalid)
            <span class="badge bg-danger"><?php echo $preview['rejected_count'] ?? 0; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabEmailPreview">
            <i class="bi bi-envelope me-1"></i>Email Preview
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- Valid Recipients Tab -->
    <div class="tab-pane fade show active" id="tabValidRecipients">
        <form method="POST" id="sendForm">
            <input type="hidden" name="action" value="send_bulk">
            
            <!-- Select All / Deselect All toolbar -->
            <div class="card mb-2">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success" onclick="selectAll()">
                            <i class="bi bi-check-all me-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                            <i class="bi bi-square me-1"></i>Deselect All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="invertSelection()">
                            <i class="bi bi-arrow-left-right me-1"></i>Invert
                        </button>
                        <div class="input-group input-group-sm" style="width:200px;">
                            <input type="text" class="form-control" id="searchBox" placeholder="Search name/email..." oninput="filterRows()">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('searchBox').value='';filterRows();">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary fs-6" id="toolbarSelected">0 selected</span>
                        <span class="text-muted ms-2">of <?php echo $preview['count']; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
                        <table class="table table-hover table-sm mb-0" id="recipientsTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:40px;text-align:center;">
                                        <input type="checkbox" class="form-check-input" id="checkAll" checked onchange="toggleAll(this.checked)">
                                    </th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>DOB</th>
                                    <th>Code</th>
                                    <th>Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 0; foreach ($preview['recipients'] as $idx => $r): $i++; ?>
                                <?php 
                                $rDob = $r['date_of_birth'] ?? $r['dob'] ?? '';
                                $rDobFormatted = '';
                                if ($rDob) {
                                    try { $rDobFormatted = (new DateTime($rDob))->format('d/m/Y'); } catch(Exception $e) { $rDobFormatted = $rDob; }
                                }
                                $rMobile = $r['mobile_number'] ?? $r['mobile'] ?? '';
                                ?>
                                <tr class="recipient-row" data-email="<?php echo strtolower(sanitize($r['email'])); ?>" data-name="<?php echo strtolower(sanitize($r['full_name'] ?? $r['name'] ?? '')); ?>">
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input recipient-check" name="selected_emails[]" value="<?php echo $idx; ?>" checked onchange="updateCounts()">
                                    </td>
                                    <td><?php echo $i; ?></td>
                                    <td><?php echo sanitize($r['full_name'] ?? $r['name']); ?></td>
                                    <td><code><?php echo sanitize($r['email']); ?></code></td>
                                    <td><?php echo sanitize($rMobile); ?></td>
                                    <td><?php echo $rDobFormatted; ?></td>
                                    <td><?php echo sanitize($r['employee_code'] ?? ''); ?></td>
                                    <td><?php echo sanitize($r['unit_name'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Hidden selected count field -->
            <input type="hidden" id="hiddenSelectedCount" value="<?php echo $preview['count']; ?>">
            
            <!-- Send Button -->
            <div class="mt-3">
                <button type="submit" class="btn btn-success btn-lg w-100" id="sendBtn">
                    <i class="bi bi-send me-2"></i>Send to <span id="sendBtnCount"><?php echo $preview['count']; ?></span> Selected Recipients
                </button>
                <small class="text-muted d-block text-center mt-1">
                    Emails will be sent in batches of 50 with 0.2s delay each. <?php echo $preview['count']; ?> emails ≈ <?php echo round($preview['count'] * 0.2 / 60, 1); ?> minutes
                </small>
            </div>
        </form>
        
        <form method="POST" class="mt-2">
            <input type="hidden" name="action" value="discard">
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-x-circle me-2"></i>Discard & Go Back
            </button>
        </form>
    </div>
    
    <!-- Rejected Emails Tab -->
    <div class="tab-pane fade" id="tabInvalidEmails">
        <?php if (!empty($preview['rejected'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <b><?php echo count($preview['rejected']); ?></b> emails were rejected due to invalid format. These will NOT be sent.
        </div>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="table-danger">
                    <tr><th>#</th><th>Name</th><th>Invalid Email</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php $j = 0; foreach ($preview['rejected'] as $rr): $j++; ?>
                    <tr>
                        <td><?php echo $j; ?></td>
                        <td><?php echo sanitize($rr['full_name'] ?? $rr['name'] ?? ''); ?></td>
                        <td><code class="text-danger"><?php echo sanitize($rr['email']); ?></code></td>
                        <td>
                            <?php 
                            $reason = 'Invalid format';
                            if (!preg_match('/@/', $rr['email'])) $reason = 'Missing @';
                            elseif (!preg_match('/\./', $rr['email'])) $reason = 'Missing domain dot';
                            elseif (strpos($rr['email'], ' ') !== false) $reason = 'Contains spaces';
                            elseif (preg_match('/^\.|@\./', $rr['email'])) $reason = 'Starts/ends with dot';
                            echo $reason;
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>No invalid emails found. All emails passed validation.</div>
        <?php endif; ?>
    </div>
    
    <!-- Email Preview Tab -->
    <div class="tab-pane fade" id="tabEmailPreview">
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-envelope me-2"></i>Sample Subject (1st recipient)</h6></div>
                    <div class="card-body">
                        <code><?php 
                        $first = $preview['recipients'][0] ?? [];
                        $fDob = $first['date_of_birth'] ?? $first['dob'] ?? '';
                        $fDobFmt = '';
                        if ($fDob) { try { $fDobFmt = (new DateTime($fDob))->format('d/m/Y'); } catch(Exception $e) { $fDobFmt = $fDob; } }
                        echo sanitize(str_replace(
                            ['{{name}}', '{{unit}}', '{{site}}', '{{client}}', '{{designation}}', '{{department}}', '{{code}}', '{{mobile}}', '{{dob}}'],
                            [$first['full_name'] ?? $first['name'] ?? '[Name]',
                             $first['unit_name'] ?? '[Unit]',
                             $first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',
                             $first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',
                             $first['employee_code'] ?? $first['member_id'] ?? '[Code]',
                             $first['mobile_number'] ?? $first['mobile'] ?? '[Mobile]',
                             $fDobFmt],
                            $preview['subject']
                        )); 
                        ?></code>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-body-text me-2"></i>Sample Body — HTML Rendered</h6></div>
                    <div class="card-body" style="max-height:400px;overflow-y:auto;">
                        <div style="background:#fff;padding:15px;border-radius:8px;border:1px solid #dee2e6;font-size:14px;"><?php 
                        echo str_replace(
                            ['{{name}}', '{{unit}}', '{{site}}', '{{client}}', '{{designation}}', '{{department}}', '{{code}}', '{{mobile}}', '{{dob}}',
                             '{{Name}}', '{{Unit}}', '{{Site}}', '{{Client}}', '{{Designation}}', '{{Department}}', '{{Code}}', '{{Mobile}}', '{{DOB}}'],
                            [$first['full_name'] ?? $first['name'] ?? '[Name]',
                             $first['unit_name'] ?? '[Unit]',
                             $first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',
                             $first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',
                             $first['employee_code'] ?? $first['member_id'] ?? '[Code]',
                             $first['mobile_number'] ?? $first['mobile'] ?? '[Mobile]',
                             $fDobFmt,
                             $first['full_name'] ?? $first['name'] ?? '[Name]',
                             $first['unit_name'] ?? '[Unit]',
                             $first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',
                             $first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',
                             $first['employee_code'] ?? $first['member_id'] ?? '[Code]',
                             $first['mobile_number'] ?? $first['mobile'] ?? '[Mobile]',
                             $fDobFmt],
                            $preview['body_template']
                        ); 
                        ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== PHP HELPER: Render result table for tabs ==================== -->
<?php
function renderResultTable($tab, $results) {
    $data = [];
    $statusClass = '';
    $emptyMsg = '';
    
    switch($tab) {
        case 'sent':
            $data = $results['sent'];
            $statusClass = 'success';
            $emptyMsg = '<i class="bi bi-check-circle me-2"></i>No emails sent or all sent successfully viewed here.';
            break;
        case 'failed':
            $data = $results['failed'];
            $statusClass = 'danger';
            $emptyMsg = '<i class="bi bi-check-circle me-2"></i>No failures! All selected emails were sent.';
            break;
        case 'skipped':
            $data = $results['skipped'];
            $statusClass = 'warning';
            $emptyMsg = '<i class="bi bi-check-circle me-2"></i>No recipients were skipped (all were checked).';
            break;
        case 'rejected':
            $data = $results['rejected'];
            $statusClass = 'secondary';
            $emptyMsg = '<i class="bi bi-check-circle me-2"></i>No emails were rejected. All had valid format.';
            break;
        case 'all':
            // Combine all
            $data = [];
            foreach ($results['sent'] as $d) { $d['_status'] = 'sent'; $data[] = $d; }
            foreach ($results['failed'] as $d) { $d['_status'] = 'failed'; $data[] = $d; }
            foreach ($results['skipped'] as $d) { $d['_status'] = 'skipped'; $data[] = $d; }
            foreach ($results['rejected'] as $d) { $d['_status'] = 'rejected'; $data[] = $d; }
            $emptyMsg = 'No data found.';
            break;
    }
    
    if (empty($data)) {
        echo '<div class="card-body"><div class="alert alert-success mb-0">' . $emptyMsg . '</div></div>';
        return;
    }
    ?>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Email</th>
                        <?php if ($tab === 'failed' || $tab === 'all'): ?>
                        <th>Reason</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($data as $d): $n++; 
                    $st = $d['_status'] ?? $tab;
                    $badge = '';
                    switch($st) {
                        case 'sent': $badge = '<span class="badge bg-success"><i class="bi bi-check"></i> Sent</span>'; break;
                        case 'failed': $badge = '<span class="badge bg-danger"><i class="bi bi-x"></i> Failed</span>'; break;
                        case 'skipped': $badge = '<span class="badge bg-warning text-dark"><i class="bi bi-skip-forward"></i> Skipped</span>'; break;
                        case 'rejected': $badge = '<span class="badge bg-secondary"><i class="bi bi-ban"></i> Rejected</span>'; break;
                    }
                    $rowClass = '';
                    if ($st === 'failed') $rowClass = 'table-danger';
                    elseif ($st === 'skipped') $rowClass = 'table-warning';
                    elseif ($st === 'rejected') $rowClass = 'table-secondary';
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td><?php echo $n; ?></td>
                        <td><?php echo $badge; ?></td>
                        <td><?php echo sanitize($d['name']); ?></td>
                        <td><code><?php echo sanitize($d['email']); ?></code></td>
                        <?php if ($tab === 'failed' || $tab === 'all'): ?>
                        <td><small class="text-danger"><?php echo sanitize($d['reason'] ?? '-'); ?></small></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($data) > 100): ?>
        <div class="card-footer text-muted small">
            <i class="bi bi-info-circle me-1"></i>Showing <?php echo min(count($data), 500); ?> of <?php echo count($data); ?> records.
            <?php if (count($data) > 500): ?>
            <?php
            // Export to CSV
            $csvData = "Status,Name,Email,Reason\n";
            foreach ($data as $d) {
                $st = $d['_status'] ?? $tab;
                $csvData .= "$st,\"" . str_replace('"', '""', $d['name']) . "\",\"" . str_replace('"', '""', $d['email']) . "\",\"" . str_replace('"', '""', $d['reason'] ?? '') . "\"\n";
            }
            $_SESSION['bulk_email_csv'] = $csvData;
            ?>
            <a href="index.php?page=notifications/bulk-email-export" class="btn btn-sm btn-outline-primary ms-2">
                <i class="bi bi-download me-1"></i>Export Full CSV
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<script>
// Filter units by client
function loadUnits(clientId) {
    const select = document.getElementById('unitSelect');
    const options = select.querySelectorAll('option');
    options.forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!clientId || opt.dataset.client == clientId) ? '' : 'none';
    });
}

// Insert placeholder at cursor position
function insertPlaceholder(placeholder) {
    const textarea = document.getElementById('emailBody');
    if (!textarea) return;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + placeholder + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
}

// Toggle live HTML preview
function toggleLivePreview() {
    const box = document.getElementById('livePreviewBox');
    if (!box) return;
    if (box.style.display === 'none') {
        box.style.display = '';
        renderLivePreview();
    } else {
        box.style.display = 'none';
    }
}

// Render live preview with sample data
function renderLivePreview() {
    const body = document.getElementById('emailBody');
    if (!body) return;
    const sampleReplacements = {
        '{{name}}': 'Rajesh Kumar', '{{Name}}': 'Rajesh Kumar',
        '{{mobile}}': '9876543210', '{{Mobile}}': '9876543210',
        '{{dob}}': '15/08/1990', '{{DOB}}': '15/08/1990',
        '{{unit}}': 'Unit A - Main Plant', '{{Unit}}': 'Unit A - Main Plant',
        '{{site}}': 'Industrial Area, MIDC', '{{Site}}': 'Industrial Area, MIDC',
        '{{client}}': 'ABC Manufacturing Ltd', '{{Client}}': 'ABC Manufacturing Ltd',
        '{{designation}}': 'Supervisor', '{{Designation}}': 'Supervisor',
        '{{department}}': 'Production', '{{Department}}': 'Production',
        '{{code}}': 'EMP-1042', '{{Code}}': 'EMP-1042'
    };
    let rendered = body.value;
    for (const [key, val] of Object.entries(sampleReplacements)) {
        rendered = rendered.split(key).join(val);
    }
    document.getElementById('livePreviewContent').innerHTML = rendered;
}

// Auto-update live preview on typing
const emailBodyEl = document.getElementById('emailBody');
if (emailBodyEl) {
    emailBodyEl.addEventListener('input', function() {
        const box = document.getElementById('livePreviewBox');
        if (box && box.style.display !== 'none') renderLivePreview();
    });
}

// ---- Checkbox selection functions ----
function toggleAll(checked) {
    document.querySelectorAll('.recipient-check').forEach(function(cb) {
        cb.checked = checked;
    });
    document.getElementById('checkAll').checked = checked;
    updateCounts();
}

function selectAll() {
    toggleAll(true);
}

function deselectAll() {
    toggleAll(false);
}

function invertSelection() {
    document.querySelectorAll('.recipient-check').forEach(function(cb) {
        cb.checked = !cb.checked;
    });
    // Update header checkbox
    const all = document.querySelectorAll('.recipient-check');
    const checked = document.querySelectorAll('.recipient-check:checked');
    document.getElementById('checkAll').checked = (all.length === checked.length);
    updateCounts();
}

function updateCounts() {
    const all = document.querySelectorAll('.recipient-check');
    const checked = document.querySelectorAll('.recipient-check:checked');
    const selCount = checked.length;
    const unselCount = all.length - selCount;
    
    document.getElementById('selectedCount').textContent = selCount;
    document.getElementById('unselectedCount').textContent = unselCount;
    document.getElementById('toolbarSelected').textContent = selCount + ' selected';
    document.getElementById('sendBtnCount').textContent = selCount;
    document.getElementById('hiddenSelectedCount').value = selCount;
    
    // Disable send button if none selected
    const btn = document.getElementById('sendBtn');
    if (btn) {
        btn.disabled = (selCount === 0);
        if (selCount === 0) {
            btn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Select at least one recipient';
        } else {
            btn.innerHTML = '<i class="bi bi-send me-2"></i>Send to ' + selCount + ' Selected Recipients';
        }
    }
    
    // Update header checkbox state
    const checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.checked = (all.length > 0 && all.length === selCount);
        checkAll.indeterminate = (selCount > 0 && selCount < all.length);
    }
}

// Search/filter rows
function filterRows() {
    const query = document.getElementById('searchBox').value.toLowerCase();
    document.querySelectorAll('.recipient-row').forEach(function(row) {
        const email = row.dataset.email || '';
        const name = row.dataset.name || '';
        if (email.indexOf(query) !== -1 || name.indexOf(query) !== -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Intercept form submit to validate selection
const sendForm = document.getElementById('sendForm');
if (sendForm) {
    sendForm.addEventListener('submit', function(e) {
        const checked = document.querySelectorAll('.recipient-check:checked').length;
        if (checked === 0) {
            e.preventDefault();
            alert('Please select at least one recipient to send emails.');
            return false;
        }
        const total = document.querySelectorAll('.recipient-check').length;
        const skipped = total - checked;
        let msg = 'Send emails to ' + checked + ' recipient(s)?';
        if (skipped > 0) {
            msg += '\n\n' + skipped + ' recipient(s) will be SKIPPED (unchecked).';
        }
        msg += '\n\nThis cannot be undone!';
        if (!confirm(msg)) {
            e.preventDefault();
            return false;
        }
    });
}

// Quick templates
const templates = {
    kyc_pending: {
        subject: 'RCS KYC Update Required — {{name}}',
        body: 'Dear {{name}},<br><br>Your RCS KYC may be pending.<br>Kindly go to <a href="https://join.rcsfacility.com"><b>join.rcsfacility.com</b></a> and enter your <b>Mobile No</b> and <b>Birthdate</b> to login and update your details.<br><br><b>Please ignore this mail if your profile is 100% complete.</b><br><br><b>Note:</b> If your birthdate is showing wrong (e.g. 01/02/1980 try 02/01/1980) and you are still getting a login issue, call <b>+91 8469241414</b> and ask for your birthdate from RCS Web App.<br><br>Thank You,<br><b>RCS True Facilities Pvt Ltd</b><br><br><span style="color:#888;font-size:12px;"><i>Please do not reply to this mail. This is a system generated mail.</i></span>'
    },
    general: {
        subject: 'Important Notice for {{name}}',
        body: 'Dear {{name}},<br><br>This is to inform you about an important update regarding your employment at {{client}}.<br><br><b>Unit:</b> {{unit}}<br><b>Site:</b> {{site}}<br><b>Designation:</b> {{designation}}<br><b>Department:</b> {{department}}<br><br>Please take note of the following:<br><br><p>[Enter your message here]</p><br>For any queries, please contact the HR department.<br><br>Best regards,<br><b>RCS TRUE FACILITIES PVT LTD</b><br>HR Department'
    },
    pf_update: {
        subject: 'PF Contribution Update — {{name}} ({{code}})',
        body: 'Dear {{name}},<br><br>We would like to inform you about your PF contribution update.<br><br><b>Employee Code:</b> {{code}}<br><b>Unit:</b> {{unit}}<br><b>Client:</b> {{client}}<br><b>Designation:</b> {{designation}}<br><br>Your Provident Fund details have been updated. Please verify the same through your EPFO account.<br><br><b>Important:</b><br><ul><li>Ensure your UAN is linked with your Aadhaar</li><li>Verify your KYC details on the EPFO portal</li><li>Contact HR for any discrepancies</li></ul><br><br>Best regards,<br><b>RCS TRUE FACILITIES PVT LTD</b><br>HR & Compliance Department'
    },
    esi_update: {
        subject: 'ESI Card Update — {{name}}',
        body: 'Dear {{name}},<br><br>This is regarding your Employee State Insurance (ESI) details.<br><br><b>Employee Code:</b> {{code}}<br><b>Unit:</b> {{unit}}<br><b>Site:</b> {{site}}<br><br>Please ensure:<br><ul><li>Your ESI card is updated with current details</li><li>Your Aadhaar is linked with ESI</li><li>You have the latest ESI Pehchan card</li></ul><br><br>For any ESI-related queries, contact the HR department.<br><br>Best regards,<br><b>RCS TRUE FACILITIES PVT LTD</b><br>HR & Compliance Department'
    },
    holiday: {
        subject: 'Holiday Notice — {{unit}} / {{site}}',
        body: 'Dear {{name}},<br><br>Please be informed of the following holiday:<br><br><b>Holiday:</b> [Holiday Name]<br><b>Date:</b> [Date]<br><b>Applicable for:</b> {{unit}} — {{site}}<br><br>All employees at {{client}} are requested to note this holiday.<br><b>Work resume:</b> [Next working day]<br><br>In case of emergency, please contact your supervisor.<br><br>Best regards,<br><b>RCS TRUE FACILITIES PVT LTD</b><br>HR Department'
    },
    policy: {
        subject: 'Policy Update: [Policy Name] — {{name}}',
        body: 'Dear {{name}},<br><br>We would like to bring to your attention an update to our company policies.<br><br><b>Policy:</b> [Policy Name]<br><b>Effective Date:</b> [Date]<br><br><b>Key Changes:</b><br><ol><li>[Change 1]</li><li>[Change 2]</li><li>[Change 3]</li></ol><br><br>This policy applies to all employees at {{client}} — {{unit}}, {{site}}.<br><br>Please acknowledge this update by contacting HR.<br><br>Best regards,<br><b>RCS TRUE FACILITIES PVT LTD</b><br>Management'
    }
};

function loadTemplate(type) {
    const t = templates[type];
    if (!t) return;
    const subject = document.getElementById('emailSubject');
    const body = document.getElementById('emailBody');
    if (subject) subject.value = t.subject;
    if (body) body.value = t.body;
    const box = document.getElementById('livePreviewBox');
    if (box && box.style.display !== 'none') renderLivePreview();
}

// Init counts on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCounts();
});
</script>
