<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }

/**
 * RCS HRMS Pro — Employee Change Requests Approval Page
 *
 * Allows HR/Admin to view, approve, or reject change requests
 * submitted by employees via the ESS mobile app.
 *
 * Approve: updates the employee record with the new value
 * (including KYC document image URLs: aadhaar_front_url, aadhaar_back_url, bank_document_url).
 * Reject: stores rejection reason; employee sees it in ESS.
 * Both actions send email + WhatsApp notification to the employee.
 */

$pageTitle = 'Change Request Approvals';

// ─── In-App Notification Helper (INSERT into ess_notifications) ──────────
function sendInAppNotification(int $employeeId, string $title, string $message, string $type = 'info', string $link = '') {
    global $db;
    try {
        // Must go through $db->insert(): Database::exec() accepts no bind params,
        // so the previous $db->exec($sql, [...]) silently discarded them. PDO then
        // sent the raw SQL containing ":employee_id" placeholders, which threw and
        // was swallowed below — the employee was never actually notified.
        // Format matches ESS notifications.php / cron-auto-notifications.php.
        $db->insert('ess_notifications', [
            'employee_id' => (string)$employeeId,
            'title'       => $title,
            'message'     => $message,
            'type'        => $type,
            'link'        => $link ?: null,
            'is_read'     => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        error_log('[change-request in-app notif] ' . $e->getMessage());
    }
}

// ─── Upload URL Helper ──────────────────────────────────────────────────────
// Reuses the project's existing viewUploadUrl() normalizer (byte-identical to the
// definition in modules/employee/view.php:10-15). That file is a page controller,
// so it cannot be required from here without executing the whole employee-view
// page — the function is therefore declared only if it is not already loaded.
// Needed because stored values are inconsistent: the ESS upload endpoints
// (api/ess/upload-base64.php) and the fix-upload-paths.php migration may already
// include the "/uploads/" prefix, while older rows store a bare "profile/xxx.jpg".
// Naively prefixing produced "/uploads//uploads/..." and a broken thumbnail.
if (!function_exists('viewUploadUrl')) {
    function viewUploadUrl($path) {
        if (empty($path)) return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        if (strpos($path, '/uploads/') === 0) return $path;
        return '/uploads/' . ltrim($path, '/');
    }
}

// ─── Sensitive-approval policy ──────────────────────────────────────────────
// Bank, statutory and KYC-document changes can divert salary payments or swap
// identity documents, so only Admin/HR may APPROVE them. Managers and
// supervisors can still see such a request and REJECT it — rejection changes no
// employee data. Declared outside the POST block because the table below uses it
// to hide the approve control and the bulk checkbox too.
$SENSITIVE_APPROVAL_FIELDS = [
    'aadhaar_number', 'uan_number', 'esic_number',
    'bank_name', 'account_holder_name', 'account_number', 'ifsc_code',
    'aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url',
];
$SENSITIVE_APPROVER_ROLES = ['admin', 'hr_executive', 'hr'];
$canApproveSensitive      = in_array($_SESSION['role_code'] ?? '', $SENSITIVE_APPROVER_ROLES, true);

// True when the current user may not approve this particular request.
function isSensitiveApprovalBlocked($request, array $sensitiveFields, $canApproveSensitive) {
    return !$canApproveSensitive
        && in_array($request['field_name'] ?? '', $sensitiveFields, true);
}

// ─── POST Actions ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php?page=employee/change-requests');
    }
    $action = $_POST['action'] ?? '';

    // ── Whitelist of fields that can be updated on approval ──
    $APPROVAL_FIELDS = [
        'full_name', 'father_name', 'date_of_birth', 'gender',
        'designation', 'department', 'profile_pic_url',
        // Bank & statutory details — manager/employee change requests for the
        // ESS Manager Edit flow (see RCS_HRMS_Manager_Edit_Button.md). Sensitive
        // columns on employees; applied to the employee record on approval.
        'uan_number', 'esic_number', 'aadhaar_number',
        'bank_name', 'account_holder_name', 'account_number', 'ifsc_code',
        // KYC document image URLs — employee can request replacement/upload of
        // Aadhaar front, back, and bank passbook images via EditProfilePage.
        'aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url',
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

                // Bank / statutory / KYC requests can only be approved by Admin or HR
                if (isSensitiveApprovalBlocked($request, $SENSITIVE_APPROVAL_FIELDS, $canApproveSensitive)) {
                    setFlash('error', 'Only Admin or HR can approve bank, statutory or KYC document changes.');
                    redirect('index.php?page=employee/change-requests');
                }

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

                // ── In-App Notification to employee ──
                $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                sendInAppNotification(
                    (int)$request['employee_id'],
                    "Change Request Approved — {$fieldLabel}",
                    "Your change request for {$fieldLabel} has been APPROVED. New value: {$newValue}",
                    'success',
                    '/profile/change-requests'
                );

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
        $reason = trim($_POST['rejection_reason'] ?? '');

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

                // ── In-App Notification to employee ──
                $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                sendInAppNotification(
                    (int)$request['employee_id'],
                    "Change Request Rejected — {$fieldLabel}",
                    "Your change request for {$fieldLabel} has been REJECTED. Reason: {$reason}",
                    'danger',
                    '/profile/change-requests'
                );

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
            $count = 0;
            $errors = 0;
            $skipped = 0;

            // The whole batch runs in one transaction: if anything unexpected
            // fails part-way through, nothing is left half-applied — each
            // employee record and its request row stay in step, and no employee
            // is told about a change that was rolled back.
            $db->beginTransaction();
            try {
                foreach ($ids as $id) {
                    $id = (int) $id;
                    if ($id <= 0) continue;

                    $request = $db->fetch(
                        "SELECT * FROM employee_change_requests
                         WHERE id = :id AND status = 'pending'
                         LIMIT 1",
                        ['id' => $id]
                    );

                    if (!$request) continue;

                    $field = $request['field_name'];
                    $newValue = $request['new_value'];

                    // Bank / statutory / KYC requests can only be approved by Admin or HR:
                    // leave them pending rather than failing the whole batch.
                    if (isSensitiveApprovalBlocked($request, $SENSITIVE_APPROVAL_FIELDS, $canApproveSensitive)) {
                        $skipped++;
                        continue;
                    }

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

                    // ── In-App Notification to employee (same as single approve) ──
                    $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                    sendInAppNotification(
                        (int)$request['employee_id'],
                        "Change Request Approved — {$fieldLabel}",
                        "Your change request for {$fieldLabel} has been APPROVED. New value: {$newValue}",
                        'success',
                        '/profile/change-requests'
                    );

                    // ── Audit log (same action name as single approve) ──
                    // Guarded separately so an audit_log problem cannot abort the batch.
                    if (function_exists('logActivity')) {
                        try {
                            logActivity('approve_change_request', 'employee', $request['employee_id'],
                                "Approved change request #{$id}: {$field} = " . substr($newValue, 0, 50));
                        } catch (Exception $e) {
                            error_log('[change-requests] bulk approve audit log failed: ' . $e->getMessage());
                        }
                    }
                }
                $db->commit();
            } catch (Exception $e) {
                try { $db->rollBack(); } catch (Exception $ignored) { /* transaction already closed */ }
                error_log('[change-requests] Bulk approve failed, rolled back: ' . $e->getMessage());
                setFlash('error', 'Bulk approve failed and was rolled back. No changes were saved.');
                redirect('index.php?page=employee/change-requests');
            }

            $msg = "{$count} change request(s) approved.";
            if ($errors > 0) $msg .= " ({$errors} error(s))";
            if ($skipped > 0) $msg .= " ({$skipped} skipped — Admin/HR approval required)";
            // Only blocked-and-nothing-approved is a warning, not a success
            setFlash(($count === 0 && $skipped > 0) ? 'warning' : 'success', $msg);
        } else {
            setFlash('warning', 'No requests selected.');
        }
        redirect('index.php?page=employee/change-requests');
    }
}

