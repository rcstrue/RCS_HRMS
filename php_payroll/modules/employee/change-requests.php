<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }

/**
 * RCS HRMS Pro — Employee Change Requests Approval Page
 *
 * Allows HR/Admin to view, approve, or reject change requests
 * submitted by employees via the ESS mobile app.
 *
 * Approve: updates the employee record with the new value.
 * Reject: stores rejection reason; employee sees it in ESS.
 * Both actions send email + WhatsApp notification to the employee.
 */

$pageTitle = 'Change Request Approvals';

// ─── Ensure table exists ──────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS employee_change_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        field_name VARCHAR(100) NOT NULL,
        old_value TEXT,
        new_value TEXT NOT NULL,
        reason TEXT,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        reviewed_by INT NULL,
        rejection_reason TEXT NULL,
        INDEX idx_employee_status (employee_id, status),
        INDEX idx_field_pending (employee_id, field_name, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('[change-requests] Table create error: ' . $e->getMessage());
}

// ─── POST Actions ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php?page=employee/change-requests');
    }
    $action = sanitize($_POST['action']);

    // ── Whitelist of fields that can be updated on approval ──
    $APPROVAL_FIELDS = [
        'full_name', 'father_name', 'date_of_birth', 'gender',
        'designation', 'department', 'profile_pic_url',
    ];

    // ── 1. Approve ─────────────────────────────────────────────
    if ($action === 'approve') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            // Fetch request WITHOUT JOIN, then get employee separately
            $request = $db->fetch(
                "SELECT * FROM employee_change_requests
                 WHERE id = :id AND status = 'pending' LIMIT 1",
                ['id' => $id]
            );

            if ($request) {
                // Fetch employee info separately
                $empInfo = $db->fetch(
                    "SELECT full_name, email, mobile_number, employee_code, designation
                     FROM employees WHERE id = :eid LIMIT 1",
                    ['eid' => (int)$request['employee_id']]
                );
                if ($empInfo) {
                    $request['emp_name']      = $empInfo['full_name'];
                    $request['email']         = $empInfo['email'];
                    $request['mobile_number'] = $empInfo['mobile_number'];
                    $request['employee_code'] = $empInfo['employee_code'];
                    $request['designation']   = $empInfo['designation'];
                } else {
                    $request['emp_name'] = 'Employee #' . $request['employee_id'];
                }
                $field = $request['field_name'];
                $newValue = $request['new_value'];

                // Apply the change to employee record if it's a whitelisted field
                if (in_array($field, $APPROVAL_FIELDS, true)) {
                    try {
                        $db->update(
                            'employees',
                            [$field => $newValue],
                            'id = :id',
                            ['id' => $request['employee_id']]
                        );
                    } catch (Exception $e) {
                        setFlash('error', 'Failed to update employee record: ' . $e->getMessage());
                        redirect('index.php?page=employee/change-requests');
                    }
                }

                // Mark request as approved
                $db->update(
                    'employee_change_requests',
                    [
                        'status'      => 'approved',
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'reviewed_by' => $_SESSION['user_id'] ?? null,
                    ],
                    'id = :id',
                    ['id' => $id]
                );

                // ── Notification to employee ──
                try {
                    require_once __DIR__ . '/../../includes/class.notification.php';
                    $notif = new Notification();

                    // Email
                    $empEmail = $request['email'];
                    if (!empty($empEmail)) {
                        $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                        $htmlBody = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
                            <div style='background:#10b981;padding:20px;text-align:center;'>
                                <h2 style='color:#fff;margin:0;'>RCS True Facilities Pvt Ltd</h2>
                            </div>
                            <div style='padding:25px;background:#f9fafb;border:1px solid #e5e7eb;'>
                                <p>Dear {$request['emp_name']},</p>
                                <p>Your change request has been <strong style='color:#10b981;'>APPROVED</strong>.</p>
                                <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Field</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>{$fieldLabel}</td></tr>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Old Value</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($request['old_value']) . "</td></tr>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>New Value</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($newValue) . "</td></tr>
                                </table>
                                <p>If you did not request this change, please contact HR immediately.</p>
                            </div>
                            <p style='text-align:center;color:#9ca3af;font-size:12px;'>This is an automated message from RCS HRMS Pro.</p>
                        </div>";
                        $notif->sendEmail($empEmail, "Change Request Approved — {$fieldLabel}", $htmlBody);
                    }

                    // WhatsApp
                    $empMobile = $request['mobile_number'];
                    if (!empty($empMobile)) {
                        $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                        $waMsg = "Hello {$request['emp_name']},\n\nYour change request has been APPROVED.\n\nField: {$fieldLabel}\nNew Value: {$newValue}\n\n- RCS HRMS Pro";
                        $notif->sendWhatsApp($empMobile, $waMsg);
                    }
                } catch (Exception $e) {
                    // Log but don't fail the approval
                    error_log('[Change Request Approval] Notification error: ' . $e->getMessage());
                }

                // Audit log
                if (function_exists('logActivity')) {
                    logActivity('approve_change_request', 'employee', $request['employee_id'],
                        "Approved change request #{$id}: {$field} = " . substr($newValue, 0, 50));
                }

                setFlash('success', 'Change request approved. Employee has been notified.');
            } else {
                setFlash('error', 'Request not found or already processed.');
            }
        }
        redirect('index.php?page=employee/change-requests');
    }

    // ── 2. Reject ──────────────────────────────────────────────
    if ($action === 'reject') {
        $id     = (int) ($_POST['id'] ?? 0);
        $reason = sanitize(trim($_POST['rejection_reason'] ?? ''));

        if ($id > 0 && $reason !== '') {
            // Fetch request WITHOUT JOIN
            $request = $db->fetch(
                "SELECT * FROM employee_change_requests
                 WHERE id = :id AND status = 'pending' LIMIT 1",
                ['id' => $id]
            );

            if ($request) {
                // Fetch employee info separately
                $empInfo = $db->fetch(
                    "SELECT full_name, email, mobile_number, employee_code, designation
                     FROM employees WHERE id = :eid LIMIT 1",
                    ['eid' => (int)$request['employee_id']]
                );
                if ($empInfo) {
                    $request['emp_name']      = $empInfo['full_name'];
                    $request['email']         = $empInfo['email'];
                    $request['mobile_number'] = $empInfo['mobile_number'];
                    $request['employee_code'] = $empInfo['employee_code'];
                    $request['designation']   = $empInfo['designation'];
                } else {
                    $request['emp_name'] = 'Employee #' . $request['employee_id'];
                }
                $field = $request['field_name'];

                // Mark request as rejected
                $db->update(
                    'employee_change_requests',
                    [
                        'status'           => 'rejected',
                        'reviewed_at'      => date('Y-m-d H:i:s'),
                        'reviewed_by'      => $_SESSION['user_id'] ?? null,
                        'rejection_reason' => $reason,
                    ],
                    'id = :id',
                    ['id' => $id]
                );

                // ── Notification to employee ──
                try {
                    require_once __DIR__ . '/../../includes/class.notification.php';
                    $notif = new Notification();

                    $empEmail = $request['email'];
                    if (!empty($empEmail)) {
                        $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                        $htmlBody = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
                            <div style='background:#ef4444;padding:20px;text-align:center;'>
                                <h2 style='color:#fff;margin:0;'>RCS True Facilities Pvt Ltd</h2>
                            </div>
                            <div style='padding:25px;background:#f9fafb;border:1px solid #e5e7eb;'>
                                <p>Dear {$request['emp_name']},</p>
                                <p>Your change request has been <strong style='color:#ef4444;'>REJECTED</strong>.</p>
                                <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Field</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>{$fieldLabel}</td></tr>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Requested Value</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($request['new_value']) . "</td></tr>
                                    <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Rejection Reason</td>
                                        <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($reason) . "</td></tr>
                                </table>
                                <p>If you believe this was an error, please contact HR.</p>
                            </div>
                            <p style='text-align:center;color:#9ca3af;font-size:12px;'>This is an automated message from RCS HRMS Pro.</p>
                        </div>";
                        $notif->sendEmail($empEmail, "Change Request Rejected — {$fieldLabel}", $htmlBody);
                    }

                    // WhatsApp
                    $empMobile = $request['mobile_number'];
                    if (!empty($empMobile)) {
                        $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                        $waMsg = "Hello {$request['emp_name']},\n\nYour change request has been REJECTED.\n\nField: {$fieldLabel}\nReason: {$reason}\n\n- RCS HRMS Pro";
                        $notif->sendWhatsApp($empMobile, $waMsg);
                    }
                } catch (Exception $e) {
                    error_log('[Change Request Rejection] Notification error: ' . $e->getMessage());
                }

                if (function_exists('logActivity')) {
                    logActivity('reject_change_request', 'employee', $request['employee_id'],
                        "Rejected change request #{$id}: {$field} — " . substr($reason, 0, 50));
                }

                setFlash('success', 'Change request rejected. Employee has been notified.');
            } else {
                setFlash('error', 'Request not found or already processed.');
            }
        } elseif ($id > 0 && $reason === '') {
            setFlash('danger', 'Rejection reason is required.');
        }
        redirect('index.php?page=employee/change-requests');
    }

    // ── 3. Bulk approve ───────────────────────────────────────
    if ($action === 'bulk_approve') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $APPROVAL_FIELDS = [
                'full_name', 'father_name', 'date_of_birth', 'gender',
                'designation', 'department', 'profile_pic_url',
            ];
            $count = 0;
            $errors = 0;

            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) continue;

                $request = $db->fetch(
                    "SELECT r.* FROM employee_change_requests r
                     WHERE r.id = :id AND r.status = 'pending'
                     LIMIT 1",
                    ['id' => $id]
                );

                if (!$request) continue;

                $field = $request['field_name'];
                $newValue = $request['new_value'];

                if (in_array($field, $APPROVAL_FIELDS, true)) {
                    try {
                        $db->update('employees', [$field => $newValue], 'id = :id', ['id' => $request['employee_id']]);
                    } catch (Exception $e) {
                        $errors++;
                        continue;
                    }
                }

                $db->update(
                    'employee_change_requests',
                    [
                        'status'      => 'approved',
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'reviewed_by' => $_SESSION['user_id'] ?? null,
                    ],
                    'id = :id',
                    ['id' => $id]
                );
                $count++;
            }

            $msg = "{$count} change request(s) approved.";
            if ($errors > 0) $msg .= " ({$errors} error(s))";
            setFlash('success', $msg);
        } else {
            setFlash('warning', 'No requests selected.');
        }
        redirect('index.php?page=employee/change-requests');
    }
}

