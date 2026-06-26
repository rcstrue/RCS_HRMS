<?php
/**
 * RCS HRMS Pro - Helpdesk Ticket View / Create
 * Uses ess_helpdesk_tickets + ess_helpdesk_comments tables
 */

$pageTitle = 'New Ticket';
$ticketData = null;
$isEdit = false;

// Fetch ticket if ID provided
if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    $stmt = $db->prepare("SELECT t.*,
            COALESCE(e.full_name, ec.full_name, 'Unknown') AS emp_name,
            COALESCE(e.employee_code, ec.employee_id, '') AS emp_code,
            COALESCE(CONCAT(r.first_name, ' ', r.last_name), r.username, t.resolved_by) AS resolver_name
        FROM ess_helpdesk_tickets t
        LEFT JOIN employees e ON CAST(t.employee_id AS UNSIGNED) = e.id
        LEFT JOIN ess_employee_cache ec ON CAST(t.employee_id AS UNSIGNED) = CAST(ec.employee_id AS UNSIGNED)
        LEFT JOIN users r ON CAST(t.resolved_by AS UNSIGNED) = r.id
        WHERE t.id = :id");
    $stmt->execute([':id' => (int)$_GET['id']]);
    $ticketData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ticketData) {
        $pageTitle = 'Ticket #' . htmlspecialchars($ticketData['ticket_number'] ?: 'TKT-' . str_pad($ticketData['id'], 5, '0', STR_PAD_LEFT));
        $isEdit = true;
    }
}

$currentUserId = $_SESSION['user_id'] ?? '';
$currentUserRole = $_SESSION['role_code'] ?? '';
$isAdmin = ($currentUserRole === 'admin');
$canResolve = $isAdmin || in_array($currentUserRole, ['hr', 'hr_executive']);

