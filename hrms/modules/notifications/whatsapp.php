<?php
/**
 * RCS HRMS Pro - WhatsApp Messaging Module
 * Manual WhatsApp messaging: Single, Bulk (with placeholders), Send History
 *
 * Route: index.php?page=notifications/whatsapp
 */

require_once __DIR__ . '/../../includes/whatsapp.php';

$pageTitle = 'WhatsApp Messaging';

// Ensure tables exist (also self-heals the employees.whatsapp_opted_in column).
ensureWhatsAppLogsTable();
ensureEmployeesWhatsAppOptIn();

// Tab
$tab = $_GET['tab'] ?? 'send';
if (!in_array($tab, ['send', 'bulk', 'history'])) $tab = 'send';

// Handle single send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'send') && ($_POST['action'] ?? '') === 'send_single') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
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
//  HISTORY TAB: Retry queued messages
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'history' && ($_POST['action'] ?? '') === 'retry_queued') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect('index.php?page=notifications/whatsapp&tab=history');
    }

    $queued = $db->fetchAll("SELECT * FROM whatsapp_logs WHERE status IN ('queued','retry_wait') ORDER BY id ASC");

    if (empty($queued)) {
        setFlash('info', 'No queued messages to retry.');
        redirect('index.php?page=notifications/whatsapp&tab=history');
    }

    // Re-arm the existing rows: reset the attempt counter and make them due now.
    // The bot's worker claims them from the DB — no duplicate rows, no in-memory loop.
    $stmt = $db->query(
        "UPDATE whatsapp_logs
         SET status = 'queued', attempts = 0, error = NULL, available_at = NOW(), updated_at = NOW()
         WHERE status IN ('queued','retry_wait')"
    );
    $rearmed = $stmt ? $stmt->rowCount() : 0;

    // Clear any admin pause so the worker actually resumes.
    waQueuePause(false);

    setFlash('success', "Re-queued $rearmed message(s). The bot sends them gradually and reports the real delivery status.");
    redirect('index.php?page=notifications/whatsapp&tab=history');
}