// ─── GET Filters ────────────────────────────────────────────────────────────

// Use raw GET values (sanitize does htmlspecialchars which can break PDO binding)
$fStatusRaw = trim($_GET['status'] ?? 'all');
$fSearchRaw = trim($_GET['search'] ?? '');

// Validate status against whitelist
$fStatus = in_array($fStatusRaw, ['all', 'pending', 'approved', 'rejected'], true) ? $fStatusRaw : 'all';
$fSearch = mb_substr($fSearchRaw, 0, 100); // limit search length

// ─── Summary counts (independent query) ────────────────────────────────────

$pendingCount  = 0;
$approvedCount = 0;
$rejectedCount = 0;
try {
    $pendingCount  = (int)$db->fetchColumn("SELECT COUNT(*) FROM employee_change_requests WHERE status = 'pending'") ?: 0;
    $approvedCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM employee_change_requests WHERE status = 'approved'") ?: 0;
    $rejectedCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM employee_change_requests WHERE status = 'rejected'") ?: 0;
} catch (Exception $e) {
    error_log('[change-requests] Count queries failed: ' . $e->getMessage());
}

// ─── Fetch requests — TWO-STEP approach (no JOINs on first step) ────────────
// Step 1: Fetch from employee_change_requests only (guaranteed to work)
// Step 2: Fetch employee names separately and merge