// ============================================================================
// POST Handlers
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- Create New Ticket ---
    if ($_POST['action'] === 'create_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'hr';
        $priority = $_POST['priority'] ?? 'medium';
        $employeeId = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;

        if ($subject === '' || $description === '') {
            $flashMsg = 'Subject and Description are required.';
            $flashType = 'error';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO ess_helpdesk_tickets (employee_id, category, subject, description, priority, status, created_by, ticket_number) VALUES (:eid, :cat, :sub, :desc, :pri, 'open', :uid, :tn)");
                $stmt->execute([
                    ':eid' => $employeeId,
                    ':cat' => $category,
                    ':sub' => $subject,
                    ':desc' => $description,
                    ':pri' => $priority,
                    ':uid' => $currentUserId,
                    ':tn' => 'TKT-' . date('Y') . '-' . str_pad(0, 5, '0', STR_PAD_LEFT) // placeholder
                ]);
                $newId = $db->lastInsertId();
                $tktNum = 'TKT-' . date('Y') . '-' . str_pad($newId, 5, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE ess_helpdesk_tickets SET ticket_number = :tn WHERE id = :id")->execute([':tn' => $tktNum, ':id' => $newId]);
                setFlash('success', 'Ticket created: ' . $tktNum);
                redirect('index.php?page=helpdesk/list');
            } catch (Exception $e) {
                $flashMsg = 'Error creating ticket: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }

    // --- Add Comment ---
    if ($_POST['action'] === 'add_comment' && $isEdit && !empty($_POST['comment'])) {
        try {
            $stmt = $db->prepare("INSERT INTO ess_helpdesk_comments (ticket_id, user_id, comment, is_internal) VALUES (:tid, :uid, :comment, :internal)");
            $stmt->execute([
                ':tid' => $ticketData['id'],
                ':uid' => $currentUserId,
                ':comment' => trim($_POST['comment']),
                ':internal' => isset($_POST['is_internal']) ? 1 : 0
            ]);
            setFlash('success', 'Comment added!');
            redirect('index.php?page=helpdesk/add&id=' . $ticketData['id']);
        } catch (Exception $e) {
            $flashMsg = 'Error adding comment.';
            $flashType = 'error';
        }
    }

    // --- Update Status ---
    if ($_POST['action'] === 'update_status' && $isEdit) {
        $newStatus = $_POST['status'] ?? '';
        $resolution = trim($_POST['resolution'] ?? '');
        try {
            $updSql = "UPDATE ess_helpdesk_tickets SET status = :status";
            $updParams = [':status' => $newStatus, ':id' => $ticketData['id']];

            // If resolving/closing, record resolver and resolution
            if (in_array($newStatus, ['resolved', 'closed']) && $resolution !== '') {
                $updSql .= ", resolved_by = :resolver, resolution = :resolution";
                $updParams[':resolver'] = $currentUserId;
                $updParams[':resolution'] = $resolution;
            } elseif (in_array($newStatus, ['resolved', 'closed']) && $resolution === '') {
                $updSql .= ", resolved_by = :resolver";
                $updParams[':resolver'] = $currentUserId;
            }

            // Reopen: clear resolution
            if ($newStatus === 'open') {
                $updSql .= ", resolved_by = NULL, resolution = NULL";
            }

            $updSql .= " WHERE id = :id";
            $db->prepare($updSql)->execute($updParams);
            setFlash('success', 'Status updated!');
            redirect('index.php?page=helpdesk/add&id=' . $ticketData['id']);
        } catch (Exception $e) {
            $flashMsg = 'Error updating status.';
            $flashType = 'error';
        }
    }
}

// ============================================================================
// Fetch Comments
// ============================================================================

$comments = [];
if ($isEdit) {
    try {
        $stmt = $db->prepare("SELECT c.*,
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, c.user_id) AS commenter_name
            FROM ess_helpdesk_comments c
            LEFT JOIN users u ON CAST(c.user_id AS UNSIGNED) = u.id
            WHERE c.ticket_id = :tid
            ORDER BY c.created_at ASC");
        $stmt->execute([':tid' => $ticketData['id']]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ============================================================================
// Helpers
// ============================================================================

$categories = ['hr' => 'HR', 'payroll' => 'Payroll', 'it' => 'IT Support', 'admin' => 'Admin', 'other' => 'Other'];
$priorities = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
$statuses = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
$statusColors = ['open' => 'danger', 'in_progress' => 'warning', 'resolved' => 'success', 'closed' => 'secondary'];
$priorityColors = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];

// Fetch employees for dropdown (admin can create on behalf of others)
$employees = [];
try {
    $empStmt = $db->query("SELECT id, full_name, employee_code FROM employees WHERE status = 'approved' ORDER BY full_name LIMIT 500");
    $employees = $empStmt ? $empStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {}

$flashMsg = $flashMsg ?? '';
$flashType = $flashType ?? 'success';
?>
<div class="container-fluid py-3">
    <!-- Back Link -->
    <a href="index.php?page=helpdesk/list" class="text-decoration-none mb-3 d-inline-block">
        <i class="bi bi-arrow-left me-1"></i>Back to Tickets
    </a>

    <!-- Flash Message -->
    <?php if (isset($flashMsg) && $flashMsg): ?>
    <div class="alert alert-<?php echo $flashType === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($isEdit): ?>
    <!-- ============ VIEW / EDIT TICKET ============ -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary bg-opacity-10 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i><?php echo $pageTitle; ?></h5>
                    <span class="badge bg-<?php echo $statusColors[$ticketData['status']] ?? 'secondary'; ?> fs-6">
                        <?php echo htmlspecialchars($statuses[$ticketData['status']] ?? $ticketData['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">Category</small>
                            <div><?php echo htmlspecialchars($categories[$ticketData['category']] ?? ucfirst($ticketData['category'])); ?></div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Priority</small>
                            <div><span class="badge bg-<?php echo $priorityColors[$ticketData['priority']] ?? 'secondary'; ?>"><?php echo ucfirst(htmlspecialchars($ticketData['priority'])); ?></span></div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">Employee</small>
                            <div><?php echo htmlspecialchars($ticketData['emp_name'] ?? 'Unknown'); ?> <?php echo $ticketData['emp_code'] ? '(' . htmlspecialchars($ticketData['emp_code']) . ')' : ''; ?></div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Created</small>
                            <div><?php echo date('d M Y, h:i A', strtotime($ticketData['created_at'])); ?></div>
                        </div>
                    </div>
                    <hr>
                    <h6>Subject</h6>
                    <p class="fw-bold"><?php echo htmlspecialchars($ticketData['subject']); ?></p>
                    <h6 class="mt-3">Description</h6>
                    <div class="bg-light p-3 rounded" style="white-space: pre-wrap;"><?php echo htmlspecialchars($ticketData['description']); ?></div>

                    <?php if (!empty($ticketData['resolution'])): ?>
                    <h6 class="mt-3 text-success"><i class="bi bi-check-circle me-1"></i>Resolution</h6>
                    <div class="bg-success bg-opacity-10 p-3 rounded border border-success" style="white-space: pre-wrap;"><?php echo htmlspecialchars($ticketData['resolution']); ?></div>
                    <?php if (!empty($ticketData['resolved_by'])): ?>
                    <small class="text-muted mt-1 d-block">Resolved by: <?php echo htmlspecialchars($ticketData['resolver_name'] ?? $ticketData['resolved_by']); ?></small>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Comments Section -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light border-0">
                    <h6 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Comments (<?php echo count($comments); ?>)</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($comments)): ?>
                    <div class="mb-3">
                        <?php foreach ($comments as $c): ?>
                        <div class="border rounded p-2 mb-2 <?php echo $c['is_internal'] ? 'border-warning bg-warning bg-opacity-10' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="small">
                                    <?php echo htmlspecialchars(trim($c['commenter_name'] ?? '') ?: $c['user_id'] ?? 'System'); ?>
                                    <?php if ($c['is_internal']): ?><span class="badge bg-warning text-dark ms-1">Internal</span><?php endif; ?>
                                </strong>
                                <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($c['created_at'])); ?></small>
                            </div>
                            <div class="small" style="white-space: pre-wrap;"><?php echo htmlspecialchars($c['comment']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted text-center mb-3">No comments yet.</p>
                    <?php endif; ?>

                    <!-- Add Comment Form -->
                    <form method="POST">
                        <input type="hidden" name="action" value="add_comment">
                        <div class="mb-2">
                            <textarea name="comment" class="form-control form-control-sm" rows="2" required placeholder="Add a comment..."></textarea>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <?php if ($isAdmin || in_array($currentUserRole, ['hr', 'hr_executive'])): ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="is_internal" id="is_internal">
                                <label class="form-check-label small" for="is_internal">Internal note</label>
                            </div>
                            <?php else: ?>
                            <div></div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-reply me-1"></i>Reply</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Status Update -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-warning bg-opacity-10 border-0">
                    <h6 class="mb-0"><i class="bi bi-gear me-2"></i>Update Status</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach ($statuses as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $ticketData['status'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Resolution / Notes</label>
                            <textarea name="resolution" class="form-control form-control-sm" rows="3" placeholder="Add resolution details..."><?php echo htmlspecialchars($ticketData['resolution'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Update</button>
                    </form>
                </div>
            </div>

            <!-- Ticket Info -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light border-0">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Ticket Info</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0 small">
                        <tr><td class="text-muted">Ticket #</td><td class="fw-bold"><?php echo htmlspecialchars($ticketData['ticket_number'] ?: 'TKT-' . str_pad($ticketData['id'], 5, '0', STR_PAD_LEFT)); ?></td></tr>
                        <tr><td class="text-muted">Priority</td><td><span class="badge bg-<?php echo $priorityColors[$ticketData['priority']] ?? 'secondary'; ?>"><?php echo ucfirst(htmlspecialchars($ticketData['priority'])); ?></span></td></tr>
                        <tr><td class="text-muted">Category</td><td><?php echo htmlspecialchars($categories[$ticketData['category']] ?? ucfirst($ticketData['category'])); ?></td></tr>
                        <tr><td class="text-muted">Created</td><td><?php echo date('d M Y, h:i A', strtotime($ticketData['created_at'])); ?></td></tr>
                        <tr><td class="text-muted">Updated</td><td><?php echo date('d M Y, h:i A', strtotime($ticketData['updated_at'])); ?></td></tr>
                        <?php if (!empty($ticketData['resolved_by'])): ?>
                        <tr><td class="text-muted">Resolved By</td><td><?php echo htmlspecialchars($ticketData['resolver_name'] ?? $ticketData['resolved_by']); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ============ CREATE NEW TICKET ============ -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 border-0">
                    <h5 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>Create New Ticket</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_ticket">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="subject" required placeholder="Brief description of your issue...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Category</label>
                                <select class="form-select" name="category">
                                    <?php foreach ($categories as $v => $l): ?>
                                    <option value="<?php echo $v; ?>"><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Priority</label>
                                <select class="form-select" name="priority">
                                    <?php foreach ($priorities as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $v === 'medium' ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($isAdmin || in_array($currentUserRole, ['hr', 'hr_executive'])): ?>
                            <div class="col-12">
                                <label class="form-label fw-semibold">On Behalf Of (optional)</label>
                                <select class="form-select" name="employee_id">
                                    <option value="">-- Myself --</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="5" required placeholder="Describe your issue in detail..."></textarea>
                            </div>
                        </div>
                        <div class="mt-4 d-flex justify-content-end gap-2">
                            <a href="index.php?page=helpdesk/list" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
