<?php
/**
 * RCS HRMS Pro - WhatsApp Messaging Module
 * Manual WhatsApp messaging: Single, Bulk (with placeholders), Send History
 *
 * Route: index.php?page=notifications/whatsapp
 */

require_once __DIR__ . '/../../includes/whatsapp.php';

$pageTitle = 'WhatsApp Messaging';

// Ensure tables exist
ensureWhatsAppLogsTable();

// Tab
$tab = $_GET['tab'] ?? 'send';
if (!in_array($tab, ['send', 'bulk', 'history'])) $tab = 'send';

// Handle single send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'send') && ($_POST['action'] ?? '') === 'send_single') {
    $mobile = sanitize($_POST['mobile'] ?? '');
    $message = $_POST['message'] ?? '';
    $employeeId = (int)($_POST['employee_id'] ?? 0);

    if (empty($mobile) || empty($message)) {
        setFlash('error', 'Mobile number and message are required.');
    } else {
        $result = waSend($mobile, $message, $employeeId > 0 ? $employeeId : null);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
    }
    redirect('index.php?page=notifications/whatsapp&tab=send');
}

// ═══════════════════════════════════════════════════════════
//  BULK TAB: Preview + Send with Placeholders
// ═══════════════════════════════════════════════════════════
$resultMessage = '';
$resultType = '';
$currentTab = 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'bulk') {
    $action = $_POST['action'] ?? '';

    // ---- Preview: fetch employees, validate mobiles ----
    if ($action === 'preview') {
        $message = $_POST['message'] ?? '';
        $filterStatus = $_POST['filter_status'] ?? '';
        $filterClient = (int)($_POST['filter_client'] ?? 0);
        $filterUnit = (int)($_POST['filter_unit'] ?? 0);

        if (empty($message)) {
            $resultMessage = 'Message template is required.';
            $resultType = 'danger';
        } else {
            $sql = "SELECT e.id, e.full_name, e.mobile_number, e.employee_code, e.designation, e.department,
                           e.date_of_birth, e.client_id, e.unit_id,
                           c.name as client_name, u.name as unit_name, u.address as site_name
                    FROM employees e
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE e.mobile_number IS NOT NULL AND e.mobile_number != ''";
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
            $sql .= " ORDER BY e.mobile_number ASC";
            $rawRecipients = $db->fetchAll($sql, $params);

            // Validate mobile numbers (must be 10+ digits)
            $recipients = [];
            $rejected = [];
            foreach ($rawRecipients as $r) {
                $cleanMobile = preg_replace('/[^0-9]/', '', $r['mobile_number']);
                if (strlen($cleanMobile) >= 10) {
                    $r['_clean_mobile'] = $cleanMobile;
                    $recipients[] = $r;
                } else {
                    $rejected[] = $r;
                }
            }

            $_SESSION['wa_bulk_preview'] = [
                'message' => $message,
                'recipients' => $recipients,
                'rejected' => $rejected,
                'count' => count($recipients),
                'rejected_count' => count($rejected),
            ];

            $totalRaw = count($rawRecipients);
            $resultMessage = "Found $totalRaw employees: <b>" . count($recipients) . " valid mobile</b>, <b>" . count($rejected) . " rejected</b> (invalid mobile). Review below.";
            $resultType = count($rejected) > 0 ? 'warning' : 'info';
        }
    }

    // ---- Actually send the WhatsApp messages ----
    if ($action === 'send_bulk') {
        $preview = $_SESSION['wa_bulk_preview'] ?? null;

        if (!$preview) {
            setFlash('error', 'No preview data. Please preview first.');
            redirect('index.php?page=notifications/whatsapp&tab=bulk');
        }

        $selectedIndices = $_POST['selected_mobiles'] ?? [];
        if (!is_array($selectedIndices)) $selectedIndices = [];

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
            setFlash('error', 'No recipients selected.');
            redirect('index.php?page=notifications/whatsapp&tab=bulk');
        }

        // Build personalized messages
        $messages = [];
        foreach ($toSend as $r) {
            $rawDob = $r['date_of_birth'] ?? '';
            $formattedDob = '';
            if ($rawDob) {
                try {
                    $formattedDob = (new DateTime($rawDob))->format('d/m/Y');
                } catch (Exception $e) {
                    $formattedDob = $rawDob;
                }
            }

            $replacements = [
                '{{name}}'        => $r['full_name'] ?? 'Employee',
                '{{mobile}}'      => $r['mobile_number'] ?? '',
                '{{dob}}'         => $formattedDob,
                '{{unit}}'        => $r['unit_name'] ?? '',
                '{{site}}'        => $r['site_name'] ?? $r['unit_name'] ?? '',
                '{{client}}'      => $r['client_name'] ?? '',
                '{{designation}}' => $r['designation'] ?? '',
                '{{department}}'  => $r['department'] ?? '',
                '{{code}}'        => $r['employee_code'] ?? '',
                '{{Name}}'        => $r['full_name'] ?? 'Employee',
                '{{Mobile}}'      => $r['mobile_number'] ?? '',
                '{{DOB}}'         => $formattedDob,
                '{{Unit}}'        => $r['unit_name'] ?? '',
                '{{Site}}'        => $r['site_name'] ?? $r['unit_name'] ?? '',
                '{{Client}}'      => $r['client_name'] ?? '',
                '{{Designation}}' => $r['designation'] ?? '',
                '{{Department}}'  => $r['department'] ?? '',
                '{{Code}}'        => $r['employee_code'] ?? '',
            ];

            $personalMsg = str_replace(array_keys($replacements), array_values($replacements), $preview['message']);
            $mobile = waNormalizeMobile($r['_clean_mobile'] ?? $r['mobile_number'] ?? '');
            $messages[] = [
                'number' => $mobile,
                'message' => $personalMsg,
                'employee_id' => $r['id'] ?? null,
                'name' => $r['full_name'] ?? '',
            ];
        }

        // Send via bulk API (server queues them with 3s delay)
        $config = waGetConfig();
        $sent = 0;
        $failed = 0;
        $queued = 0;
        $sentList = [];
        $failedList = [];

        if (empty($config['api_url']) || empty($config['api_key'])) {
            $failed = count($messages);
            foreach ($messages as $m) {
                $failedList[] = ['mobile' => $m['number'], 'name' => $m['name'], 'reason' => 'WhatsApp Bot not configured'];
            }
        } else {
            // Send in batches of 200 to avoid API limits
            $batchSize = 200;
            $totalBatches = ceil(count($messages) / $batchSize);

            for ($batch = 0; $batch < $totalBatches; $batch++) {
                $batchMsgs = array_slice($messages, $batch * $batchSize, $batchSize);
                $apiMsgs = [];
                foreach ($batchMsgs as $m) {
                    $apiMsgs[] = ['number' => $m['number'], 'message' => $m['message']];
                }

                $result = waApiCall('/send-bulk', ['messages' => $apiMsgs], 600);
                $data = $result['data'];

                if ($result['error']) {
                    // Entire batch failed
                    foreach ($batchMsgs as $m) {
                        $failed++;
                        $failedList[] = ['mobile' => $m['number'], 'name' => $m['name'], 'reason' => $result['error']];
                        waLog(['mobile' => $m['number'], 'message' => $m['message'], 'status' => 'failed', 'error' => $result['error'], 'employee_id' => $m['employee_id']]);
                    }
                } else {
                    $bSent = $data['data']['sent'] ?? 0;
                    $bFailed = $data['data']['failed'] ?? 0;
                    $bQueued = $data['data']['queued'] ?? count($apiMsgs);

                    // Log each as queued
                    foreach ($batchMsgs as $m) {
                        waLog(['mobile' => $m['number'], 'message' => $m['message'], 'status' => 'queued', 'employee_id' => $m['employee_id']]);
                    }

                    $sent += $bSent;
                    $failed += $bFailed;
                    $queued += $bQueued;
                }
            }

            // Build sent list (we don't get per-message results from bulk API, treat all queued as sent)
            foreach ($messages as $m) {
                $sentList[] = ['mobile' => $m['number'], 'name' => $m['name']];
            }
        }

        $_SESSION['wa_bulk_results'] = [
            'sent' => $sentList,
            'failed' => $failedList,
            'skipped' => array_map(fn($s) => ['mobile' => waNormalizeMobile($s['_clean_mobile'] ?? $s['mobile_number'] ?? ''), 'name' => $s['full_name'] ?? ''], $skipped),
            'rejected' => array_map(fn($r) => ['mobile' => $r['mobile_number'] ?? '', 'name' => $r['full_name'] ?? '', 'reason' => 'Invalid mobile number'], $preview['rejected'] ?? []),
            'total_sent' => $sent,
            'total_failed' => $failed,
            'total_skipped' => count($skipped),
            'total_rejected' => count($preview['rejected'] ?? []),
            'queued' => $queued,
            'message' => $preview['message'],
        ];

        unset($_SESSION['wa_bulk_preview']);

        $totalAll = count($sentList) + count($failedList) + count($skipped) + count($preview['rejected'] ?? []);
        $resultMessage = "<b>Campaign Complete!</b> Total: $totalAll | <span class='text-success'>Sent/Queued: $sent</span> | <span class='text-danger'>Failed: $failed</span> | <span class='text-warning'>Skipped: " . count($skipped) . "</span> | <span class='text-secondary'>Rejected (invalid mobile): " . count($preview['rejected'] ?? []) . "</span>";
        $resultType = 'success';
        $currentTab = 'sent';
    }

    // Discard
    if ($action === 'discard') {
        unset($_SESSION['wa_bulk_preview']);
        unset($_SESSION['wa_bulk_results']);
        redirect('index.php?page=notifications/whatsapp&tab=bulk');
    }
}