// ═══════════════════════════════════════════════════════════
//  BULK TAB: Preview + Send with Placeholders
// ═══════════════════════════════════════════════════════════
$resultMessage = '';
$resultType = '';
$currentTab = 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'bulk') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
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
                           e.father_name, e.gender, e.blood_group, e.email, e.address, e.pin_code,
                           e.state, e.district, e.uan_number, e.esic_number, e.marital_status,
                           e.date_of_joining, e.employment_type, e.worker_category, e.status as emp_status,
                           e.bank_name, e.ifsc_code, e.account_holder_name, e.alternate_mobile,
                           e.emergency_contact_name, e.emergency_contact_relation,
                           e.nominee_name, e.nominee_relationship,
                           c.name as client_name, u.name as unit_name, u.address as site_name,
                           ess.gross_salary
                    FROM employees e
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                    WHERE e.mobile_number IS NOT NULL AND e.mobile_number != ''
                    AND e.whatsapp_opted_in = 1";
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
            $birthYear = '';
            if ($rawDob) {
                try {
                    $dobDt = new DateTime($rawDob);
                    $formattedDob = $dobDt->format('d/m/Y');
                    $birthYear = $dobDt->format('Y');
                } catch (Exception $e) {
                    $formattedDob = $rawDob;
                }
            }

            $rawDoj = $r['date_of_joining'] ?? '';
            $formattedDoj = '';
            if ($rawDoj) {
                try {
                    $formattedDoj = (new DateTime($rawDoj))->format('d/m/Y');
                } catch (Exception $e) {
                    $formattedDoj = $rawDoj;
                }
            }

            $fGross = '';
            if (!empty($r['gross_salary'])) {
                $fGross = number_format((float)$r['gross_salary'], 2);
            }

            // Full placeholder map — matches the buttons shown in the Compose screen.
            $replacements = [
                '{{name}}'               => $r['full_name'] ?? 'Employee',
                '{{mobile}}'             => $r['mobile_number'] ?? '',
                '{{dob}}'                => $formattedDob,
                '{{birthyear}}'          => $birthYear,
                '{{unit}}'               => $r['unit_name'] ?? '',
                '{{site}}'               => $r['site_name'] ?? $r['unit_name'] ?? '',
                '{{client}}'             => $r['client_name'] ?? '',
                '{{designation}}'        => $r['designation'] ?? '',
                '{{department}}'         => $r['department'] ?? '',
                '{{code}}'               => $r['employee_code'] ?? '',
                '{{email}}'              => $r['email'] ?? '',
                '{{father_name}}'        => $r['father_name'] ?? '',
                '{{gender}}'             => $r['gender'] ?? '',
                '{{blood_group}}'        => $r['blood_group'] ?? '',
                '{{marital_status}}'     => $r['marital_status'] ?? '',
                '{{doj}}'                => $formattedDoj,
                '{{address}}'            => $r['address'] ?? '',
                '{{pin_code}}'           => $r['pin_code'] ?? '',
                '{{state}}'              => $r['state'] ?? '',
                '{{district}}'           => $r['district'] ?? '',
                '{{employment_type}}'    => $r['employment_type'] ?? '',
                '{{worker_category}}'    => $r['worker_category'] ?? '',
                '{{emp_status}}'         => $r['emp_status'] ?? '',
                '{{uan}}'                => $r['uan_number'] ?? '',
                '{{esic}}'               => $r['esic_number'] ?? '',
                '{{gross_salary}}'       => $fGross,
                '{{bank_name}}'          => $r['bank_name'] ?? '',
                '{{ifsc_code}}'          => $r['ifsc_code'] ?? '',
                '{{account_holder}}'     => $r['account_holder_name'] ?? '',
                '{{alt_mobile}}'         => $r['alternate_mobile'] ?? '',
                '{{emergency_contact}}'  => $r['emergency_contact_name'] ?? '',
                '{{nominee_name}}'       => $r['nominee_name'] ?? '',
                '{{Name}}'               => $r['full_name'] ?? 'Employee',
                '{{Mobile}}'             => $r['mobile_number'] ?? '',
                '{{DOB}}'                => $formattedDob,
                '{{BirthYear}}'          => $birthYear,
                '{{Unit}}'               => $r['unit_name'] ?? '',
                '{{Site}}'               => $r['site_name'] ?? $r['unit_name'] ?? '',
                '{{Client}}'             => $r['client_name'] ?? '',
                '{{Designation}}'        => $r['designation'] ?? '',
                '{{Department}}'         => $r['department'] ?? '',
                '{{Code}}'               => $r['employee_code'] ?? '',
                '{{Email}}'              => $r['email'] ?? '',
                '{{Father_Name}}'        => $r['father_name'] ?? '',
                '{{Gender}}'             => $r['gender'] ?? '',
                '{{Blood_Group}}'        => $r['blood_group'] ?? '',
                '{{Marital_Status}}'     => $r['marital_status'] ?? '',
                '{{DOJ}}'                => $formattedDoj,
                '{{Address}}'            => $r['address'] ?? '',
                '{{Pin_Code}}'           => $r['pin_code'] ?? '',
                '{{State}}'              => $r['state'] ?? '',
                '{{District}}'           => $r['district'] ?? '',
                '{{Employment_Type}}'    => $r['employment_type'] ?? '',
                '{{Worker_Category}}'    => $r['worker_category'] ?? '',
                '{{Emp_Status}}'         => $r['emp_status'] ?? '',
                '{{UAN}}'                => $r['uan_number'] ?? '',
                '{{ESIC}}'               => $r['esic_number'] ?? '',
                '{{Gross_Salary}}'       => $fGross,
                '{{Bank_Name}}'          => $r['bank_name'] ?? '',
                '{{IFSC_Code}}'          => $r['ifsc_code'] ?? '',
                '{{Account_Holder}}'     => $r['account_holder_name'] ?? '',
                '{{Alt_Mobile}}'         => $r['alternate_mobile'] ?? '',
                '{{Emergency_Contact}}'  => $r['emergency_contact_name'] ?? '',
                '{{Nominee_Name}}'       => $r['nominee_name'] ?? '',
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
            // PERSISTENT QUEUE: rows are inserted as 'queued' with a campaign id and
            // are sent GRADUALLY by the bot's single worker (paced, sequential,
            // DB-backed). Nothing is marked 'sent' here. Status becomes truthful
            // only when the worker confirms an actual WhatsApp send.
            $campaignId = 'wa' . date('ymd') . bin2hex(random_bytes(6));
            $queueRows = [];
            foreach ($messages as $m) {
                $queueRows[] = [
                    'mobile'      => $m['number'],
                    'message'     => $m['message'],
                    'employee_id' => $m['employee_id'],
                ];
            }

            // Insert into whatsapp_logs as the queue itself (Option A).
            foreach ($queueRows as $qr) {
                $logId = waLog([
                    'mobile'      => $qr['mobile'],
                    'message'     => $qr['message'],
                    'status'      => 'queued',
                    'employee_id' => $qr['employee_id'],
                    'campaign_id' => $campaignId,
                    'available_at' => date('Y-m-d H:i:s'),
                ]);
                if ($logId <= 0) {
                    $failed++;
                    $failedList[] = ['mobile' => $qr['mobile'], 'name' => '', 'reason' => 'Could not queue message'];
                } else {
                    $queued++;
                }
            }

            // Wake the delivery-callback path is no longer needed: the bot's worker
            // claims rows straight from the DB. We only nudge it once so a healthy
            // connection starts working immediately instead of on the next tick.
            // No nudge call needed: the bot's single worker polls the queue every
            // few seconds and claims rows straight from the DB. The HTTP request
            // returns immediately and never waits for delivery.

            // Populate the results panel. These rows are QUEUED for gradual
            // delivery — the panel labels them accordingly; real status lives
            // in the Send History tab.
            foreach ($queueRows as $qr) {
                $sentList[] = ['mobile' => $qr['mobile'], 'name' => ''];
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
        $resultMessage = "<b>Campaign Submitted!</b> Total: $totalAll | <span class='text-warning'>Queued: $queued</span> | <span class='text-danger'>Failed: $failed</span> | <span class='text-warning'>Skipped: " . count($skipped) . "</span> | <span class='text-secondary'>Rejected (invalid mobile): " . count($preview['rejected'] ?? []) . "</span><br><small class='text-muted'>Status updates in real time: Queued → Sending → Sent (or Failed/Retry) as the bot confirms each delivery — check the Send History tab.</small>";
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

// Helper: column-exists check. MUST be defined BEFORE its first use below —
// PHP does not hoist functions defined inside `if (!function_exists(...))`
// blocks, so calling columnExists() before this definition would throw a
// fatal "Call to undefined function" error (which is what caused the white
// screen on this page in commit 8f8e679).
if (!function_exists('columnExists')) {
    function columnExists($db, string $table, string $column): bool {
        try {
            $cnt = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c",
                [':t' => $table, ':c' => $column]
            );
            return $cnt > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

// Recipient counts now reflect the bulk-safe redesign: only opted-in
// employees are eligible for bulk sends. Legacy installs without the
// whatsapp_opted_in column (migration runs on first page load) treat
// everyone as opted-in (column DEFAULT 1), so this degrades gracefully.
$mobileCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM employees
     WHERE mobile_number IS NOT NULL AND mobile_number != ''
       AND status = 'approved'"
    . (columnExists($db, 'employees', 'whatsapp_opted_in') ? ' AND whatsapp_opted_in = 1' : '')
);
// Opted-out count = eligible mobile + approved, but consent = 0.
// Surfaced in the Bulk tab so HR can see how many employees have opted out.
$optedOutCount = columnExists($db, 'employees', 'whatsapp_opted_in')
    ? (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees
         WHERE mobile_number IS NOT NULL AND mobile_number != ''
           AND status = 'approved' AND whatsapp_opted_in = 0"
      )
    : 0;

// Live queue status (used by the Bulk + History tabs). Fetches from the
// bot over X-API-Key. Degrades gracefully when the bot is unreachable.
$queueStats = waQueueStats();
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
        <?php if (!$waBot['connected']): ?>
        <!-- Connection warning — single sends go out synchronously, so an
             offline bot means the send will fail with a 503 immediately. -->
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>WhatsApp Bot is offline.</strong>
            Single sends will fail until the bot is reconnected.
            Go to <a href="index.php?page=settings/notifications">Settings → Notifications</a>
            to log in (QR or phone code).
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-send me-2"></i>Send Single Message</h5>
            </div>
            <div class="card-body">
                <form method="POST">
            <?php echo getCSRFTokenField(); ?>
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

<!-- ── Bulk-safe redesign status panel ─────────────────────────────────
     Shows the operator the live queue state before they compose a campaign.
     Visible on the Compose screen (not the Results screen). -->
<?php if (!$results): ?>
<div class="card mb-3 border-info">
    <div class="card-header bg-info text-white py-2">
        <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Bulk Send — Persistent Queue</h6>
    </div>
    <div class="card-body py-3">
        <div class="row g-3 small">
            <!-- Connection state -->
            <div class="col-md-3">
                <div class="text-muted">Bot Connection</div>
                <?php if ($waBot['connected']): ?>
                    <span class="badge bg-success"><i class="bi bi-circle-fill me-1"></i>Connected</span>
                    <?php if (!empty($waBot['phone'])): ?>
                    <small class="d-block text-muted mt-1"><?php echo sanitize($waBot['phone']); ?></small>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge bg-danger"><i class="bi bi-circle me-1"></i>Offline</span>
                    <small class="d-block text-muted mt-1">
                        Messages will still be queued, but won't send until the bot reconnects.
                        <a href="index.php?page=settings/notifications">Fix in Settings →</a>
                    </small>
                <?php endif; ?>
            </div>

            <!-- Queue counters -->
            <div class="col-md-3">
                <div class="text-muted">Live Queue</div>
                <strong class="text-warning"><?php echo number_format((int)($queueStats['queued'] ?? 0)); ?></strong> queued
                &middot;
                <strong class="text-primary"><?php echo number_format((int)($queueStats['sending'] ?? 0)); ?></strong> sending
                &middot;
                <strong class="text-secondary"><?php echo number_format((int)($queueStats['retry_wait'] ?? 0)); ?></strong> retry-wait
            </div>

            <!-- Today's progress -->
            <div class="col-md-3">
                <div class="text-muted">Today's Sends</div>
                <strong><?php echo number_format((int)($queueStats['sent_today'] ?? 0)); ?></strong>
                / <?php echo number_format((int)($queueStats['day_limit'] ?? 200)); ?> (day limit)
                <?php if (!empty($queueStats['day_limit_hit'])): ?>
                    <span class="badge bg-warning text-dark ms-1">Limit hit</span>
                <?php endif; ?>
            </div>

            <!-- Opt-in summary -->
            <div class="col-md-3">
                <div class="text-muted">Eligible Recipients</div>
                <strong><?php echo number_format($mobileCount); ?></strong> opted-in
                <?php if ($optedOutCount > 0): ?>
                <small class="d-block text-muted">
                    <?php echo number_format($optedOutCount); ?> opted out (excluded automatically)
                </small>
                <?php endif; ?>
            </div>
        </div>

        <hr class="my-2">
        <div class="small text-muted">
            <i class="bi bi-shield-check me-1 text-success"></i>
            Messages are sent gradually by the bot's single worker
            (<?php echo (int)($queueStats['config']['interval_min_s'] ?? 20); ?>–<?php echo (int)($queueStats['config']['interval_max_s'] ?? 45); ?>s apart,
            max <?php echo (int)($queueStats['config']['batch_limit'] ?? 25); ?> per batch with a
            <?php echo (int)($queueStats['config']['batch_cooldown_s'] ?? 300); ?>s breather).
            <a href="index.php?page=settings/notifications">Adjust pacing in Settings →</a>
            &middot;
            <i class="bi bi-shield-lock me-1 text-success"></i>
            Duplicate messages to the same recipient within 24 hours are auto-skipped.
        </div>
    </div>
</div>

<?php
// Block-risk warning — shown when the queue state suggests WhatsApp may
// block the account. Two triggers:
//   1. > 100 pending messages (large backlog → burst risk on resume)
//   2. > 5% failure rate on recent sends (recipients reporting/blocking)
// Both are heuristics — they don't prove a block is imminent, but they
// correlate with the conditions that led to past blocks.
$pendingCount = (int)($queueStats['queued'] ?? 0) + (int)($queueStats['retry_wait'] ?? 0);
$sentTotal = (int)($queueStats['sent'] ?? 0) + (int)($queueStats['sent_today'] ?? 0);
$failedTotal = (int)($queueStats['failed'] ?? 0);
$failureRate = ($sentTotal + $failedTotal > 0) ? ($failedTotal / ($sentTotal + $failedTotal) * 100) : 0;
$highBacklog = $pendingCount > 100;
$highFailureRate = $failureRate > 5 && ($sentTotal + $failedTotal) > 20;
if ($highBacklog || $highFailureRate):
?>
<div class="alert alert-warning mb-3">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong>High block risk detected.</strong>
    <ul class="mb-0 mt-1 small">
        <?php if ($highBacklog): ?>
        <li><strong><?php echo number_format($pendingCount); ?></strong> messages pending in the queue.
            A large backlog drained at once looks like automated bulk sending to WhatsApp's
            heuristics. Consider purging non-essential messages or spreading the send
            across multiple days.</li>
        <?php endif; ?>
        <?php if ($highFailureRate): ?>
        <li>Failure rate is <strong><?php echo number_format($failureRate, 1); ?>%</strong>
            (<?php echo $failedTotal; ?> failed out of <?php echo $sentTotal + $failedTotal; ?> attempts).
            High failure rates usually mean recipients are reporting the messages as spam or
            blocking the number. Review message content and recipient opt-in status.</li>
        <?php endif; ?>
    </ul>
    <div class="mt-2">
        <a href="index.php?page=notifications/whatsapp&tab=history" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-clock-history me-1"></i>Review in History tab
        </a>
    </div>
</div>
<?php endif; ?>

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
            <?php echo getCSRFTokenField(); ?>
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
            <?php echo getCSRFTokenField(); ?>
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
                        <!-- Row 1: Core -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{name}}')"><i class="bi bi-person me-1"></i>Name</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{mobile}}')"><i class="bi bi-phone me-1"></i>Mobile</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{dob}}')"><i class="bi bi-calendar3 me-1"></i>DOB</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{birthyear}}')"><i class="bi bi-calendar-minus me-1"></i>Birth Year</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{unit}}')"><i class="bi bi-building me-1"></i>Unit</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{site}}')"><i class="bi bi-geo-alt me-1"></i>Site</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{client}}')"><i class="bi bi-briefcase me-1"></i>Client</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{designation}}')"><i class="bi bi-tag me-1"></i>Designation</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{department}}')"><i class="bi bi-diagram-3 me-1"></i>Department</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="waInsertPlaceholder('{{code}}')"><i class="bi bi-upc me-1"></i>Emp Code</button>
                        </div>
                        <!-- Row 2: Personal -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{email}}')"><i class="bi bi-envelope me-1"></i>Email</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{father_name}}')"><i class="bi bi-person-heart me-1"></i>Father</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{gender}}')"><i class="bi bi-gender-ambiguous me-1"></i>Gender</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{blood_group}}')"><i class="bi bi-droplet me-1"></i>Blood Grp</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{marital_status}}')"><i class="bi bi-heart me-1"></i>Marital</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{doj}}')"><i class="bi bi-calendar-plus me-1"></i>DOJ</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{address}}')"><i class="bi bi-pin-map me-1"></i>Address</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="waInsertPlaceholder('{{alt_mobile}}')"><i class="bi bi-phone-landscape me-1"></i>Alt Mobile</button>
                        </div>
                        <!-- Row 3: Employment & Bank -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{employment_type}}')"><i class="bi bi-person-badge me-1"></i>Emp Type</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{worker_category}}')"><i class="bi bi-people me-1"></i>Worker Cat</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{emp_status}}')"><i class="bi bi-p-circle me-1"></i>Status</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{uan}}')"><i class="bi bi-shield-check me-1"></i>UAN</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{esic}}')"><i class="bi bi-heart-pulse me-1"></i>ESIC</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="waInsertPlaceholder('{{gross_salary}}')"><i class="bi bi-currency-rupee me-1"></i>Gross Salary</button>
                        </div>
                        <!-- Row 4: Location & Contact -->
                        <div class="btn-group mb-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{pin_code}}')"><i class="bi bi-signpost me-1"></i>PIN Code</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{state}}')"><i class="bi bi-map me-1"></i>State</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{district}}')"><i class="bi bi-geo me-1"></i>District</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{bank_name}}')"><i class="bi bi-bank me-1"></i>Bank</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{ifsc_code}}')"><i class="bi bi-credit-card me-1"></i>IFSC</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{account_holder}}')"><i class="bi bi-person-check me-1"></i>Acct Holder</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{emergency_contact}}')"><i class="bi bi-telephone me-1"></i>Emerg Contact</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="waInsertPlaceholder('{{nominee_name}}')"><i class="bi bi-person-lines-fill me-1"></i>Nominee</button>
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
                        <?php
                        // Inject the first real recipient's data so the live
                        // preview shows an actual personalised message instead
                        // of the hardcoded "EMP-1042" sample. If no preview has
                        // been built yet, waRenderPreview() falls back to the
                        // hardcoded sample.
                        if (!empty($preview) && !empty($preview['recipients'])):
                            $pr = $preview['recipients'][0];
                            $prDob = $pr['date_of_birth'] ?? '';
                            $prDobFmt = ''; $prBirthYear = '';
                            if ($prDob) { try { $d = new DateTime($prDob); $prDobFmt = $d->format('d/m/Y'); $prBirthYear = $d->format('Y'); } catch(Exception $e) {} }
                            $prDoj = $pr['date_of_joining'] ?? ''; $prDojFmt = '';
                            if ($prDoj) { try { $prDojFmt = (new DateTime($prDoj))->format('d/m/Y'); } catch(Exception $e) {} }
                            $prGross = !empty($pr['gross_salary']) ? number_format((float)$pr['gross_salary'], 2) : '';
                        ?>
                        <script>
                        window.waPreviewSample = {
                            '{{name}}': <?php echo json_encode($pr['full_name'] ?? ''); ?>,
                            '{{Name}}': <?php echo json_encode($pr['full_name'] ?? ''); ?>,
                            '{{mobile}}': <?php echo json_encode($pr['mobile_number'] ?? ''); ?>,
                            '{{Mobile}}': <?php echo json_encode($pr['mobile_number'] ?? ''); ?>,
                            '{{dob}}': <?php echo json_encode($prDobFmt); ?>,
                            '{{DOB}}': <?php echo json_encode($prDobFmt); ?>,
                            '{{birthyear}}': <?php echo json_encode($prBirthYear); ?>,
                            '{{BirthYear}}': <?php echo json_encode($prBirthYear); ?>,
                            '{{email}}': <?php echo json_encode($pr['email'] ?? ''); ?>,
                            '{{Email}}': <?php echo json_encode($pr['email'] ?? ''); ?>,
                            '{{father_name}}': <?php echo json_encode($pr['father_name'] ?? ''); ?>,
                            '{{Father_Name}}': <?php echo json_encode($pr['father_name'] ?? ''); ?>,
                            '{{gender}}': <?php echo json_encode($pr['gender'] ?? ''); ?>,
                            '{{Gender}}': <?php echo json_encode($pr['gender'] ?? ''); ?>,
                            '{{blood_group}}': <?php echo json_encode($pr['blood_group'] ?? ''); ?>,
                            '{{Blood_Group}}': <?php echo json_encode($pr['blood_group'] ?? ''); ?>,
                            '{{marital_status}}': <?php echo json_encode($pr['marital_status'] ?? ''); ?>,
                            '{{Marital_Status}}': <?php echo json_encode($pr['marital_status'] ?? ''); ?>,
                            '{{doj}}': <?php echo json_encode($prDojFmt); ?>,
                            '{{DOJ}}': <?php echo json_encode($prDojFmt); ?>,
                            '{{unit}}': <?php echo json_encode($pr['unit_name'] ?? ''); ?>,
                            '{{Unit}}': <?php echo json_encode($pr['unit_name'] ?? ''); ?>,
                            '{{site}}': <?php echo json_encode($pr['site_name'] ?? $pr['unit_name'] ?? ''); ?>,
                            '{{Site}}': <?php echo json_encode($pr['site_name'] ?? $pr['unit_name'] ?? ''); ?>,
                            '{{client}}': <?php echo json_encode($pr['client_name'] ?? ''); ?>,
                            '{{Client}}': <?php echo json_encode($pr['client_name'] ?? ''); ?>,
                            '{{designation}}': <?php echo json_encode($pr['designation'] ?? ''); ?>,
                            '{{Designation}}': <?php echo json_encode($pr['designation'] ?? ''); ?>,
                            '{{department}}': <?php echo json_encode($pr['department'] ?? ''); ?>,
                            '{{Department}}': <?php echo json_encode($pr['department'] ?? ''); ?>,
                            '{{code}}': <?php echo json_encode($pr['employee_code'] ?? ''); ?>,
                            '{{Code}}': <?php echo json_encode($pr['employee_code'] ?? ''); ?>,
                            '{{employment_type}}': <?php echo json_encode($pr['employment_type'] ?? ''); ?>,
                            '{{Employment_Type}}': <?php echo json_encode($pr['employment_type'] ?? ''); ?>,
                            '{{worker_category}}': <?php echo json_encode($pr['worker_category'] ?? ''); ?>,
                            '{{Worker_Category}}': <?php echo json_encode($pr['worker_category'] ?? ''); ?>,
                            '{{emp_status}}': <?php echo json_encode($pr['emp_status'] ?? $pr['status'] ?? ''); ?>,
                            '{{Emp_Status}}': <?php echo json_encode($pr['emp_status'] ?? $pr['status'] ?? ''); ?>,
                            '{{uan}}': <?php echo json_encode($pr['uan_number'] ?? ''); ?>,
                            '{{UAN}}': <?php echo json_encode($pr['uan_number'] ?? ''); ?>,
                            '{{esic}}': <?php echo json_encode($pr['esic_number'] ?? ''); ?>,
                            '{{ESIC}}': <?php echo json_encode($pr['esic_number'] ?? ''); ?>,
                            '{{gross_salary}}': <?php echo json_encode($prGross); ?>,
                            '{{Gross_Salary}}': <?php echo json_encode($prGross); ?>,
                            '{{address}}': <?php echo json_encode($pr['address'] ?? ''); ?>,
                            '{{Address}}': <?php echo json_encode($pr['address'] ?? ''); ?>,
                            '{{pin_code}}': <?php echo json_encode($pr['pin_code'] ?? ''); ?>,
                            '{{Pin_Code}}': <?php echo json_encode($pr['pin_code'] ?? ''); ?>,
                            '{{state}}': <?php echo json_encode($pr['state'] ?? ''); ?>,
                            '{{State}}': <?php echo json_encode($pr['state'] ?? ''); ?>,
                            '{{district}}': <?php echo json_encode($pr['district'] ?? ''); ?>,
                            '{{District}}': <?php echo json_encode($pr['district'] ?? ''); ?>,
                            '{{bank_name}}': <?php echo json_encode($pr['bank_name'] ?? ''); ?>,
                            '{{Bank_Name}}': <?php echo json_encode($pr['bank_name'] ?? ''); ?>,
                            '{{ifsc_code}}': <?php echo json_encode($pr['ifsc_code'] ?? ''); ?>,
                            '{{IFSC_Code}}': <?php echo json_encode($pr['ifsc_code'] ?? ''); ?>,
                            '{{account_holder}}': <?php echo json_encode($pr['account_holder_name'] ?? ''); ?>,
                            '{{Account_Holder}}': <?php echo json_encode($pr['account_holder_name'] ?? ''); ?>,
                            '{{alt_mobile}}': <?php echo json_encode($pr['alternate_mobile'] ?? ''); ?>,
                            '{{Alt_Mobile}}': <?php echo json_encode($pr['alternate_mobile'] ?? ''); ?>,
                            '{{emergency_contact}}': <?php echo json_encode($pr['emergency_contact_name'] ?? ''); ?>,
                            '{{Emergency_Contact}}': <?php echo json_encode($pr['emergency_contact_name'] ?? ''); ?>,
                            '{{nominee_name}}': <?php echo json_encode($pr['nominee_name'] ?? ''); ?>,
                            '{{Nominee_Name}}': <?php echo json_encode($pr['nominee_name'] ?? ''); ?>
                        };
                        </script>
                        <?php endif; ?>
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
                <div class="card-body p-2">
                    <div style="max-height:450px;overflow-y:auto;">
                    <table class="table table-sm mb-0 small">
                        <thead class="sticky-top"><tr><th>Tag</th><th>Replaced With</th></tr></thead>
                        <tbody>
                            <tr><td><code>{{name}}</code></td><td>Full Name</td></tr>
                            <tr class="table-success"><td><code>{{mobile}}</code></td><td>Mobile No.</td></tr>
                            <tr class="table-success"><td><code>{{dob}}</code></td><td>Date of Birth (DD/MM/YYYY)</td></tr>
                            <tr class="table-success"><td><code>{{birthyear}}</code></td><td>Birth Year (YYYY)</td></tr>
                            <tr><td><code>{{email}}</code></td><td>Email Address</td></tr>
                            <tr><td><code>{{father_name}}</code></td><td>Father's Name</td></tr>
                            <tr class="table-success"><td><code>{{gender}}</code></td><td>Gender</td></tr>
                            <tr class="table-success"><td><code>{{blood_group}}</code></td><td>Blood Group</td></tr>
                            <tr><td><code>{{marital_status}}</code></td><td>Marital Status</td></tr>
                            <tr class="table-success"><td><code>{{doj}}</code></td><td>Date of Joining (DD/MM/YYYY)</td></tr>
                            <tr><td><code>{{unit}}</code></td><td>Unit Name</td></tr>
                            <tr class="table-success"><td><code>{{site}}</code></td><td>Site Address</td></tr>
                            <tr><td><code>{{client}}</code></td><td>Client Name</td></tr>
                            <tr class="table-success"><td><code>{{designation}}</code></td><td>Job Title</td></tr>
                            <tr><td><code>{{department}}</code></td><td>Department</td></tr>
                            <tr class="table-success"><td><code>{{code}}</code></td><td>Emp Code / Member ID</td></tr>
                            <tr><td><code>{{employment_type}}</code></td><td>Employment Type</td></tr>
                            <tr class="table-success"><td><code>{{worker_category}}</code></td><td>Worker Category</td></tr>
                            <tr><td><code>{{emp_status}}</code></td><td>Employee Status</td></tr>
                            <tr class="table-success"><td><code>{{uan}}</code></td><td>UAN Number</td></tr>
                            <tr><td><code>{{esic}}</code></td><td>ESIC Number</td></tr>
                            <tr class="table-success"><td><code>{{gross_salary}}</code></td><td>Gross Salary</td></tr>
                            <tr><td><code>{{address}}</code></td><td>Full Address</td></tr>
                            <tr class="table-success"><td><code>{{pin_code}}</code></td><td>PIN Code</td></tr>
                            <tr><td><code>{{state}}</code></td><td>State</td></tr>
                            <tr class="table-success"><td><code>{{district}}</code></td><td>District</td></tr>
                            <tr><td><code>{{bank_name}}</code></td><td>Bank Name</td></tr>
                            <tr class="table-success"><td><code>{{ifsc_code}}</code></td><td>IFSC Code</td></tr>
                            <tr><td><code>{{account_holder}}</code></td><td>Account Holder Name</td></tr>
                            <tr class="table-success"><td><code>{{alt_mobile}}</code></td><td>Alternate Mobile</td></tr>
                            <tr><td><code>{{emergency_contact}}</code></td><td>Emergency Contact Name</td></tr>
                            <tr class="table-success"><td><code>{{nominee_name}}</code></td><td>Nominee Name</td></tr>
                        </tbody>
                    </table>
                    </div>
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
                        <li>DOB &amp; DOJ formatted as <strong>DD/MM/YYYY</strong></li>
                        <li>Gross Salary fetched from salary structure</li>
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
            <?php echo getCSRFTokenField(); ?>
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
            <?php echo getCSRFTokenField(); ?>
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
                        $fBirthYear = '';
                        if ($fDob) {
                            try {
                                $fDobDate = new DateTime($fDob);
                                $fDobFmt = $fDobDate->format('d/m/Y');
                                $fBirthYear = $fDobDate->format('Y');
                            } catch(Exception $e) { $fDobFmt = $fDob; }
                        }
                        $fDoj = $first['date_of_joining'] ?? '';
                        $fDojFmt = '';
                        if ($fDoj) { try { $fDojFmt = (new DateTime($fDoj))->format('d/m/Y'); } catch(Exception $e) { $fDojFmt = $fDoj; } }
                        $fGross = $first['gross_salary'] ? number_format((float)$first['gross_salary'], 2) : '[Gross Salary]';
                        echo nl2br(sanitize(str_replace(
                            ['{{name}}','{{mobile}}','{{dob}}','{{birthyear}}','{{unit}}','{{site}}','{{client}}','{{designation}}','{{department}}','{{code}}',
                             '{{email}}','{{father_name}}','{{gender}}','{{blood_group}}','{{marital_status}}','{{doj}}',
                             '{{employment_type}}','{{worker_category}}','{{emp_status}}',
                             '{{uan}}','{{esic}}','{{gross_salary}}',
                             '{{address}}','{{pin_code}}','{{state}}','{{district}}',
                             '{{bank_name}}','{{ifsc_code}}','{{account_holder}}',
                             '{{alt_mobile}}','{{emergency_contact}}','{{nominee_name}}',
                             '{{Name}}','{{Mobile}}','{{DOB}}','{{BirthYear}}','{{Unit}}','{{Site}}','{{Client}}','{{Designation}}','{{Department}}','{{Code}}',
                             '{{Email}}','{{Father_Name}}','{{Gender}}','{{Blood_Group}}','{{Marital_Status}}','{{DOJ}}',
                             '{{Employment_Type}}','{{Worker_Category}}','{{Emp_Status}}',
                             '{{UAN}}','{{ESIC}}','{{Gross_Salary}}',
                             '{{Address}}','{{Pin_Code}}','{{State}}','{{District}}',
                             '{{Bank_Name}}','{{IFSC_Code}}','{{Account_Holder}}',
                             '{{Alt_Mobile}}','{{Emergency_Contact}}','{{Nominee_Name}}'],
                            [$first['full_name'] ?? '[Name]',$first['mobile_number'] ?? '[Mobile]',$fDobFmt,$fBirthYear,
                             $first['unit_name'] ?? '[Unit]',$first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',$first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',$first['employee_code'] ?? '[Code]',
                             $first['email'] ?? '[Email]',$first['father_name'] ?? '[Father Name]',
                             $first['gender'] ?? '[Gender]',$first['blood_group'] ?? '[Blood Group]',
                             $first['marital_status'] ?? '[Marital Status]',$fDojFmt,
                             $first['employment_type'] ?? '[Employment Type]',$first['worker_category'] ?? '[Worker Category]',
                             $first['emp_status'] ?? '[Status]',
                             $first['uan_number'] ?? '[UAN]',$first['esic_number'] ?? '[ESIC]',$fGross,
                             $first['address'] ?? '[Address]',$first['pin_code'] ?? '[PIN]',$first['state'] ?? '[State]',
                             $first['district'] ?? '[District]',
                             $first['bank_name'] ?? '[Bank]',$first['ifsc_code'] ?? '[IFSC]',$first['account_holder_name'] ?? '[Account Holder]',
                             $first['alternate_mobile'] ?? '[Alt Mobile]',$first['emergency_contact_name'] ?? '[Emergency Contact]',
                             $first['nominee_name'] ?? '[Nominee]',
                             $first['full_name'] ?? '[Name]',$first['mobile_number'] ?? '[Mobile]',$fDobFmt,$fBirthYear,
                             $first['unit_name'] ?? '[Unit]',$first['site_name'] ?? $first['unit_name'] ?? '[Site]',
                             $first['client_name'] ?? '[Client]',$first['designation'] ?? '[Designation]',
                             $first['department'] ?? '[Department]',$first['employee_code'] ?? '[Code]',
                             $first['email'] ?? '[Email]',$first['father_name'] ?? '[Father Name]',
                             $first['gender'] ?? '[Gender]',$first['blood_group'] ?? '[Blood Group]',
                             $first['marital_status'] ?? '[Marital Status]',$fDojFmt,
                             $first['employment_type'] ?? '[Employment Type]',$first['worker_category'] ?? '[Worker Category]',
                             $first['emp_status'] ?? '[Status]',
                             $first['uan_number'] ?? '[UAN]',$first['esic_number'] ?? '[ESIC]',$fGross,
                             $first['address'] ?? '[Address]',$first['pin_code'] ?? '[PIN]',$first['state'] ?? '[State]',
                             $first['district'] ?? '[District]',
                             $first['bank_name'] ?? '[Bank]',$first['ifsc_code'] ?? '[IFSC]',$first['account_holder_name'] ?? '[Account Holder]',
                             $first['alternate_mobile'] ?? '[Alt Mobile]',$first['emergency_contact_name'] ?? '[Emergency Contact]',
                             $first['nominee_name'] ?? '[Nominee]'],
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
// If a preview has been built (recipients exist in session), use the FIRST
// real recipient's data so the preview reflects an actual message. Otherwise
// fall back to the hardcoded sample. This fixes the bug where the preview
// always showed the same fake employee (EMP-1042) regardless of who was
// actually selected.
function waRenderPreview() {
    const body = document.getElementById('waMessage');
    if (!body) return;

    // Real recipient data is injected by PHP below (if a preview exists)
    const realSample = window.waPreviewSample || null;

    const fallback = {
        '{{name}}': 'Rajesh Kumar', '{{Name}}': 'Rajesh Kumar',
        '{{mobile}}': '9876543210', '{{Mobile}}': '9876543210',
        '{{dob}}': '15/08/1990', '{{DOB}}': '15/08/1990',
        '{{birthyear}}': '1990', '{{BirthYear}}': '1990',
        '{{email}}': 'rajesh.kumar@email.com', '{{Email}}': 'rajesh.kumar@email.com',
        '{{father_name}}': 'Sh. Ram Kumar', '{{Father_Name}}': 'Sh. Ram Kumar',
        '{{gender}}': 'Male', '{{Gender}}': 'Male',
        '{{blood_group}}': 'B+', '{{Blood_Group}}': 'B+',
        '{{marital_status}}': 'Married', '{{Marital_Status}}': 'Married',
        '{{doj}}': '01/03/2022', '{{DOJ}}': '01/03/2022',
        '{{unit}}': 'Unit A - Main Plant', '{{Unit}}': 'Unit A - Main Plant',
        '{{site}}': 'Industrial Area, MIDC', '{{Site}}': 'Industrial Area, MIDC',
        '{{client}}': 'ABC Manufacturing Ltd', '{{Client}}': 'ABC Manufacturing Ltd',
        '{{designation}}': 'Supervisor', '{{Designation}}': 'Supervisor',
        '{{department}}': 'Production', '{{Department}}': 'Production',
        '{{code}}': 'EMP-1042', '{{Code}}': 'EMP-1042',
        '{{employment_type}}': 'Permanent', '{{Employment_Type}}': 'Permanent',
        '{{worker_category}}': 'Skilled', '{{Worker_Category}}': 'Skilled',
        '{{emp_status}}': 'Approved', '{{Emp_Status}}': 'Approved',
        '{{uan}}': '101234567890', '{{UAN}}': '101234567890',
        '{{esic}}': '210012345678901', '{{ESIC}}': '210012345678901',
        '{{gross_salary}}': '18,500.00', '{{Gross_Salary}}': '18,500.00',
        '{{address}}': 'Flat 301, Sector 15, CBD Belapur', '{{Address}}': 'Flat 301, Sector 15, CBD Belapur',
        '{{pin_code}}': '400614', '{{Pin_Code}}': '400614',
        '{{state}}': 'Maharashtra', '{{State}}': 'Maharashtra',
        '{{district}}': 'Thane', '{{District}}': 'Thane',
        '{{bank_name}}': 'State Bank of India', '{{Bank_Name}}': 'State Bank of India',
        '{{ifsc_code}}': 'SBIN0001234', '{{IFSC_Code}}': 'SBIN0001234',
        '{{account_holder}}': 'Rajesh Kumar', '{{Account_Holder}}': 'Rajesh Kumar',
        '{{alt_mobile}}': '9898989898', '{{Alt_Mobile}}': '9898989898',
        '{{emergency_contact}}': 'Suresh Kumar', '{{Emergency_Contact}}': 'Suresh Kumar',
        '{{nominee_name}}': 'Meena Kumari', '{{Nominee_Name}}': 'Meena Kumari'
    };

    const sample = realSample || fallback;
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

<?php
// Counts for the action buttons (queued + retry_wait both retryable;
// failed can be re-queued with the retry-failed admin action;
// cancelled can be re-queued with the retry-cancelled admin action).
$queuedCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'queued'");
$retryWaitCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'retry_wait'");
$failedCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'failed'");
$cancelledCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'cancelled' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$retryableCount = $queuedCount + $retryWaitCount;

// Active campaigns (for the Cancel Campaign dropdown) — campaigns with
// at least one row still in a non-final status.
$activeCampaigns = $db->fetchAll(
    "SELECT campaign_id, COUNT(*) AS pending, MIN(created_at) AS first_at
     FROM whatsapp_logs
     WHERE campaign_id IS NOT NULL
       AND status IN ('queued','sending','retry_wait')
     GROUP BY campaign_id
     ORDER BY first_at DESC
     LIMIT 20"
);

// Handle Cancel Campaign action (bulk-safe redesign — operator can stop a
// campaign's waiting rows without touching already-sent ones).
// Note: $tab is already constrained to 'history' by the elseif above, so
// we don't re-check it here (PHPStan flags the redundant check as always-true).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_campaign') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect('index.php?page=notifications/whatsapp&tab=history');
    }
    $cancelCampaignId = trim($_POST['campaign_id'] ?? '');
    if ($cancelCampaignId !== '') {
        $cancelled = waQueueCancelCampaign($cancelCampaignId);
        setFlash($cancelled > 0 ? 'success' : 'info',
            $cancelled > 0
                ? "Cancelled {$cancelled} pending message(s) in campaign {$cancelCampaignId}."
                : "No pending messages found for campaign {$cancelCampaignId}."
        );
    } else {
        setFlash('error', 'Campaign ID is required.');
    }
    redirect('index.php?page=notifications/whatsapp&tab=history');
}

// Handle Retry Failed action (re-queues permanently failed rows).
// Same note: $tab is already 'history' here — no need to re-check.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_failed') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect('index.php?page=notifications/whatsapp&tab=history');
    }
    $requeued = waQueueRetryFailed();
    setFlash($requeued > 0 ? 'success' : 'info',
        $requeued > 0
            ? "Re-queued {$requeued} failed message(s) for delivery."
            : 'No failed messages to re-queue.'
    );
    redirect('index.php?page=notifications/whatsapp&tab=history');
}