// ─── GET Filters ────────────────────────────────────────────────────────────

// Raw GET values — do NOT use sanitize() (htmlspecialchars breaks PDO binding)
$fStatusRaw = trim($_GET['status'] ?? 'all');
$fSearchRaw = trim($_GET['search'] ?? '');

// Validate status against whitelist
$fStatus = in_array($fStatusRaw, ['all', 'pending', 'approved', 'rejected'], true) ? $fStatusRaw : 'all';
$fSearch = substr($fSearchRaw, 0, 100);

// ─── Pagination ─────────────────────────────────────────────────────────────
// "page" is the route parameter, so the page number uses "page_num" instead
// (same convention as modules/notifications/whatsapp.php).
$perPage = defined('RECORDS_PER_PAGE') ? (int)RECORDS_PER_PAGE : 50;
$pageNum = max(1, (int)($_GET['page_num'] ?? 1));

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

// ─── Fetch requests — 2-step approach (no JOINs) ──────────────────────────

$requests   = [];
$dbError    = '';
$diagInfo   = '';
$totalRows  = 0;
$totalPages = 1;
$offset     = 0;

try {
    // ── Shared WHERE clause for the count and the page query ──
    // Search moved into SQL so it composes with LIMIT/OFFSET: filtering after the
    // fetch (the old behaviour) would have paginated the unfiltered result set.
    $whereParts  = [];
    $whereParams = [];

    if ($fStatus !== 'all') {
        $whereParts[] = 'status = :crstatus';
        $whereParams['crstatus'] = $fStatus;
    }

    if ($fSearch !== '') {
        // Step 1 stays JOIN-free, so employees matching the search are resolved
        // first and matched by employee_id.
        $orParts = ['field_name LIKE :cr_field'];
        $whereParams['cr_field'] = '%' . $fSearch . '%';

        $like = '%' . $fSearch . '%';
        $empMatches = $db->fetchAll(
            "SELECT id FROM employees WHERE full_name LIKE :s1 OR employee_code LIKE :s2 LIMIT 200",
            ['s1' => $like, 's2' => $like]
        );
        $searchEmpIds = [];
        foreach ($empMatches as $em) {
            $searchEmpIds[] = (int)$em['id'];
        }
        if (!empty($searchEmpIds)) {
            $orParts[] = 'employee_id IN (' . implode(',', $searchEmpIds) . ')';
        }
        $whereParts[] = '(' . implode(' OR ', $orParts) . ')';
    }

    $whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Total rows for the current filter + search, then exactly one page of them
    $totalRows  = (int)$db->fetchColumn("SELECT COUNT(*) FROM employee_change_requests {$whereSql}", $whereParams);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($pageNum > $totalPages) {
        $pageNum = $totalPages;
    }
    $offset = ($pageNum - 1) * $perPage;

    // Step 1: Fetch one page of change requests WITHOUT any JOINs
    $rawRequests = $db->fetchAll(
        "SELECT * FROM employee_change_requests {$whereSql} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
        $whereParams
    );

    $diagInfo .= 'Step1: fetched ' . count($rawRequests) . ' of ' . $totalRows . ' requests (page ' . $pageNum . '). ';

    // Step 2: Get unique employee IDs and batch-fetch
    $empIds = [];
    foreach ($rawRequests as $r) {
        $eid = (int)$r['employee_id'];
        if ($eid > 0 && !in_array($eid, $empIds)) {
            $empIds[] = $eid;
        }
    }
    $empMap = [];

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
        } catch (Exception $e) {
            $diagInfo .= 'Step2 ERROR: ' . $e->getMessage();
        }
    }

    $diagInfo .= 'Step2: found ' . count($empMap) . ' employees. ';

    // Step 2b: batch-resolve reviewer names (reviewed_by holds a users id).
    // One query for the whole page instead of one per row.
    $revIds = [];
    foreach ($rawRequests as $r) {
        $rid = (int)($r['reviewed_by'] ?? 0);
        if ($rid > 0 && !in_array($rid, $revIds, true)) {
            $revIds[] = $rid;
        }
    }
    $revMap = [];
    if (!empty($revIds)) {
        try {
            $revRows = $db->fetchAll(
                "SELECT id, first_name, last_name, username
                 FROM users WHERE id IN (" . implode(',', array_map('intval', $revIds)) . ")"
            );
            foreach ($revRows as $u) {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                $revMap[(int)$u['id']] = $name !== '' ? $name : ($u['username'] ?? null);
            }
        } catch (Exception $e) {
            $diagInfo .= 'Step2b ERROR: ' . $e->getMessage();
        }
    }

    // Step 3: Merge employee data into requests
    foreach ($rawRequests as $r) {
        $eid = (int)$r['employee_id'];
        $emp = $empMap[$eid] ?? [];

        // Reviewer name — already batch-resolved in Step 2b.
        // reviewed_by stores a USERS id — $_SESSION['user_id'] is users.id (see
        // Auth::login()), not an employees id. Resolving it against `employees`
        // showed '—' or an unrelated employee's name whose id coincided.
        $revId = (int)($r['reviewed_by'] ?? 0);
        $reviewerName = $revId > 0 ? ($revMap[$revId] ?? null) : null;

        $requests[] = array_merge($r, [
            'emp_name'          => $emp['full_name'] ?? ('Employee #' . $eid),
            'employee_code'     => $emp['employee_code'] ?? '',
            'mobile_number'     => $emp['mobile_number'] ?? '',
            'email'             => $emp['email'] ?? '',
            'emp_designation'   => $emp['designation'] ?? '',
            'reviewed_by_name'  => $reviewerName,
        ]);
    }

} catch (Exception $e) {
    $dbError = $e->getMessage();
    error_log('[change-requests] Fetch failed: ' . $dbError);
    $diagInfo .= 'FATAL: ' . $dbError;
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
    'uan_number'          => 'UAN Number',
    'esic_number'         => 'ESIC Number',
    'aadhaar_number'      => 'Aadhaar Number',
    'bank_name'           => 'Bank Name',
    'account_holder_name' => 'Account Holder Name',
    'account_number'      => 'Account Number',
    'ifsc_code'           => 'IFSC Code',
];