// Stats
$waStats = waGetStats();

// Bot status
$notification = new Notification();
$waBot = $notification->getWhatsAppBotStatus();

// For history tab
$historyPage = max(1, (int)($_GET['page_num'] ?? 1));
$historyStatus = sanitize($_GET['status'] ?? '');
$historySearch = sanitize($_GET['search'] ?? '');
$history = waGetLogs($historyPage, 50, $historyStatus, $historySearch);

// Bulk tab data
$preview = $_SESSION['wa_bulk_preview'] ?? null;
$results = $_SESSION['wa_bulk_results'] ?? null;
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name ASC");
$units = $db->fetchAll("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name ASC");
$mobileCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE mobile_number IS NOT NULL AND mobile_number != '' AND status = 'approved'");
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title"><i class="bi bi-whatsapp me-2 text-success"></i>WhatsApp Messaging</h1>
            <p class="text-muted">Send WhatsApp messages to employees</p>
        </div>
        <div class="col-auto">
            <span class="badge bg-<?php echo $waBot['connected'] ? 'success' : 'secondary'; ?> fs-6 px-3 py-2">
                <?php echo $waBot['connected'] ? '<i class="bi bi-circle-fill me-1"></i>Bot Connected' : '<i class="bi bi-circle me-1"></i>Bot Offline'; ?>
            </span>
        </div>
    </div>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Total Sent</div>
                <div class="fs-4 fw-bold text-success"><?php echo number_format($waStats['sent'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Today</div>
                <div class="fs-4 fw-bold text-primary"><?php echo number_format($waStats['today'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Queued</div>
                <div class="fs-4 fw-bold text-warning"><?php echo number_format($waStats['queued'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Failed</div>
                <div class="fs-4 fw-bold text-danger"><?php echo number_format($waStats['failed'] ?? 0); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'send' ? 'active' : ''; ?>" href="?page=notifications/whatsapp&tab=send">
            <i class="bi bi-send me-1"></i>Single Send
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'bulk' ? 'active' : ''; ?>" href="?page=notifications/whatsapp&tab=bulk">
            <i class="bi bi-people me-1"></i>Bulk Send
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'history' ? 'active' : ''; ?>" href="?page=notifications/whatsapp&tab=history">
            <i class="bi bi-clock-history me-1"></i>Send History
        </a>
    </li>
</ul>

<!-- ===================== SINGLE SEND TAB ===================== -->
<?php if ($tab === 'send'): ?>
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-send me-2"></i>Send Single Message</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="send_single">
                    <input type="hidden" name="employee_id" id="send_emp_id" value="">

                    <div class="mb-3">
                        <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">+91</span>
                            <input type="text" class="form-control" name="mobile" id="send_mobile"
                                   placeholder="Enter 10-digit mobile number"
                                   maxlength="10" pattern="[0-9]{10}" required>
                            <button type="button" class="btn btn-outline-primary" onclick="searchEmployee()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <small class="text-muted">Enter 10-digit number or search employee below</small>
                    </div>

                    <div class="mb-3" id="emp_search_box">
                        <label class="form-label">Or Search Employee</label>
                        <input type="text" class="form-control" id="emp_search_input"
                               placeholder="Type name or employee code..."
                               autocomplete="off">
                        <div id="emp_search_results" class="list-group mt-1" style="max-height:200px;overflow-y:auto;display:none;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="message" rows="5" required
                                  placeholder="Type your message here..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-whatsapp me-2"></i>Send WhatsApp Message
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const empInput = document.getElementById('emp_search_input');
const empResults = document.getElementById('emp_search_results');
let searchTimer;

empInput.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { empResults.style.display = 'none'; return; }
    searchTimer = setTimeout(() => {
        fetch('index.php?page=api/employee-search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { empResults.style.display = 'none'; return; }
                empResults.innerHTML = data.map(e =>
                    `<button type="button" class="list-group-item list-group-item-action" onclick="selectEmployee(${e.id}, '${e.mobile_number || ''}', '${e.full_name.replace(/'/g, "\\'")}')">
                        <strong>${e.employee_code}</strong> &mdash; ${e.full_name}
                        <br><small class="text-muted">${e.mobile_number || 'No mobile'} | ${e.designation || ''}</small>
                    </button>`
                ).join('');
                empResults.style.display = 'block';
            })
            .catch(() => { empResults.style.display = 'none'; });
    }, 300);
});

function selectEmployee(id, mobile, name) {
    document.getElementById('send_emp_id').value = id;
    document.getElementById('send_mobile').value = mobile.replace(/[^0-9]/g, '').slice(-10);
    document.getElementById('emp_search_input').value = name + ' (' + mobile + ')';
    empResults.style.display = 'none';
}

function searchEmployee() {
    const mobile = document.getElementById('send_mobile').value.trim();
    if (mobile.length >= 3) {
        fetch('index.php?page=api/employee-search&q=' + encodeURIComponent(mobile))
            .then(r => r.json())
            .then(data => {
                if (data.length > 0) {
                    selectEmployee(data[0].id, data[0].mobile_number || '', data[0].full_name);
                }
            });
    }
}
</script>

<!-- ===================== BULK SEND TAB ===================== -->
<?php elseif ($tab === 'bulk'): ?>

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
                    <i class="bi bi-check-circle me-1 text-success"></i>Sent/Queued
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
                    <i class="bi bi-skip-forward me-1 text-warning"></i>Skipped
                    <span class="badge bg-warning text-dark"><?php echo $results['total_skipped']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tabRejected">
                    <i class="bi bi-ban me-1 text-secondary"></i>Rejected
                    <span class="badge bg-dark"><?php echo $results['total_rejected']; ?></span>
                </a>
            </li>
        </ul>
    </div>
    <div class="tab-content">
        <?php
        function renderWaResultTable($wtab, $wresults) {
            $wdata = [];
            $wemptyMsg = '';
            switch($wtab) {
                case 'sent':
                    $wdata = $wresults['sent']; $wemptyMsg = 'No sent messages.'; break;
                case 'failed':
                    $wdata = $wresults['failed']; $wemptyMsg = 'No failures!'; break;
                case 'skipped':
                    $wdata = $wresults['skipped']; $wemptyMsg = 'No skipped recipients.'; break;
                case 'rejected':
                    $wdata = $wresults['rejected']; $wemptyMsg = 'No rejected mobiles.'; break;
                case 'all':
                    $wdata = [];
                    foreach ($wresults['sent'] as $d) { $d['_status'] = 'sent'; $wdata[] = $d; }
                    foreach ($wresults['failed'] as $d) { $d['_status'] = 'failed'; $wdata[] = $d; }
                    foreach ($wresults['skipped'] as $d) { $d['_status'] = 'skipped'; $wdata[] = $d; }
                    foreach ($wresults['rejected'] as $d) { $d['_status'] = 'rejected'; $wdata[] = $d; }
                    $wemptyMsg = 'No data found.';
                    break;
            }
            if (empty($wdata)) {
                echo '<div class="card-body"><div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>' . $wemptyMsg . '</div></div>';
                return;
            }
            $showReason = in_array($wtab, ['failed', 'all']);
            $maxShow = 500;
            $displayData = array_slice($wdata, 0, $maxShow);
            ?>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>#</th>
                                <th>Status</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <?php if ($showReason): ?>
                                <th>Reason</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 0; foreach ($displayData as $d): $n++;
                            $st = $d['_status'] ?? $wtab;
                            $badge = '';
                            $rowClass = '';
                            switch($st) {
                                case 'sent': $badge = '<span class="badge bg-success"><i class="bi bi-check"></i> Sent</span>'; break;
                                case 'failed': $badge = '<span class="badge bg-danger"><i class="bi bi-x"></i> Failed</span>'; $rowClass = 'table-danger'; break;
                                case 'skipped': $badge = '<span class="badge bg-warning text-dark"><i class="bi bi-skip-forward"></i> Skipped</span>'; $rowClass = 'table-warning'; break;
                                case 'rejected': $badge = '<span class="badge bg-secondary"><i class="bi bi-ban"></i> Rejected</span>'; $rowClass = 'table-secondary'; break;
                            }
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo $n; ?></td>
                                <td><?php echo $badge; ?></td>
                                <td><?php echo sanitize($d['name']); ?></td>
                                <td><code><?php echo sanitize($d['mobile']); ?></code></td>
                                <?php if ($showReason): ?>
                                <td><small class="text-danger"><?php echo sanitize($d['reason'] ?? '-'); ?></small></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($wdata) > $maxShow): ?>
                <div class="card-footer text-muted small">
                    <i class="bi bi-info-circle me-1"></i>Showing <?php echo $maxShow; ?> of <?php echo count($wdata); ?> records.
                </div>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        <div class="tab-pane fade <?php echo $currentTab == 'all' ? 'show active' : ''; ?>" id="tabAll">
            <?php renderWaResultTable('all', $results); ?>
        </div>
        <div class="tab-pane fade <?php echo $currentTab == 'sent' ? 'show active' : ''; ?>" id="tabSent">
            <?php renderWaResultTable('sent', $results); ?>
        </div>
        <div class="tab-pane fade <?php echo $currentTab == 'failed' ? 'show active' : ''; ?>" id="tabFailed">
            <?php renderWaResultTable('failed', $results); ?>
        </div>
        <div class="tab-pane fade" id="tabSkipped">
            <?php renderWaResultTable('skipped', $results); ?>
        </div>
        <div class="tab-pane fade" id="tabRejected">
            <?php renderWaResultTable('rejected', $results); ?>
        </div>
    </div>
</div>

<div class="d-flex gap-3">
    <a href="index.php?page=notifications/whatsapp&tab=bulk" class="btn btn-success btn-lg flex-grow-1">
        <i class="bi bi-plus-circle me-2"></i>New Bulk Campaign
    </a>
    <form method="POST">
        <input type="hidden" name="tab" value="bulk">
        <input type="hidden" name="action" value="discard">
        <button type="submit" class="btn btn-outline-danger btn-lg">
            <i class="bi bi-trash me-2"></i>Clear Results
        </button>
    </form>
</div>

<?php elseif (!$preview): ?>
<!-- ==================== COMPOSE SCREEN ==================== -->
<form method="POST">
    <input type="hidden" name="action" value="preview">

    <div class="row">
        <!-- Left: Compose -->
        <div class="col-lg-8">
            <!-- Recipient Filters -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-database me-2"></i>Recipient Filters</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Status Filter</label>
                            <select class="form-select" name="filter_status">
                                <option value="">All Statuses</option>
                                <option value="approved" selected>Approved Only</option>
                                <option value="pending_hr_verification">Pending Verification</option>
                                <option value="inactive">Inactive</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <select class="form-select" name="filter_client" onchange="waLoadUnits(this.value)">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit / Site</label>
                            <select class="form-select" name="filter_unit" id="waUnitSelect">
                                <option value="">All Units</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" data-client="<?php echo $u['client_id']; ?>">
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="alert alert-info mb-0 w-100 py-2">
                                <i class="bi bi-people me-1"></i>
                                <b><?php echo number_format($mobileCount); ?></b> employees with valid mobile numbers
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Message Compose -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-pencil-square me-2"></i>Compose Message</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message Template * <span class="badge bg-success">Plain text with placeholders</span></label>

                        <!-- Placeholder buttons -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{name}}')">
                                <i class="bi bi-person me-1"></i>Name
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{mobile}}')">
                                <i class="bi bi-phone me-1"></i>Mobile
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{dob}}')">
                                <i class="bi bi-calendar3 me-1"></i>DOB
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{unit}}')">
                                <i class="bi bi-building me-1"></i>Unit
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{site}}')">
                                <i class="bi bi-geo-alt me-1"></i>Site
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{client}}')">
                                <i class="bi bi-briefcase me-1"></i>Client
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{designation}}')">
                                <i class="bi bi-tag me-1"></i>Designation
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{department}}')">
                                <i class="bi bi-diagram-3 me-1"></i>Department
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{code}}')">
                                <i class="bi bi-upc me-1"></i>Emp Code
                            </button>
                        </div>

                        <textarea class="form-control font-monospace" name="message" id="waMessage" rows="10" required
                                  placeholder="Dear {{name}},

