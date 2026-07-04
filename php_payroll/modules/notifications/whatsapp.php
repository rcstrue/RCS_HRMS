<?php
/**
 * RCS HRMS Pro - WhatsApp Messaging Module
 * Manual WhatsApp messaging: Single, Bulk, Send History
 *
 * Route: index.php?page=notifications/whatsapp
 */

require_once __DIR__ . '/../includes/whatsapp.php';

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

// Handle bulk send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'bulk') && ($_POST['action'] ?? '') === 'send_bulk') {
    $message = $_POST['message'] ?? '';
    $sourceType = sanitize($_POST['source_type'] ?? '');

    if (empty($message)) {
        setFlash('error', 'Message is required.');
        redirect('index.php?page=notifications/whatsapp&tab=bulk');
    }

    $recipients = [];

    if ($sourceType === 'manual') {
        // Comma/newline separated numbers
        $numbers = sanitize($_POST['manual_numbers'] ?? '');
        $parts = preg_split('/[\s,;\n]+/', $numbers);
        foreach ($parts as $num) {
            $num = trim($num);
            if (strlen($num) >= 10) {
                $recipients[] = ['mobile' => $num];
            }
        }
    } elseif ($sourceType === 'unit') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        if ($unitId > 0) {
            $rows = $db->fetchAll(
                "SELECT id AS employee_id, mobile_number AS mobile, full_name
                 FROM employees WHERE unit_id = ? AND status IN ('approved','active') AND mobile_number IS NOT NULL AND mobile_number != ''",
                [$unitId]
            );
            $recipients = $rows;
        }
    } elseif ($sourceType === 'client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId > 0) {
            $rows = $db->fetchAll(
                "SELECT id AS employee_id, mobile_number AS mobile, full_name
                 FROM employees WHERE client_id = ? AND status IN ('approved','active') AND mobile_number IS NOT NULL AND mobile_number != ''",
                [$clientId]
            );
            $recipients = $rows;
        }
    } elseif ($sourceType === 'search') {
        $search = sanitize($_POST['search_query'] ?? '');
        if (!empty($search)) {
            $rows = $db->fetchAll(
                "SELECT id AS employee_id, mobile_number AS mobile, full_name
                 FROM employees
                 WHERE (full_name LIKE ? OR employee_code LIKE ? OR mobile_number LIKE ?)
                 AND status IN ('approved','active') AND mobile_number IS NOT NULL AND mobile_number != ''",
                ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%']
            );
            $recipients = $rows;
        }
    }

    if (empty($recipients)) {
        setFlash('error', 'No recipients found.');
    } else {
        $result = waSendBulk($recipients, $message);
        $summary = "Sent: {$result['sent']}, Failed: {$result['failed']}, Queued: {$result['queued']}";
        setFlash($result['success'] ? 'success' : 'error', $result['message'] . ' (' . $summary . ')');
    }
    redirect('index.php?page=notifications/whatsapp&tab=bulk');
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
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title"><i class="bi bi-whatsapp me-2 text-success"></i>WhatsApp Messaging</h1>
            <p class="text-muted">Send WhatsApp messages to employees</p>
        </div>
        <div class="col-auto">
            <span class="badge bg-<?php echo $waBot['connected'] ? 'success' : 'secondary'; ?> fs-6 px-3 py-2">
                <?php echo $waBot['connected'] ? '🟢 Bot Connected' : '🔴 Bot Offline'; ?>
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
                <div class="fs-4 fw-bold text-success"><?php echo number_format($waStats['sent']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Today</div>
                <div class="fs-4 fw-bold text-primary"><?php echo number_format($waStats['today']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Queued</div>
                <div class="fs-4 fw-bold text-warning"><?php echo number_format($waStats['queued']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div class="text-muted small">Failed</div>
                <div class="fs-4 fw-bold text-danger"><?php echo number_format($waStats['failed']); ?></div>
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
                            <span class="input-group-text">🇮🇳 +91</span>
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
// Employee search autocomplete
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
                        <strong>${e.employee_code}</strong> — ${e.full_name}
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
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Bulk Send</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="send_bulk">

                    <!-- Source selection -->
                    <div class="mb-3">
                        <label class="form-label">Send To <span class="text-danger">*</span></label>
                        <select class="form-select" name="source_type" id="bulk_source" onchange="toggleBulkSource()">
                            <option value="manual">Manual Numbers</option>
                            <option value="unit">Entire Unit</option>
                            <option value="client">Entire Client</option>
                            <option value="search">Search Employees</option>
                        </select>
                    </div>

                    <!-- Manual numbers -->
                    <div class="mb-3" id="bulk_manual">
                        <label class="form-label">Mobile Numbers</label>
                        <textarea class="form-control" name="manual_numbers" rows="4"
                                  placeholder="Enter mobile numbers separated by comma, space, or new line&#10;Example: 9824009110, 9876543210"></textarea>
                        <small class="text-muted">10-digit numbers. Add 91 prefix automatically.</small>
                    </div>

                    <!-- Unit select -->
                    <div class="mb-3" id="bulk_unit" style="display:none;">
                        <label class="form-label">Select Unit</label>
                        <select class="form-select" name="unit_id">
                            <option value="">-- Select Unit --</option>
                            <?php
                            $clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
                            foreach ($clients as $c):
                            ?>
                            <optgroup label="<?php echo sanitize($c['name']); ?>">
                                <?php
                                $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$c['id']]);
                                foreach ($units as $u):
                                ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo sanitize($u['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Client select -->
                    <div class="mb-3" id="bulk_client" style="display:none;">
                        <label class="form-label">Select Client</label>
                        <select class="form-select" name="client_id">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Employee search -->
                    <div class="mb-3" id="bulk_search" style="display:none;">
                        <label class="form-label">Search Employees</label>
                        <input type="text" class="form-control" name="search_query"
                               placeholder="Type name, code, or mobile...">
                    </div>

                    <!-- Recipient count preview -->
                    <div class="mb-3" id="bulk_preview" style="display:none;">
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-people me-1"></i>
                            <span id="bulk_recipient_count">0</span> recipients will receive this message.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="message" rows="5" required
                                  placeholder="Type your message here..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg"
                            onclick="return confirm('Send this message to all selected recipients?')">
                        <i class="bi bi-whatsapp me-2"></i>Send Bulk Message
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleBulkSource() {
    const type = document.getElementById('bulk_source').value;
    ['manual', 'unit', 'client', 'search'].forEach(t => {
        const el = document.getElementById('bulk_' + t);
        if (el) el.style.display = (t === type) ? 'block' : 'none';
    });
}
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
                            <span class="text-muted">—</span>
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
                            $statusBadge = [
                                'sent'           => 'success',
                                'queued'         => 'warning',
                                'failed'         => 'danger',
                                'link_generated' => 'info',
                            ];
                            $statusLabel = [
                                'sent'           => 'Sent',
                                'queued'         => 'Queued',
                                'failed'         => 'Failed',
                                'link_generated' => 'Link',
                            ];
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
                            <span class="text-muted">—</span>
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
                if ($endPage - $startPage < $maxVisible - 1) {
                    $startPage = max(1, $endPage - $maxVisible + 1);
                }

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