// Handle Retry Cancelled action (re-queues rows cancelled via Purge Queue
// or Cancel Campaign, so they can be sent after the bot is healthy again).
// Only re-queues cancelled rows from the last 7 days (avoids ancient rows).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_cancelled') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect('index.php?page=notifications/whatsapp&tab=history');
    }
    $requeued = waQueueRetryCancelled();
    setFlash($requeued > 0 ? 'success' : 'info',
        $requeued > 0
            ? "Re-queued {$requeued} cancelled message(s) for delivery. They will be sent gradually by the bot's worker."
            : 'No cancelled messages to re-queue (only rows from the last 7 days are eligible).'
    );
    redirect('index.php?page=notifications/whatsapp&tab=history');
}
?>

<!-- Live queue status mini-panel (same shape as Bulk tab, condensed) -->
<div class="card mb-3 border-info">
    <div class="card-body py-2">
        <div class="row g-2 small text-center">
            <div class="col">
                <div class="text-muted">Queued</div>
                <strong class="text-warning fs-5"><?php echo number_format($queuedCount); ?></strong>
            </div>
            <div class="col border-start">
                <div class="text-muted">Retry Wait</div>
                <strong class="text-secondary fs-5"><?php echo number_format($retryWaitCount); ?></strong>
            </div>
            <div class="col border-start">
                <div class="text-muted">Failed</div>
                <strong class="text-danger fs-5"><?php echo number_format($failedCount); ?></strong>
            </div>
            <div class="col border-start">
                <div class="text-muted">Cancelled</div>
                <strong class="text-dark fs-5"><?php echo number_format($cancelledCount); ?></strong>
            </div>
            <div class="col border-start">
                <div class="text-muted">Sent Today</div>
                <strong class="text-success fs-5"><?php echo number_format((int)($queueStats['sent_today'] ?? 0)); ?></strong>
            </div>
            <div class="col border-start">
                <div class="text-muted">Day Limit</div>
                <strong class="fs-5"><?php echo number_format((int)($queueStats['day_limit'] ?? 200)); ?></strong>
            </div>
            <?php if (!empty($queueStats['day_limit_hit'])): ?>
            <div class="col border-start align-self-center">
                <span class="badge bg-warning text-dark">Day limit reached — queue resumes tomorrow</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Send History</h5>
                <small class="text-muted"><?php echo number_format($history['pagination']['total']); ?> total messages</small>
            </div>
            <div class="col-auto d-flex gap-2 align-items-center">
                <!-- Cancel Campaign dropdown -->
                <?php if (!empty($activeCampaigns)): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-x-circle me-1"></i>Cancel Campaign
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($activeCampaigns as $ac): ?>
                        <li>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Cancel <?php echo (int)$ac['pending']; ?> pending message(s) in campaign <?php echo htmlspecialchars($ac['campaign_id']); ?>? Already-sent messages are not affected.');">
                                <?php echo getCSRFTokenField(); ?>
                                <input type="hidden" name="tab" value="history">
                                <input type="hidden" name="action" value="cancel_campaign">
                                <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($ac['campaign_id']); ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                    <code><?php echo htmlspecialchars($ac['campaign_id']); ?></code>
                                    <span class="badge bg-warning text-dark ms-2"><?php echo (int)$ac['pending']; ?> pending</span>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Retry Failed (re-queues permanently failed rows) -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Re-queue all <?php echo (int)$failedCount; ?> permanently failed message(s) for delivery?');">
                    <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="tab" value="history">
                    <input type="hidden" name="action" value="retry_failed">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" <?php echo $failedCount > 0 ? '' : 'disabled'; ?>>
                        <i class="bi bi-arrow-repeat me-1"></i>Retry Failed
                        <?php if ($failedCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $failedCount; ?></span>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- Retry Cancelled (re-queues rows cancelled via Purge Queue
                     or Cancel Campaign, so they can be sent after the bot is
                     healthy again. Only affects cancelled rows from the last
                     7 days.) -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Re-queue all <?php echo (int)$cancelledCount; ?> cancelled message(s) for delivery?\n\nThey will be sent gradually by the bot at the configured pace (20-45s apart, batch of 25).');">
                    <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="tab" value="history">
                    <input type="hidden" name="action" value="retry_cancelled">
                    <button type="submit" class="btn btn-sm btn-outline-dark" <?php echo $cancelledCount > 0 ? '' : 'disabled'; ?>>
                        <i class="bi bi-arrow-repeat me-1"></i>Retry Cancelled
                        <?php if ($cancelledCount > 0): ?>
                        <span class="badge bg-dark ms-1"><?php echo $cancelledCount; ?></span>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- Retry Queued (re-queues stuck queued/retry_wait rows) -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Re-send all <?php echo (int)$retryableCount; ?> queued/retry-wait message(s)?');">
                    <?php echo getCSRFTokenField(); ?>
                    <input type="hidden" name="tab" value="history">
                    <input type="hidden" name="action" value="retry_queued">
                    <button type="submit" class="btn btn-sm btn-warning" <?php echo $retryableCount > 0 ? '' : 'disabled'; ?>>
                        <i class="bi bi-arrow-repeat me-1"></i>Retry Queued
                        <?php if ($retryableCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $retryableCount; ?></span>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- Resume Queue — clears drain-cap pause (when the worker
                     stops after N sends on one connection) or admin pause.
                     Calls the bot's /api/queue/resume endpoint via a PHP proxy.
                     Always enabled — clicking when nothing is paused is harmless. -->
                <form method="POST" action="index.php?page=api/whatsapp-queue-resume" style="display:inline;"
                      onsubmit="return confirm('Resume the WhatsApp queue? This clears any drain-cap or admin pause. The bot will start sending queued messages again.');">
                    <?php echo getCSRFTokenField(); ?>
                    <button type="submit" class="btn btn-sm btn-success"
                            title="Resume the queue after a drain-cap pause or admin pause">
                        <i class="bi bi-play-circle me-1"></i>Resume Queue
                    </button>
                </form>

                <!-- Purge All Queued — cancels every pending message (queued/
                     sending/retry_wait). Used BEFORE re-linking a blocked
                     WhatsApp account so the fresh session doesn't immediately
                     blast 100+ messages and re-trigger the block. Already-sent
                     and permanently-failed rows are NOT touched. -->
                <form method="POST" action="index.php?page=api/whatsapp-queue-purge" style="display:inline;"
                      onsubmit="return confirm('PURGE ALL QUEUED MESSAGES?\n\nThis cancels every pending message (queued / sending / retry_wait) and marks them as cancelled. Already-sent messages are not affected.\n\nUse this BEFORE re-linking a blocked WhatsApp account so the fresh session does not immediately send a burst that re-triggers the block.\n\nContinue?');">
                    <?php echo getCSRFTokenField(); ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            title="Cancel all pending messages (use before re-linking after a block)">
                        <i class="bi bi-trash me-1"></i>Purge Queue
                        <?php if ($retryableCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $retryableCount; ?></span>
                        <?php endif; ?>
                    </button>
                </form>
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
                    <option value="queued" <?php echo $historyStatus === 'queued' ? 'selected' : ''; ?>>Queued</option>
                    <option value="sending" <?php echo $historyStatus === 'sending' ? 'selected' : ''; ?>>Sending</option>
                    <option value="sent" <?php echo $historyStatus === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="retry_wait" <?php echo $historyStatus === 'retry_wait' ? 'selected' : ''; ?>>Retry Wait</option>
                    <option value="failed" <?php echo $historyStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    <option value="cancelled" <?php echo $historyStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="paused" <?php echo $historyStatus === 'paused' ? 'selected' : ''; ?>>Paused</option>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="search"
                       value="<?php echo sanitize($historySearch); ?>"
                       placeholder="Search mobile, message, campaign ID, or name...">
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

        <!-- Quick filters — one-click access to the most common views -->
        <div class="d-flex gap-2 mb-3 flex-wrap">
            <a href="?page=notifications/whatsapp&tab=history&status=sent"
               class="btn btn-sm <?php echo $historyStatus === 'sent' ? 'btn-success' : 'btn-outline-success'; ?>">
                <i class="bi bi-check-circle me-1"></i>Sent
                <span class="badge bg-light text-dark ms-1">
                    <?php echo (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'sent' AND DATE(sent_at) = CURDATE()"); ?>
                </span>
            </a>
            <a href="?page=notifications/whatsapp&tab=history&status=failed"
               class="btn btn-sm <?php echo $historyStatus === 'failed' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                <i class="bi bi-x-circle me-1"></i>Failed
                <span class="badge bg-light text-dark ms-1">
                    <?php echo (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'failed'"); ?>
                </span>
            </a>
            <a href="?page=notifications/whatsapp&tab=history&status=cancelled"
               class="btn btn-sm <?php echo $historyStatus === 'cancelled' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                <i class="bi bi-slash-circle me-1"></i>Cancelled
                <span class="badge bg-light text-dark ms-1">
                    <?php echo (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'cancelled'"); ?>
                </span>
            </a>
            <a href="?page=notifications/whatsapp&tab=history&status=queued"
               class="btn btn-sm <?php echo $historyStatus === 'queued' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                <i class="bi bi-hourglass-split me-1"></i>Queued
                <span class="badge bg-light text-dark ms-1">
                    <?php echo (int)$db->fetchColumn("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'queued'"); ?>
                </span>
            </a>
            <a href="?page=notifications/whatsapp&tab=history"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list me-1"></i>All
            </a>
        </div>

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
                        <th>Attempts</th>
                        <th>Campaign</th>
                        <th>Error</th>
                        <th>Created</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history['items'])): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No messages found</td></tr>
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
                            <div style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                 title="<?php echo htmlspecialchars($log['message']); ?>">
                                <?php echo sanitize(mb_substr($log['message'], 0, 60)); ?>
                                <?php if (mb_strlen($log['message']) > 60) echo '...'; ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            // Status badges with icons — clearer than colour-only.
                            $statusMap = [
                                'sent'         => ['success',  'check-circle',        'Sent'],
                                'queued'       => ['warning',  'hourglass-split',     'Queued'],
                                'sending'       => ['primary',  'arrow-repeat',        'Sending'],
                                'retry_wait'   => ['secondary', 'clock-history',       'Retry Wait'],
                                'failed'       => ['danger',   'x-circle',            'Failed'],
                                'cancelled'    => ['dark',     'slash-circle',        'Cancelled'],
                                'paused'       => ['light',    'pause-circle',        'Paused'],
                                'link_generated'=> ['info',    'link-45deg',          'Link'],
                            ];
                            $sb = $statusMap[$log['status']] ?? ['secondary', 'question-circle', $log['status']];
                            ?>
                            <span class="badge bg-<?php echo $sb[0]; ?>">
                                <i class="bi bi-<?php echo $sb[1]; ?> me-1"></i><?php echo $sb[2]; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php
                            $att = (int)($log['attempts'] ?? 0);
                            $maxAtt = (int)($log['max_attempts'] ?? 4);
                            // Highlight attempts > 0 so operators can see retried rows at a glance
                            $attClass = $att === 0 ? 'text-muted' : ($att >= $maxAtt ? 'text-danger fw-bold' : 'text-warning');
                            ?>
                            <span class="<?php echo $attClass; ?>"><?php echo $att; ?>/<?php echo $maxAtt; ?></span>
                        </td>
                        <td>
                            <?php if (!empty($log['campaign_id'])): ?>
                            <code class="small" title="<?php echo htmlspecialchars($log['campaign_id']); ?>">
                                <?php echo sanitize(mb_substr($log['campaign_id'], 0, 12)); ?><?php if (mb_strlen($log['campaign_id']) > 12) echo '...'; ?>
                            </code>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($log['error'])): ?>
                            <small class="text-danger" title="<?php echo htmlspecialchars($log['error']); ?>">
                                <?php echo sanitize(mb_substr($log['error'], 0, 30)); ?><?php if (mb_strlen($log['error']) > 30) echo '...'; ?>
                            </small>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?php echo date('d M H:i', strtotime($log['created_at'])); ?></small></td>
                        <td>
                            <?php if (!empty($log['sent_at'])): ?>
                            <small class="text-success"><?php echo date('d M H:i', strtotime($log['sent_at'])); ?></small>
                            <?php elseif (!empty($log['available_at']) && in_array($log['status'], ['queued','retry_wait'], true)): ?>
                            <small class="text-warning" title="Next attempt scheduled">
                                <?php echo date('d M H:i', strtotime($log['available_at'])); ?>
                            </small>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
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