Write your WhatsApp message here...

Your Employee Code: {{code}}
Mobile: {{mobile}}
Unit: {{unit}} - {{site}}
Client: {{client}}
Designation: {{designation}}
Department: {{department}}
DOB: {{dob}}

Thank you.
- RCS TRUE FACILITIES PVT LTD"></textarea>

                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">Use placeholder buttons to insert variables. Plain text only (no HTML).</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="waTogglePreview()">
                                <i class="bi bi-eye me-1"></i>Toggle Preview
                            </button>
                        </div>
                    </div>

                    <!-- Live Preview -->
                    <div id="waLivePreview" class="mb-3" style="display:none;">
                        <label class="form-label text-success"><i class="bi bi-eye me-1"></i>Message Preview (sample data)</label>
                        <div id="waPreviewContent" class="border rounded p-3" style="background:#dcf8c6;max-height:350px;overflow-y:auto;min-height:100px;white-space:pre-wrap;font-family:inherit;font-size:14px;border:1px solid #a5d6a7;"></div>
                    </div>

                    <!-- Quick Templates -->
                    <div class="mb-3">
                        <label class="form-label">Quick Templates</label>
                        <div class="row g-2">
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="waLoadTemplate('general')">
                                    <i class="bi bi-file-text me-1"></i>General Notice
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="waLoadTemplate('kyc_pending')">
                                    <i class="bi bi-exclamation-diamond me-1"></i>KYC Pending
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="waLoadTemplate('pf_update')">
                                    <i class="bi bi-shield-check me-1"></i>PF Update
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="waLoadTemplate('holiday')">
                                    <i class="bi bi-calendar-event me-1"></i>Holiday Notice
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="waLoadTemplate('policy')">
                                    <i class="bi bi-journal-text me-1"></i>Policy Update
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preview Button -->
            <button type="submit" class="btn btn-success btn-lg w-100">
                <i class="bi bi-eye me-2"></i>Generate Preview &mdash; Review Recipients Before Sending
            </button>
        </div>

        <!-- Right: Info Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Placeholders</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Tag</th><th>Replaced With</th></tr></thead>
                        <tbody>
                            <tr><td><code>{{name}}</code></td><td>Full Name</td></tr>
                            <tr class="table-success"><td><code>{{mobile}}</code></td><td>Mobile No.</td></tr>
                            <tr class="table-success"><td><code>{{dob}}</code></td><td>Date of Birth (DD/MM/YYYY)</td></tr>
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
                        <li><strong>Plain text only</strong> &mdash; no HTML for WhatsApp</li>
                        <li>Use <strong>Toggle Preview</strong> to see sample output</li>
                        <li>Messages sent in batches of 200 (3s server delay)</li>
                        <li><strong>Invalid mobiles auto-rejected</strong> before preview</li>
                        <li>Uncheck recipients in preview to skip them</li>
                        <li>DOB formatted as <strong>DD/MM/YYYY</strong></li>
                        <li>+91 prefix added automatically</li>
                    </ul>
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
            <small>Valid Mobiles</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger text-center p-2">
            <div class="fs-4 fw-bold text-danger"><?php echo $preview['rejected_count'] ?? 0; ?></div>
            <small>Rejected (invalid mobile)</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-primary text-center p-2">
            <div class="fs-4 fw-bold text-primary" id="waSelectedCount"><?php echo $preview['count']; ?></div>
            <small>Selected to Send</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning text-center p-2">
            <div class="fs-4 fw-bold text-warning" id="waUnselectedCount">0</div>
            <small>Unselected (will skip)</small>
        </div>
    </div>
