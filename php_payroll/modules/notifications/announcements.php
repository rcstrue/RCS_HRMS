<?php
/**
 * Announcements Module (under Notifications)
 * - Read by ALL users
 * - Edit only by the creator (manager) or Admin
 * - Delete only by Admin
 * - Unread count shown in notification bell dropdown (header.php)
 */

if (!isset($db) || !is_object($db)) {
    header('Location: index.php');
    exit;
}

// ============================================================================
// Auto-create tables (self-contained, no dependency on expense-setup.php)
// ============================================================================

try {
    $db->query("CREATE TABLE IF NOT EXISTS `ess_announcements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `created_by` varchar(50) NOT NULL,
        `target_scope` enum('all','managers','admin') NOT NULL DEFAULT 'all',
        `target_id` varchar(50) DEFAULT NULL,
        `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_created_by` (`created_by`),
        KEY `idx_priority` (`priority`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Ensure target_scope ENUM includes 'managers' (table may have been created without it)
try { $db->query("ALTER TABLE `ess_announcements` MODIFY COLUMN `target_scope` enum('all','managers','admin') NOT NULL DEFAULT 'all'"); } catch (Exception $e) {}

try {
    $db->query("CREATE TABLE IF NOT EXISTS `ess_announcement_reads` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `announcement_id` int(11) NOT NULL,
        `user_id` varchar(50) NOT NULL,
        `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_announcement_user` (`announcement_id`, `user_id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Current user info
$currentUserId  = $_SESSION['user_id'] ?? '';
$currentUserRole = $_SESSION['role_code'] ?? '';
$isAdmin = ($currentUserRole === 'admin');
$annPageUrl = 'index.php?page=notifications/announcements';

// Scope filter: build WHERE clause based on user role
// Admin sees all, Manager sees all+managers+own, Others see all+own
function annScopeWhere($role, $uid) {
    if ($role === 'admin') {
        return ['', []];
    } elseif (in_array($role, ['manager', 'regional_manager'])) {
        return ["AND (a.target_scope = 'all' OR a.target_scope = 'managers' OR a.created_by = :selfid)", [':selfid' => $uid]];
    } else {
        return ["AND (a.target_scope = 'all' OR a.created_by = :selfid)", [':selfid' => $uid]];
    }
}
list($scopeWhere, $scopeParams) = annScopeWhere($currentUserRole, $currentUserId);

// ============================================================================
// POST Handlers
// ============================================================================

$flashMsg = '';
$flashType = 'success';

// --- Create Announcement ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title    = trim($_POST['title'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    $scope    = $_POST['target_scope'] ?? 'all';
    $priority = $_POST['priority'] ?? 'normal';

    if ($title === '' || $content === '') {
        $flashMsg = 'Title and Content are required.';
        $flashType = 'error';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO ess_announcements (title, content, created_by, target_scope, priority) VALUES (:title, :content, :created_by, :scope, :priority)");
            $stmt->execute([
                ':title'      => $title,
                ':content'    => $content,
                ':created_by' => $currentUserId,
                ':scope'      => $scope,
                ':priority'   => $priority
            ]);
            $flashMsg = 'Announcement published successfully!';
        } catch (Exception $e) {
            $flashMsg = 'Error creating announcement: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// --- Edit Announcement ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $annId   = (int)($_POST['announcement_id'] ?? 0);
    $title   = trim($_POST['edit_title'] ?? '');
    $content = trim($_POST['edit_content'] ?? '');
    $scope   = $_POST['edit_target_scope'] ?? 'all';
    $priority= $_POST['edit_priority'] ?? 'normal';

    if ($annId > 0 && $title !== '' && $content !== '') {
        try {
            $checkStmt = $db->prepare("SELECT created_by FROM ess_announcements WHERE id = :id");
            $checkStmt->execute([':id' => $annId]);
            $owner = $checkStmt->fetchColumn();

            if ($owner && ($owner == $currentUserId || $isAdmin)) {
                $updStmt = $db->prepare("UPDATE ess_announcements SET title = :title, content = :content, target_scope = :scope, priority = :priority WHERE id = :id");
                $updStmt->execute([
                    ':title'    => $title,
                    ':content'  => $content,
                    ':scope'    => $scope,
                    ':priority' => $priority,
                    ':id'       => $annId
                ]);
                $flashMsg = 'Announcement updated successfully!';
            } else {
                $flashMsg = 'You are not authorized to edit this announcement.';
                $flashType = 'error';
            }
        } catch (Exception $e) {
            $flashMsg = 'Error updating announcement: ' . $e->getMessage();
            $flashType = 'error';
        }
    } else {
        $flashMsg = 'Title and Content are required.';
        $flashType = 'error';
    }
}

// --- Delete Announcement (Admin only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $annId = (int)($_POST['announcement_id'] ?? 0);

    if ($annId > 0 && $isAdmin) {
        try {
            $db->prepare("DELETE FROM ess_announcement_reads WHERE announcement_id = :id")->execute([':id' => $annId]);
            $db->prepare("DELETE FROM ess_announcements WHERE id = :id")->execute([':id' => $annId]);
            $flashMsg = 'Announcement deleted successfully!';
        } catch (Exception $e) {
            $flashMsg = 'Error deleting announcement: ' . $e->getMessage();
            $flashType = 'error';
        }
    } elseif (!$isAdmin) {
        $flashMsg = 'Only admin can delete announcements.';
        $flashType = 'error';
    }
}

// --- Mark as Read (AJAX or GET) ---
if (isset($_GET['mark_read']) && (int)$_GET['mark_read'] > 0) {
    $annId = (int)$_GET['mark_read'];
    try {
        $db->prepare("INSERT IGNORE INTO ess_announcement_reads (announcement_id, user_id) VALUES (:aid, :uid)")
           ->execute([':aid' => $annId, ':uid' => $currentUserId]);
    } catch (Exception $e) {}
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['status' => 'ok']);
        exit;
    }
    header('Location: ' . $annPageUrl);
    exit;
}

// --- Mark All as Read ---
if (isset($_GET['mark_all_read'])) {
    try {
        $scopeMarkSql = str_replace('AND', 'WHERE', $scopeWhere);
        $db->prepare("INSERT IGNORE INTO ess_announcement_reads (announcement_id, user_id) SELECT id, :uid FROM ess_announcements WHERE 1=1 $scopeMarkSql")
           ->execute(array_merge([':uid' => $currentUserId], $scopeParams));
    } catch (Exception $e) {}
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['status' => 'ok']);
        exit;
    }
    header('Location: ' . $annPageUrl);
    exit;
}

// ============================================================================
// Fetch Data
// ============================================================================

$unreadCount = 0;
try {
    $unreadCount = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM ess_announcements a LEFT JOIN ess_announcement_reads r ON a.id = r.announcement_id AND r.user_id = :uid WHERE r.id IS NULL $scopeWhere",
        array_merge([':uid' => $currentUserId], $scopeParams)
    ) ?: 0;
} catch (Exception $e) {}

$announcements = [];
try {
    $stmt = $db->prepare("
        SELECT a.*,
               r.read_at AS is_read_at,
               COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name), a.created_by) AS creator_name
        FROM ess_announcements a
        LEFT JOIN ess_announcement_reads r ON a.id = r.announcement_id AND r.user_id = :uid
        LEFT JOIN ess_employee_cache e ON a.created_by = e.employee_id
        LEFT JOIN users u ON a.created_by = CAST(u.id AS CHAR COLLATE utf8mb4_unicode_ci)
        WHERE 1=1 $scopeWhere
        ORDER BY
            FIELD(a.priority, 'urgent', 'high', 'normal', 'low'),
            a.created_at DESC
    ");
    $stmt->execute(array_merge([':uid' => $currentUserId], $scopeParams));
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $db->prepare("
            SELECT a.*, r.read_at AS is_read_at, a.created_by AS creator_name
            FROM ess_announcements a
            LEFT JOIN ess_announcement_reads r ON a.id = r.announcement_id AND r.user_id = :uid
            WHERE 1=1 $scopeWhere
            ORDER BY a.created_at DESC
        ");
        $stmt->execute(array_merge([':uid' => $currentUserId], $scopeParams));
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

function priorityBadge($priority) {
    $map = [
        'urgent' => 'bg-danger',
        'high'   => 'bg-warning text-dark',
        'normal' => 'bg-info text-dark',
        'low'    => 'bg-secondary'
    ];
    $cls = $map[$priority] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars(ucfirst($priority)) . '</span>';
}

function scopeLabel($scope) {
    $map = [
        'all'     => '<i class="bi bi-people me-1"></i>All Users',
        'managers'=> '<i class="bi bi-person-badge me-1"></i>Managers Only',
        'admin'   => '<i class="bi bi-shield-lock me-1"></i>Admin Only'
    ];
    return $map[$scope] ?? htmlspecialchars($scope);
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'm ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}

$totalAnnouncements = count($announcements);
?>
<div class="container-fluid py-3">
    <!-- Page Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-megaphone me-2"></i>Announcements</h4>
            <small class="text-muted">Stay updated with the latest announcements from admin and managers</small>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <?php if ($unreadCount > 0): ?>
            <a href="<?php echo $annPageUrl; ?>&mark_all_read=1" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-check2-all me-1"></i>Mark All Read
            </a>
            <?php endif; ?>
            <?php if ($isAdmin || in_array($currentUserRole, ['manager', 'regional_manager'])): ?>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-plus-lg me-1"></i>New Announcement
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Unread Count Banner -->
    <?php if ($unreadCount > 0): ?>
    <div class="alert alert-info d-flex align-items-center py-2 mb-3" role="alert">
        <i class="bi bi-bell-fill me-2"></i>
        <div>You have <strong><?php echo $unreadCount; ?></strong> unread announcement<?php echo $unreadCount > 1 ? 's' : ''; ?></div>
    </div>
    <?php endif; ?>

    <!-- Flash Message -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-<?php echo $flashType === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show mb-3" role="alert">
        <?php echo htmlspecialchars($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Announcements List -->
    <?php if (empty($announcements)): ?>
    <div class="text-center py-5">
        <i class="bi bi-megaphone d-block fs-1 text-muted mb-3"></i>
        <h5 class="text-muted">No Announcements Yet</h5>
        <p class="text-muted">Announcements from admin and managers will appear here.</p>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($announcements as $ann): ?>
        <?php
            $isUnread = empty($ann['is_read_at']);
            $isOwner  = ($ann['created_by'] == $currentUserId);
            $canEdit  = $isOwner || $isAdmin;
            $canDelete = $isAdmin;
            if ($isUnread): ?>
                <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                     style="display:none;"
                     onload="(function(){fetch('<?php echo $annPageUrl; ?>&mark_read=<?php echo $ann['id']; ?>')})()" />
        <?php endif; ?>
        <div class="col-12 mb-3">
            <div class="card shadow-sm border-0 <?php echo $isUnread ? 'border-start border-4 border-primary' : ''; ?>">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <?php echo priorityBadge($ann['priority']); ?>
                            <span class="text-muted small"><?php echo scopeLabel($ann['target_scope']); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($isUnread): ?>
                            <span class="badge bg-primary rounded-pill"><i class="bi bi-dot me-1"></i>New</span>
                            <?php endif; ?>
                            <small class="text-muted"><i class="bi bi-clock me-1"></i><?php echo timeAgo($ann['created_at']); ?></small>
                        </div>
                    </div>

                    <h5 class="card-title mb-2 <?php echo $isUnread ? 'fw-bold' : ''; ?>">
                        <?php echo htmlspecialchars($ann['title']); ?>
                    </h5>

                    <div class="card-text text-muted mb-3" style="white-space: pre-wrap; line-height: 1.6;">
                        <?php echo htmlspecialchars($ann['content']); ?>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-person me-1"></i>
                            <?php echo htmlspecialchars($ann['creator_name'] ?? 'Unknown'); ?>
                            &bull; <?php echo date('d M Y, h:i A', strtotime($ann['created_at'])); ?>
                        </small>

                        <?php if ($canEdit || $canDelete): ?>
                        <div class="d-flex gap-1">
                            <?php if ($canEdit): ?>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $ann['id']; ?>" title="Edit">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $ann['id']; ?>, '<?php echo htmlspecialchars(addslashes($ann['title'])); ?>')" title="Delete">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <?php if ($canEdit): ?>
        <div class="modal fade" id="editModal<?php echo $ann['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="<?php echo $annPageUrl; ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                        <div class="modal-header bg-warning bg-opacity-10">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Announcement</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Title</label>
                                <input type="text" name="edit_title" class="form-control" value="<?php echo htmlspecialchars($ann['title']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Content</label>
                                <textarea name="edit_content" class="form-control" rows="6" required><?php echo htmlspecialchars($ann['content']); ?></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Target Scope</label>
                                    <select name="edit_target_scope" class="form-select">
                                        <option value="all" <?php echo $ann['target_scope'] === 'all' ? 'selected' : ''; ?>>All Users</option>
                                        <option value="managers" <?php echo $ann['target_scope'] === 'managers' ? 'selected' : ''; ?>>Managers Only</option>
                                        <?php if ($isAdmin): ?>
                                        <option value="admin" <?php echo $ann['target_scope'] === 'admin' ? 'selected' : ''; ?>>Admin Only</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Priority</label>
                                    <select name="edit_priority" class="form-select">
                                        <option value="low" <?php echo $ann['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="normal" <?php echo $ann['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                        <option value="high" <?php echo $ann['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="urgent" <?php echo $ann['priority'] === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Summary Footer -->
    <?php if ($totalAnnouncements > 0): ?>
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
            <span class="text-muted small"><i class="bi bi-megaphone me-1"></i>Total: <strong><?php echo $totalAnnouncements; ?></strong></span>
            <span class="text-muted small"><i class="bi bi-eye me-1"></i>Read: <strong><?php echo $totalAnnouncements - $unreadCount; ?></strong></span>
            <span class="text-muted small"><i class="bi bi-eye-slash me-1"></i>Unread: <strong class="text-primary"><?php echo $unreadCount; ?></strong></span>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create Announcement Modal -->
<?php if ($isAdmin || in_array($currentUserRole, ['manager', 'regional_manager'])): ?>
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?php echo $annPageUrl; ?>" id="createForm">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-primary bg-opacity-10">
                    <h5 class="modal-title"><i class="bi bi-megaphone me-2"></i>New Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" id="annTitle" placeholder="Enter announcement title..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Content <span class="text-danger">*</span></label>
                        <textarea name="content" class="form-control" rows="6" id="annContent" placeholder="Write your announcement here..." required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Target Scope</label>
                            <select name="target_scope" class="form-select">
                                <option value="all">All Users</option>
                                <option value="managers">Managers Only</option>
                                <?php if ($isAdmin): ?>
                                <option value="admin">Admin Only</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="normal" selected>Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="publishBtn"><i class="bi bi-send me-1"></i>Publish</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger bg-opacity-10">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this announcement?</p>
                <p class="text-muted small mb-0"><strong id="deleteTitle"></strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?php echo $annPageUrl; ?>" id="deleteForm" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="announcement_id" id="deleteId" value="">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, title) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteTitle').textContent = title;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

document.getElementById('createForm')?.addEventListener('submit', function(e) {
    const title = document.getElementById('annTitle').value.trim();
    const content = document.getElementById('annContent').value.trim();
    if (!title || !content) {
        e.preventDefault();
        alert('Please fill in both Title and Content.');
    }
});
</script>