$requests = [];
$dbError  = '';
$diagInfo = '';

// Step 1: Fetch change requests without any JOINs
try {
    $crWhere = ['1=1'];
    $crParams = [];

    if ($fStatus !== 'all') {
        $crWhere[] = 'status = :crstatus';
        $crParams['crstatus'] = $fStatus;
    }

    if ($fSearch !== '') {
        $crWhere[] = 'field_name LIKE :crsearch';
        $crParams['crsearch'] = "%{$fSearch}%";
    }

    $crWhereSql = implode(' AND ', $crWhere);

    $rawRequests = $db->fetchAll(
        "SELECT * FROM employee_change_requests
         WHERE {$crWhereSql}
         ORDER BY
            CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
            created_at DESC",
        $crParams
    );

    $diagInfo .= 'Step1: fetched ' . count($rawRequests) . ' requests. ';

    // Step 2: Get unique employee IDs and batch-fetch their names
    $empIds = array_unique(array_map('intval', array_column($rawRequests, 'employee_id')));
    $empMap = []; // employee_id => [full_name, employee_code, mobile_number, email, designation]

    if (!empty($empIds)) {
        $idList = implode(',', array_map('intval', $empIds));
        try {
            $empRows = $db->fetchAll(
                "SELECT id, full_name, employee_code, mobile_number, email, designation
                 FROM employees WHERE id IN ({$idList})"
            );
            foreach ($empRows as $emp) {
                $empMap[$emp['id']] = $emp;
            }
            $diagInfo .= 'Step2: found ' . count($empMap) . ' employees. ';
        } catch (Exception $e) {
            error_log('[change-requests] Employee lookup failed: ' . $e->getMessage());
            $diagInfo .= 'Step2 ERROR: ' . $e->getMessage();
        }
    }

    // Step 3: Merge employee data into requests
    foreach ($rawRequests as $r) {
        $eid = (int)$r['employee_id'];
        $emp = $empMap[$eid] ?? [];

        // Get reviewer name if available
        $reviewerName = null;
        if (!empty($r['reviewed_by'])) {
            try {
                $rev = $db->fetch(
                    "SELECT full_name FROM employees WHERE id = :rid",
                    ['rid' => (int)$r['reviewed_by']]
                );
                if ($rev) $reviewerName = $rev['full_name'];
            } catch (Exception $e) { /* ignore */ }
        }

        $requests[] = array_merge($r, [
            'emp_name'          => $emp['full_name'] ?? ('Employee #' . $eid),
            'employee_code'     => $emp['employee_code'] ?? '',
            'mobile_number'     => $emp['mobile_number'] ?? '',
            'email'             => $emp['email'] ?? '',
            'emp_designation'   => $emp['designation'] ?? '',
            'client_name'       => null,
            'unit_name'         => null,
            'reviewed_by_name'  => $reviewerName,
        ]);
    }

} catch (Exception $e) {
    $dbError = $e->getMessage();
    error_log('[change-requests] Fetch failed: ' . $dbError);
    $diagInfo .= 'FATAL: ' . $dbError;
}