</div>

<!-- Preview tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabValidMobiles">
            <i class="bi bi-check2-square me-1"></i>Valid Recipients
            <span class="badge bg-success"><?php echo $preview['count']; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabInvalidMobiles">
            <i class="bi bi-x-octagon me-1 text-danger"></i>Rejected (invalid mobile)
            <span class="badge bg-danger"><?php echo $preview['rejected_count'] ?? 0; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabWaMsgPreview">
            <i class="bi bi-chat-dots me-1"></i>Message Preview
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- Valid Recipients -->
    <div class="tab-pane fade show active" id="tabValidMobiles">
        <form method="POST" id="waSendForm">
            <input type="hidden" name="tab" value="bulk">
            <input type="hidden" name="action" value="send_bulk">

            <!-- Select toolbar -->
            <div class="card mb-2">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success" onclick="waToggleAll(true)">
                            <i class="bi bi-check-all me-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="waToggleAll(false)">
                            <i class="bi bi-square me-1"></i>Deselect All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInvert()">
                            <i class="bi bi-arrow-left-right me-1"></i>Invert
                        </button>
                        <div class="input-group input-group-sm" style="width:200px;">
                            <input type="text" class="form-control" id="waSearchBox" placeholder="Search name/mobile..." oninput="waFilterRows()">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('waSearchBox').value='';waFilterRows();">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary fs-6" id="waToolbarSelected">0 selected</span>
                        <span class="text-muted ms-2">of <?php echo $preview['count']; ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
                        <table class="table table-hover table-sm mb-0" id="waRecipientsTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:40px;text-align:center;">
                                        <input type="checkbox" class="form-check-input" id="waCheckAll" checked onchange="waToggleAll(this.checked)">
                                    </th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Mobile</th>
                                    <th>Code</th>
                                    <th>Unit</th>
                                    <th>Client</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 0; foreach ($preview['recipients'] as $idx => $r): $i++; ?>
                                <tr class="wa-recipient-row" data-mobile="<?php echo strtolower(sanitize($r['mobile_number'] ?? '')); ?>" data-name="<?php echo strtolower(sanitize($r['full_name'] ?? '')); ?>">
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input wa-recipient-check" name="selected_mobiles[]" value="<?php echo $idx; ?>" checked onchange="waUpdateCounts()">
                                    </td>
                                    <td><?php echo $i; ?></td>
                                    <td><?php echo sanitize($r['full_name']); ?></td>
                                    <td><code><?php echo sanitize($r['mobile_number']); ?></code></td>
                                    <td><?php echo sanitize($r['employee_code'] ?? ''); ?></td>
                                    <td><?php echo sanitize($r['unit_name'] ?? ''); ?></td>
                                    <td><?php echo sanitize($r['client_name'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <input type="hidden" id="waHiddenSelected" value="<?php echo $preview['count']; ?>">

            <div class="mt-3">
                <button type="submit" class="btn btn-success btn-lg w-100" id="waSendBtn">
                    <i class="bi bi-whatsapp me-2"></i>Send to <span id="waSendBtnCount"><?php echo $preview['count']; ?></span> Selected Recipients
                </button>
                <small class="text-muted d-block text-center mt-1">
                    Messages queued on server with 3s delay each. Check History tab for status.
                </small>
            </div>
        </form>

        <form method="POST" class="mt-2">
            <input type="hidden" name="tab" value="bulk">
            <input type="hidden" name="action" value="discard">
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-x-circle me-2"></i>Discard &amp; Go Back
            </button>
        </form>
    </div>

    <!-- Rejected Mobiles -->
    <div class="tab-pane fade" id="tabInvalidMobiles">
        <?php if (!empty($preview['rejected'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <b><?php echo count($preview['rejected']); ?></b> mobile numbers were rejected (too short or invalid).
        </div>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="table-danger">
                    <tr><th>#</th><th>Name</th><th>Invalid Mobile</th></tr>
                </thead>
                <tbody>
                    <?php $j = 0; foreach ($preview['rejected'] as $rr): $j++; ?>
                    <tr>
                        <td><?php echo $j; ?></td>
                        <td><?php echo sanitize($rr['full_name'] ?? ''); ?></td>
                        <td><code class="text-danger"><?php echo sanitize($rr['mobile_number'] ?? ''); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>All mobile numbers are valid!</div>
        <?php endif; ?>
    </div>

    <!-- Message Preview -->
    <div class="tab-pane fade" id="tabWaMsgPreview">
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Sample Message (1st recipient)</h6></div>
                    <div class="card-body" style="background:#dcf8c6;border-radius:0 0 8px 8px;white-space:pre-wrap;font-size:14px;border:1px solid #a5d6a7;border-top:none;">
                        <?php
                        $first = $preview['recipients'][0] ?? [];
                        $fDob = $first['date_of_birth'] ?? '';
                        $fDobFmt = '';
                        if ($fDob) { try { $fDobFmt = (new DateTime($fDob))->format('d/m/Y'); } catch(Exception $e) { $fDobFmt = $fDob; } }
                        echo nl2br(sanitize(str_replace(
                            ['{{name}}','{{mobile}}','{{dob}}','{{unit}}','{{site}}','{{client}}','{{designation}}','{{department}}','{{code}}',
                             '{{Name}}','{{Mobile}}','{{DOB}}','{{Unit}}','{{Site}}','{{Client}}','{{Designation}}','{{Department}}','{{Code}}'],
                            [$first['full_name'] ?? '[Name]',$first['mobile_number'] ?? '[Mobile]',$fDobFmt,
                             $first['unit_name'] ?? '[Unit]',$first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',$first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',$first['employee_code'] ?? '[Code]',
                             $first['full_name'] ?? '[Name]',$first['mobile_number'] ?? '[Mobile]',$fDobFmt,
                             $first['unit_name'] ?? '[Unit]',$first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',$first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',$first['employee_code'] ?? '[Code]'],
                            $preview['message']
                        )));
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-code-slash me-2"></i>Raw Template</h6></div>
                    <div class="card-body">
                        <pre class="mb-0" style="font-size:13px;max-height:350px;overflow-y:auto;white-space:pre-wrap;"><?php echo sanitize($preview['message']); ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Filter units by client
function waLoadUnits(clientId) {
    const select = document.getElementById('waUnitSelect');
    if (!select) return;
    select.querySelectorAll('option').forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!clientId || opt.dataset.client == clientId) ? '' : 'none';
    });
    select.value = '';
}

// Insert placeholder at cursor
function waInsertPlaceholder(placeholder) {
    const textarea = document.getElementById('waMessage');
    if (!textarea) return;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + placeholder + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
    // Update live preview if visible
    const box = document.getElementById('waLivePreview');
    if (box && box.style.display !== 'none') waRenderPreview();
}

// Toggle live preview
function waTogglePreview() {
    const box = document.getElementById('waLivePreview');
    if (!box) return;
    if (box.style.display === 'none') {
        box.style.display = '';
        waRenderPreview();
    } else {
        box.style.display = 'none';
    }
}

// Render preview with sample data
function waRenderPreview() {
    const body = document.getElementById('waMessage');
    if (!body) return;
    const sample = {
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
    for (const [key, val] of Object.entries(sample)) {
        rendered = rendered.split(key).join(val);
    }
    document.getElementById('waPreviewContent').textContent = rendered;
}

// Auto-update preview on typing
const waMsgEl = document.getElementById('waMessage');
if (waMsgEl) {
    waMsgEl.addEventListener('input', function() {
        const box = document.getElementById('waLivePreview');
        if (box && box.style.display !== 'none') waRenderPreview();
    });
}

// Quick templates
const waTemplates = {
    general: "Dear {{name}},\n\nThis is to inform you about an important update regarding your employment at {{client}}.\n\nUnit: {{unit}}\nSite: {{site}}\nDesignation: {{designation}}\nDepartment: {{department}}\n\n[Enter your message here]\n\nFor any queries, please contact the HR department.\n\nBest regards,\nRCS TRUE FACILITIES PVT LTD\nHR Department",
    kyc_pending: "Dear {{name}},\n\nYour RCS KYC may be pending.\n\nKindly go to https://join.rcsfacility.com and enter your Mobile No and Birthdate to login and update your details.\n\n*Please ignore this message if your profile is 100% complete.*\n\n*Note:* If your birthdate is showing wrong, call +91 8469241414 and ask for your birthdate from RCS Web App.\n\nThank You,\nRCS True Facilities Pvt Ltd",
    pf_update: "Dear {{name}},\n\nWe would like to inform you about your PF contribution update.\n\nEmployee Code: {{code}}\nUnit: {{unit}}\nClient: {{client}}\nDesignation: {{designation}}\n\nYour Provident Fund details have been updated. Please verify through your EPFO account.\n\nImportant:\n- Ensure your UAN is linked with Aadhaar\n- Verify KYC details on EPFO portal\n- Contact HR for any discrepancies\n\nBest regards,\nRCS TRUE FACILITIES PVT LTD\nHR & Compliance Department",
    holiday: "Dear {{name}},\n\nPlease be informed of the following holiday:\n\nHoliday: [Holiday Name]\nDate: [Date]\nApplicable for: {{unit}} - {{site}}\n\nAll employees at {{client}} are requested to note this holiday.\nWork resume: [Next working day]\n\nIn case of emergency, contact your supervisor.\n\nBest regards,\nRCS TRUE FACILITIES PVT LTD\nHR Department",
    policy: "Dear {{name}},\n\nWe would like to bring to your attention an update to our company policies.\n\nPolicy: [Policy Name]\nEffective Date: [Date]\n\nKey Changes:\n1. [Change 1]\n2. [Change 2]\n3. [Change 3]\n\nThis policy applies to all employees at {{client}} - {{unit}}, {{site}}.\n\nPlease acknowledge by contacting HR.\n\nBest regards,\nRCS TRUE FACILITIES PVT LTD\nManagement"
};

function waLoadTemplate(type) {
    const t = waTemplates[type];
    if (!t) return;
    const body = document.getElementById('waMessage');
    if (body) body.value = t;
    const box = document.getElementById('waLivePreview');
    if (box && box.style.display !== 'none') waRenderPreview();
}

// ---- Checkbox selection (preview screen) ----
function waToggleAll(checked) {
    document.querySelectorAll('.wa-recipient-check').forEach(function(cb) { cb.checked = checked; });
    const ca = document.getElementById('waCheckAll');
    if (ca) ca.checked = checked;
    waUpdateCounts();
}

function waInvert() {
    document.querySelectorAll('.wa-recipient-check').forEach(function(cb) { cb.checked = !cb.checked; });
    const all = document.querySelectorAll('.wa-recipient-check');
    const checked = document.querySelectorAll('.wa-recipient-check:checked');
    const ca = document.getElementById('waCheckAll');
    if (ca) { ca.checked = (all.length === checked.length); ca.indeterminate = (checked.length > 0 && checked.length < all.length); }
    waUpdateCounts();
}

function waUpdateCounts() {
    const all = document.querySelectorAll('.wa-recipient-check');
    const checked = document.querySelectorAll('.wa-recipient-check:checked');
    const sel = checked.length;
    const unsel = all.length - sel;

    document.getElementById('waSelectedCount').textContent = sel;
    document.getElementById('waUnselectedCount').textContent = unsel;
    document.getElementById('waToolbarSelected').textContent = sel + ' selected';
    document.getElementById('waSendBtnCount').textContent = sel;
    document.getElementById('waHiddenSelected').value = sel;

    const btn = document.getElementById('waSendBtn');
    if (btn) {
        btn.disabled = (sel === 0);
        if (sel === 0) {
            btn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Select at least one recipient';
        } else {
            btn.innerHTML = '<i class="bi bi-whatsapp me-2"></i>Send to ' + sel + ' Selected Recipients';
        }
    }

    const ca = document.getElementById('waCheckAll');
    if (ca) { ca.checked = (all.length > 0 && all.length === sel); ca.indeterminate = (sel > 0 && sel < all.length); }
}

function waFilterRows() {
    const q = document.getElementById('waSearchBox').value.toLowerCase();
    document.querySelectorAll('.wa-recipient-row').forEach(function(row) {
        const mobile = row.dataset.mobile || '';
        const name = row.dataset.name || '';
        row.style.display = (mobile.indexOf(q) !== -1 || name.indexOf(q) !== -1) ? '' : 'none';
    });
}

// Intercept send form
const waForm = document.getElementById('waSendForm');
if (waForm) {
    waForm.addEventListener('submit', function(e) {
        const checked = document.querySelectorAll('.wa-recipient-check:checked').length;
        if (checked === 0) {
            e.preventDefault();
            alert('Please select at least one recipient.');
            return false;
        }
        const total = document.querySelectorAll('.wa-recipient-check').length;
        const skipped = total - checked;
        let msg = 'Send WhatsApp messages to ' + checked + ' recipient(s)?';
        if (skipped > 0) msg += '\n\n' + skipped + ' recipient(s) will be SKIPPED (unchecked).';
        msg += '\n\nMessages will be queued on the WhatsApp server.';
        if (!confirm(msg)) { e.preventDefault(); return false; }
    });
}

document.addEventListener('DOMContentLoaded', function() { waUpdateCounts(); });
</script>

<!-- ===================== HISTORY TAB ===================== -->
<?php elseif ($tab === 'history'): ?>
<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Send History</h5>
            </div>
            <div class="col-auto">
                <span class="badge bg-secondary"><?php echo number_format($history['pagination']['total']); ?> messages</span>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <input type="hidden" name="page" value="notifications/whatsapp">
            <input type="hidden" name="tab" value="history">
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="sent" <?php echo $historyStatus === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="queued" <?php echo $historyStatus === 'queued' ? 'selected' : ''; ?>>Queued</option>
                    <option value="failed" <?php echo $historyStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="search"
                       value="<?php echo sanitize($historySearch); ?>"
                       placeholder="Search mobile, message, or name...">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
            <div class="col-md-2">
                <?php if (!empty($historyStatus) || !empty($historySearch)): ?>
                <a href="?page=notifications/whatsapp&tab=history" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Mobile</th>
                        <th>Employee</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th>Sent At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history['items'])): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No messages found</td></tr>
                    <?php else: ?>
                    <?php foreach ($history['items'] as $i => $log): ?>
                    <tr>
                        <td><?php echo ($historyPage - 1) * 50 + $i + 1; ?></td>
                        <td><code><?php echo sanitize($log['mobile']); ?></code></td>
                        <td>
                            <?php if (!empty($log['full_name'])): ?>
                            <span class="fw-medium"><?php echo sanitize($log['full_name']); ?></span>
                            <br><small class="text-muted"><?php echo sanitize($log['employee_code'] ?? ''); ?></small>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                 title="<?php echo htmlspecialchars($log['message']); ?>">
                                <?php echo sanitize(mb_substr($log['message'], 0, 80)); ?>
                                <?php if (mb_strlen($log['message']) > 80) echo '...'; ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $statusBadge = ['sent'=>'success','queued'=>'warning','failed'=>'danger','link_generated'=>'info'];
                            $statusLabel = ['sent'=>'Sent','queued'=>'Queued','failed'=>'Failed','link_generated'=>'Link'];
                            $bg = $statusBadge[$log['status']] ?? 'secondary';
                            $label = $statusLabel[$log['status']] ?? $log['status'];
                            ?>
                            <span class="badge bg-<?php echo $bg; ?>"><?php echo $label; ?></span>
                        </td>
                        <td>
                            <?php if (!empty($log['error'])): ?>
                            <small class="text-danger" title="<?php echo htmlspecialchars($log['error']); ?>">
                                <?php echo sanitize(mb_substr($log['error'], 0, 40)); ?><?php if (mb_strlen($log['error']) > 40) echo '...'; ?>
                            </small>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo date('d M Y H:i', strtotime($log['created_at'])); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($history['pagination']['total_pages'] > 1): ?>
        <nav>
            <ul class="pagination pagination-sm justify-content-center">
                <?php
                $p = $history['pagination'];
                $maxVisible = 5;
                $startPage = max(1, $p['page'] - floor($maxVisible / 2));
                $endPage = min($p['total_pages'], $startPage + $maxVisible - 1);
                if ($endPage - $startPage < $maxVisible - 1) { $startPage = max(1, $endPage - $maxVisible + 1); }
                $qs = http_build_query(array_filter(['tab' => 'history', 'status' => $historyStatus, 'search' => $historySearch]));
                ?>
                <?php if ($p['page'] > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=notifications/whatsapp&<?php echo $qs; ?>&page_num=<?php echo $p['page'] - 1; ?>">&laquo;</a>
                </li>
                <?php endif; ?>
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i === $p['page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=notifications/whatsapp&<?php echo $qs; ?>&page_num=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($p['page'] < $p['total_pages']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=notifications/whatsapp&<?php echo $qs; ?>&page_num=<?php echo $p['page'] + 1; ?>">&raquo;</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