// ─── Generate CSRF token for forms ──────────────────────────────────────────
$csrfToken = generateCSRFToken();
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

                <!-- Search -->
                <form method="GET" class="row g-2 mb-3">
                    <input type="hidden" name="page" value="employee/change-requests">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($fStatus) ?>">
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
                    <p class="text-muted mt-2">
                        <?php if (!empty($dbError)): ?>
                            No change requests could be loaded.
                        <?php elseif ($fSearch !== ''): ?>
                            No change requests match "<?= htmlspecialchars($fSearch) ?>"<?= $fStatus !== 'all' ? ' with status ' . htmlspecialchars($fStatus) : '' ?>.
                        <?php elseif ($fStatus !== 'all'): ?>
                            No <?= htmlspecialchars($fStatus) ?> change requests found.
                        <?php else: ?>
                            No change requests found.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($dbError) && ($pendingCount + $approvedCount + $rejectedCount) > 0): ?>
                    <p class="text-muted small">Debug: <?= htmlspecialchars($diagInfo) ?></p>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <!-- Bulk actions (only for pending) -->
                <?php if ($fStatus === 'all' || $fStatus === 'pending'): ?>
                <form method="POST" id="bulkForm" action="?page=employee/change-requests<?= $fStatus !== 'all' ? '&status=' . $fStatus : '' ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
                                    <?php if ($r['status'] === 'pending' && !isSensitiveApprovalBlocked($r, $SENSITIVE_APPROVAL_FIELDS, $canApproveSensitive)): ?>
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
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info text-info-emphasis">
                                        <?= htmlspecialchars($fieldLabels[$r['field_name']] ?? ucfirst(str_replace('_', ' ', $r['field_name']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['field_name'] === 'profile_pic_url' && $r['old_value']): ?>
                                        <img src="<?= htmlspecialchars(viewUploadUrl($r['old_value'])) ?>" style="max-height:40px;border-radius:6px;border:1px solid #e5e7eb;" alt="Old">
                                    <?php else: ?>
                                        <code><?= htmlspecialchars($r['old_value'] ?: '—') ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['field_name'] === 'profile_pic_url' && $r['new_value']): ?>
                                        <img src="<?= htmlspecialchars(viewUploadUrl($r['new_value'])) ?>" style="max-height:40px;border-radius:6px;border:1px solid #e5e7eb;" alt="New">
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
                                        <?php if (isSensitiveApprovalBlocked($r, $SENSITIVE_APPROVAL_FIELDS, $canApproveSensitive)): ?>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-danger"
                                                    onclick="showRejectModal(<?= $r['id'] ?>)"
                                                    title="Reject">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1"
                                              title="Bank, statutory and KYC document changes can only be approved by Admin or HR">
                                            <i class="bi bi-lock me-1"></i>Admin/HR only
                                        </span>
                                        <?php else: ?>
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
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Result summary + pagination -->
                <?php
                $showingFrom = $totalRows > 0 ? $offset + 1 : 0;
                $showingTo   = min($offset + count($requests), $totalRows);
                $pageQs      = http_build_query(array_filter([
                    'status' => $fStatus !== 'all' ? $fStatus : null,
                    'search' => $fSearch !== '' ? $fSearch : null,
                ]));
                $pageBase    = '?page=employee/change-requests' . ($pageQs !== '' ? '&' . $pageQs : '');
                ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                    <small class="text-muted">
                        Showing <?= $showingFrom ?>–<?= $showingTo ?> of <?= $totalRows ?> request(s)
                    </small>
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $maxVisible = 5;
                            $startPage  = max(1, $pageNum - (int)floor($maxVisible / 2));
                            $endPage    = min($totalPages, $startPage + $maxVisible - 1);
                            if ($endPage - $startPage < $maxVisible - 1) {
                                $startPage = max(1, $endPage - $maxVisible + 1);
                            }
                            ?>
                            <?php if ($pageNum > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= htmlspecialchars($pageBase) ?>&page_num=<?= $pageNum - 1 ?>">&laquo;</a>
                            </li>
                            <?php endif; ?>
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $pageNum ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($pageBase) ?>&page_num=<?= $i ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>
                            <?php if ($pageNum < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= htmlspecialchars($pageBase) ?>&page_num=<?= $pageNum + 1 ?>">&raquo;</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
            document.querySelectorAll('.row-checkbox').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
            updateSelectedCount();
        });
    }

    // Individual checkboxes
    document.querySelectorAll('.row-checkbox').forEach(function(cb) {
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
        form.appendChild(createInput('csrf_token', '<?= htmlspecialchars($csrfToken) ?>'));

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