// ─── Apply search filter on employee name (post-fetch, since we can't search on e.full_name in step 1) ──
if ($fSearch !== '' && !empty($requests)) {
    $searchLower = mb_strtolower($fSearch);
    $requests = array_filter($requests, function($r) use ($searchLower) {
        return mb_strpos(mb_strtolower($r['emp_name'] ?? ''), $searchLower) !== false
            || mb_strpos(mb_strtolower($r['employee_code'] ?? ''), $searchLower) !== false
            || mb_strpos(mb_strtolower($r['field_name'] ?? ''), $searchLower) !== false;
    });
    $requests = array_values($requests); // re-index
}

// ─── Field label map ────────────────────────────────────────────────────────

$fieldLabels = [
    'full_name'       => 'Full Name',
    'father_name'     => "Father's Name",
    'date_of_birth'   => 'Date of Birth',
    'gender'          => 'Gender',
    'designation'     => 'Designation',
    'department'      => 'Department',
    'profile_pic_url' => 'Profile Photo',
];
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h3 class="page-title">Change Request Approvals</h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php?page=employee/index">Employees</a></li>
                <li class="breadcrumb-item active">Change Requests</li>
            </ol>
        </nav>
    </div>
    <div>
        <span class="badge bg-primary-subtle text-primary me-1">Pending: <?= $pendingCount ?></span>
        <span class="badge bg-success-subtle text-success me-1">Approved: <?= $approvedCount ?></span>
        <span class="badge bg-danger-subtle text-danger">Rejected: <?= $rejectedCount ?></span>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Profile Change Requests
                </h5>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <!-- Filter: Status -->
                    <div class="btn-group btn-group-sm">
                        <a href="?page=employee/change-requests&status=all"
                           class="btn <?= $fStatus === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">All (<?= $pendingCount + $approvedCount + $rejectedCount ?>)</a>
                        <a href="?page=employee/change-requests&status=pending"
                           class="btn <?= $fStatus === 'pending' ? 'btn-warning' : 'btn-outline-warning' ?>">Pending (<?= $pendingCount ?>)</a>
                        <a href="?page=employee/change-requests&status=approved"
                           class="btn <?= $fStatus === 'approved' ? 'btn-success' : 'btn-outline-success' ?>">Approved (<?= $approvedCount ?>)</a>
                        <a href="?page=employee/change-requests&status=rejected"
                           class="btn <?= $fStatus === 'rejected' ? 'btn-danger' : 'btn-outline-danger' ?>">Rejected (<?= $rejectedCount ?>)</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($dbError)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Database Error:</strong> Unable to fetch change requests. 
                    Error: <?= htmlspecialchars($dbError) ?>
                    <br><small>Debug: <?= htmlspecialchars($diagInfo) ?></small>
                </div>
                <?php endif; ?>

                <?php if (empty($requests) && empty($dbError) && ($pendingCount + $approvedCount + $rejectedCount) > 0 && $fStatus === 'all'): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Debug Info:</strong> <?= htmlspecialchars($diagInfo) ?>
                    <br>Counts: Pending=<?= $pendingCount ?>, Approved=<?= $approvedCount ?>, Rejected=<?= $rejectedCount ?>
                    <br><small>If you see counts above but no rows below, please share this debug info with support.</small>
                </div>
                <?php endif; ?>

                <!-- Search -->
                <form method="GET" class="row g-2 mb-3">
                    <input type="hidden" name="page" value="employee/change-requests">
                    <div class="col-auto">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search by name, code, or field..."
                               value="<?= htmlspecialchars($fSearch) ?>"
                               style="min-width: 250px;">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <?php if ($fSearch !== '' || $fStatus !== 'all'): ?>
                        <a href="?page=employee/change-requests&status=all" class="btn btn-sm btn-outline-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (empty($requests)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-check-circle text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-2">No change requests found.</p>
                </div>
                <?php else: ?>

                <!-- Bulk actions (only for pending) -->
                <?php if ($fStatus === 'all' || $fStatus === 'pending'): ?>
                <form method="POST" id="bulkForm" action="?page=employee/change-requests<?= $fStatus !== 'all' ? '&status=' . $fStatus : '' ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                    <input type="hidden" name="action" value="bulk_approve">
                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-success" onclick="bulkApprove()">
                            <i class="bi bi-check2-all me-1"></i> Approve Selected
                        </button>
                        <span class="text-muted align-self-center small" id="selectedCount"></span>
                    </div>
                <?php endif; ?>

                <!-- Requests Table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle" id="changeRequestsTable">
                        <thead class="table-light">
                            <tr>
                                <?php if ($fStatus === 'all' || $fStatus === 'pending'): ?>
                                <th style="width:30px;"><input type="checkbox" id="selectAll"></th>
                                <?php endif; ?>
                                <th>#</th>
                                <th>Employee</th>
                                <th>Field</th>
                                <th>Old Value</th>
                                <th>New Value</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <?php if ($fStatus === 'all' || $fStatus === 'approved' || $fStatus === 'rejected'): ?>
                                <th>Reviewed By</th>
                                <?php endif; ?>
                                <?php if ($fStatus === 'rejected'): ?>
                                <th>Rejection Reason</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $i => $r): ?>
                            <tr id="row-<?= $r['id'] ?>">
                                <?php if ($fStatus === 'all' || $fStatus === 'pending'): ?>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                    <input type="checkbox" name="selected_ids[]" value="<?= $r['id'] ?>" class="row-checkbox">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><?= $r['id'] ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($r['emp_name']) ?></strong>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars($r['employee_code'] ?: 'EMP-' . $r['employee_id']) ?>
                                                <?php if ($r['client_name']): ?> · <?= htmlspecialchars($r['client_name']) ?><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info text-info-emphasis">
                                        <?= htmlspecialchars($fieldLabels[$r['field_name']] ?? ucfirst(str_replace('_', ' ', $r['field_name']))) ?>
                                    </span>
                                </td>
                                <td><?php if ($r['field_name'] === 'profile_pic_url' && $r['old_value']): ?>
                                        <img src="<?= htmlspecialchars($r['old_value']) ?>" style="max-height:40px;border-radius:6px;border:1px solid #e5e7eb;" alt="Old">
                                    <?php else: ?>
                                        <code><?= htmlspecialchars($r['old_value'] ?: '—') ?></code>
                                    <?php endif; ?>
                                </td>
                                <td><?php if ($r['field_name'] === 'profile_pic_url' && $r['new_value']): ?>
                                        <img src="<?= htmlspecialchars($r['new_value']) ?>" style="max-height:40px;border-radius:6px;border:1px solid #e5e7eb;" alt="New">
                                    <?php else: ?>
                                        <code class="text-primary"><?= htmlspecialchars($r['new_value']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <span class="badge bg-warning text-warning-emphasis">
                                            <i class="bi bi-clock me-1"></i>Pending
                                        </span>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <span class="badge bg-success text-success-emphasis">
                                            <i class="bi bi-check-circle me-1"></i>Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger text-danger-emphasis">
                                            <i class="bi bi-x-circle me-1"></i>Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= formatDateTime($r['created_at']) ?></td>
                                <?php if ($fStatus === 'all' || $fStatus === 'approved' || $fStatus === 'rejected'): ?>
                                <td class="small text-muted"><?= htmlspecialchars($r['reviewed_by_name'] ?: '—') ?></td>
                                <?php endif; ?>
                                <?php if ($fStatus === 'rejected'): ?>
                                <td class="small text-danger"><?= htmlspecialchars($r['rejection_reason'] ?? '—') ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-success"
                                                onclick="approveRequest(<?= $r['id'] ?>)"
                                                title="Approve">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button type="button" class="btn btn-danger"
                                                onclick="showRejectModal(<?= $r['id'] ?>)"
                                                title="Reject">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($fStatus === 'all' || $fStatus === 'pending'): ?>
                </form>
                <?php endif; ?>

                <?php endif; // end empty check ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Reject Modal ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Change Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" id="rejectId">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                    <p class="mb-3">Please provide a reason for rejecting this change request. The employee will be notified.</p>
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason"
                                  rows="3" required placeholder="e.g., Documents not verified, incorrect information..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Page-specific JS ────────────────────────────────────────────────── -->
<script>
(function() {
    // Select all checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
            updateSelectedCount();
        });
    }

    // Individual checkboxes
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    function updateSelectedCount() {
        const count = document.querySelectorAll('.row-checkbox:checked').length;
        const el = document.getElementById('selectedCount');
        if (el) el.textContent = count > 0 ? count + ' selected' : '';
    }

    // Approve single request
    window.approveRequest = function(id) {
        if (!confirm('Are you sure you want to approve this change request? The employee record will be updated.')) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=employee/change-requests<?= $fStatus !== 'all' ? '&status=' . $fStatus : '' ?>';

        form.appendChild(createInput('action', 'approve'));
        form.appendChild(createInput('id', id));
        form.appendChild(createInput('csrf_token', '<?= htmlspecialchars(getCSRFToken()) ?>'));

        document.body.appendChild(form);
        form.submit();
    };

    // Show reject modal
    window.showRejectModal = function(id) {
        document.getElementById('rejectId').value = id;
        document.getElementById('rejection_reason').value = '';
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    };

    // Bulk approve
    window.bulkApprove = function() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) {
            alert('Please select at least one request.');
            return;
        }
        if (!confirm('Approve ' + checked.length + ' selected change request(s)? Employee records will be updated.')) return;
        document.getElementById('bulkForm').submit();
    };

    function createInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }
})();
</script>
