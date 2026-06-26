<?php
/**
 * RCS HRMS Pro - Helpdesk Tickets List
 * Uses ess_helpdesk_tickets table
 */

$pageTitle = 'Helpdesk';

// Auto-migrate: add missing columns to ess_helpdesk_tickets
try {
    $db->query("CREATE TABLE IF NOT EXISTS `ess_helpdesk_tickets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) DEFAULT NULL,
        `category` enum('hr','payroll','it','admin','other') NOT NULL DEFAULT 'hr',
        `subject` varchar(255) NOT NULL,
        `description` text DEFAULT NULL,
        `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
        `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
        `resolved_by` varchar(50) DEFAULT NULL,
        `resolution` text DEFAULT NULL,
        `created_by` varchar(50) DEFAULT NULL,
        `ticket_number` varchar(20) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_priority` (`priority`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Add missing columns if table already exists
try { $db->query("ALTER TABLE `ess_helpdesk_tickets` ADD COLUMN `ticket_number` varchar(20) DEFAULT NULL AFTER `resolution`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `ess_helpdesk_tickets` ADD COLUMN `created_by` varchar(50) DEFAULT NULL AFTER `ticket_number`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `ess_helpdesk_tickets` ADD COLUMN `resolved_by` varchar(50) DEFAULT NULL AFTER `priority`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `ess_helpdesk_tickets` ADD COLUMN `resolution` text DEFAULT NULL AFTER `resolved_by`"); } catch (Exception $e) {}

// Backfill ticket_number for existing rows that don't have one
try {
    $emptyTickets = $db->query("SELECT id FROM ess_helpdesk_tickets WHERE ticket_number IS NULL OR ticket_number = '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($emptyTickets as $row) {
        $tktNum = 'TKT-' . date('Y') . '-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE ess_helpdesk_tickets SET ticket_number = :tn WHERE id = :id")->execute([':tn' => $tktNum, ':id' => $row['id']]);
    }
} catch (Exception $e) {}

// Create comments table
try {
    $db->query("CREATE TABLE IF NOT EXISTS `ess_helpdesk_comments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ticket_id` int(11) NOT NULL,
        `user_id` varchar(50) DEFAULT NULL,
        `comment` text NOT NULL,
        `is_internal` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_ticket` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Filters
$statusFilter = sanitize($_GET['status'] ?? '');
$categoryFilter = sanitize($_GET['category'] ?? '');
$priorityFilter = sanitize($_GET['priority'] ?? '');
$searchFilter = sanitize($_GET['search'] ?? '');

$whereClause = "1=1";
$params = [];

if ($statusFilter) {
    $whereClause .= " AND t.status = :status";
    $params[':status'] = $statusFilter;
}
if ($categoryFilter) {
    $whereClause .= " AND t.category = :category";
    $params[':category'] = $categoryFilter;
}
if ($priorityFilter) {
    $whereClause .= " AND t.priority = :priority";
    $params[':priority'] = $priorityFilter;
}
if ($searchFilter) {
    $whereClause .= " AND (t.subject LIKE :search OR t.ticket_number LIKE :search OR t.description LIKE :search)";
    $params[':search'] = '%' . $searchFilter . '%';
}

// Fetch tickets
$stmt = $db->prepare("SELECT t.*,
        COALESCE(e.full_name, ec.full_name, 'Unknown') AS emp_name,
        COALESCE(e.employee_code, ec.employee_id, '') AS emp_code
    FROM ess_helpdesk_tickets t
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN ess_employee_cache ec ON t.employee_id = CAST(ec.employee_id AS UNSIGNED)
    WHERE $whereClause
    ORDER BY
        CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
        t.created_at DESC
    LIMIT 100");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'open' => $db->query("SELECT COUNT(*) FROM ess_helpdesk_tickets WHERE status='open'")->fetchColumn() ?: 0,
    'progress' => $db->query("SELECT COUNT(*) FROM ess_helpdesk_tickets WHERE status='in_progress'")->fetchColumn() ?: 0,
    'resolved' => $db->query("SELECT COUNT(*) FROM ess_helpdesk_tickets WHERE status='resolved'")->fetchColumn() ?: 0,
    'closed' => $db->query("SELECT COUNT(*) FROM ess_helpdesk_tickets WHERE status='closed'")->fetchColumn() ?: 0,
];

$currentUserRole = $_SESSION['role_code'] ?? '';
$isAdmin = ($currentUserRole === 'admin');
$categories = ['hr' => 'HR', 'payroll' => 'Payroll', 'it' => 'IT Support', 'admin' => 'Admin', 'other' => 'Other'];
$statusColors = ['open' => 'danger', 'in_progress' => 'warning', 'resolved' => 'success', 'closed' => 'secondary'];
$priorityColors = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
$statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];

// Keep filter params in URL helper
$filterUrl = function ($overrides = []) use ($statusFilter, $categoryFilter, $priorityFilter, $searchFilter) {
    $params = [];
    if (!isset($overrides['status'])) { if ($statusFilter) $params['status'] = $statusFilter; }
    else { if ($overrides['status']) $params['status'] = $overrides['status']; }
    if (!isset($overrides['category'])) { if ($categoryFilter) $params['category'] = $categoryFilter; }
    else { if ($overrides['category']) $params['category'] = $overrides['category']; }
    if (!isset($overrides['priority'])) { if ($priorityFilter) $params['priority'] = $priorityFilter; }
    else { if ($overrides['priority']) $params['priority'] = $overrides['priority']; }
    if (!isset($overrides['search'])) { if ($searchFilter) $params['search'] = $searchFilter; }
    else { if ($overrides['search']) $params['search'] = $overrides['search']; }
    return 'index.php?page=helpdesk/list' . ($params ? '&' . http_build_query($params) : '');
};
?>
<div class="container-fluid py-3">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-headset me-2"></i>Helpdesk</h4>
            <small class="text-muted">Manage support tickets</small>
        </div>
        <a href="index.php?page=helpdesk/add" class="btn btn-primary btn-sm mt-2 mt-md-0">
            <i class="bi bi-plus-lg me-1"></i>New Ticket
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="row g-2 mb-3">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm bg-danger bg-opacity-10 h-100">
                <div class="card-body text-center py-2">
                    <h6 class="text-danger mb-1">Open</h6>
                    <h3 class="mb-0 text-danger"><?php echo number_format($stats['open']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm bg-warning bg-opacity-10 h-100">
                <div class="card-body text-center py-2">
                    <h6 class="text-warning mb-1">In Progress</h6>
                    <h3 class="mb-0 text-warning"><?php echo number_format($stats['progress']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm bg-success bg-opacity-10 h-100">
                <div class="card-body text-center py-2">
                    <h6 class="text-success mb-1">Resolved</h6>
                    <h3 class="mb-0 text-success"><?php echo number_format($stats['resolved']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm bg-secondary bg-opacity-10 h-100">
                <div class="card-body text-center py-2">
                    <h6 class="text-secondary mb-1">Closed</h6>
                    <h3 class="mb-0 text-secondary"><?php echo number_format($stats['closed']); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="helpdesk/list">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <?php foreach ($statusLabels as $v => $l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $statusFilter === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $v => $l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $categoryFilter === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Priority</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="">All Priorities</option>
                        <?php foreach (['low','medium','high','urgent'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $priorityFilter === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Subject / Ticket #" value="<?php echo htmlspecialchars($searchFilter); ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Status Filter Tabs -->
    <div class="d-flex flex-wrap gap-1 mb-3">
        <a href="<?php echo $filterUrl(['status' => '']); ?>" class="btn btn-sm <?php echo !$statusFilter ? 'btn-primary' : 'btn-outline-primary'; ?>">All (<?php echo array_sum($stats); ?>)</a>
        <a href="<?php echo $filterUrl(['status' => 'open']); ?>" class="btn btn-sm <?php echo $statusFilter === 'open' ? 'btn-danger' : 'btn-outline-danger'; ?>">Open (<?php echo $stats['open']; ?>)</a>
        <a href="<?php echo $filterUrl(['status' => 'in_progress']); ?>" class="btn btn-sm <?php echo $statusFilter === 'in_progress' ? 'btn-warning' : 'btn-outline-warning text-dark'; ?>">In Progress (<?php echo $stats['progress']; ?>)</a>
        <a href="<?php echo $filterUrl(['status' => 'resolved']); ?>" class="btn btn-sm <?php echo $statusFilter === 'resolved' ? 'btn-success' : 'btn-outline-success'; ?>">Resolved (<?php echo $stats['resolved']; ?>)</a>
        <a href="<?php echo $filterUrl(['status' => 'closed']); ?>" class="btn btn-sm <?php echo $statusFilter === 'closed' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">Closed (<?php echo $stats['closed']; ?>)</a>
    </div>

    <!-- Tickets Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ticket #</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Employee</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="bi bi-inbox d-block fs-1 text-muted mb-2"></i>
                                <span class="text-muted">No tickets found</span>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td>
                                <a href="index.php?page=helpdesk/add&id=<?php echo (int)$t['id']; ?>" class="text-decoration-none fw-bold">
                                    <?php echo htmlspecialchars($t['ticket_number'] ?: 'TKT-' . str_pad($t['id'], 5, '0', STR_PAD_LEFT)); ?>
                                </a>
                            </td>
                            <td>
                                <a href="index.php?page=helpdesk/add&id=<?php echo (int)$t['id']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($t['subject']); ?>
                                </a>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($categories[$t['category']] ?? ucfirst($t['category'])); ?></span></td>
                            <td>
                                <?php echo $t['emp_name'] !== 'Unknown' ? htmlspecialchars($t['emp_name']) . ' <small class="text-muted">(' . htmlspecialchars($t['emp_code']) . ')</small>' : '<span class="text-muted">-</span>'; ?>
                            </td>
                            <td><span class="badge bg-<?php echo $priorityColors[$t['priority']] ?? 'secondary'; ?>"><?php echo ucfirst(htmlspecialchars($t['priority'])); ?></span></td>
                            <td><span class="badge bg-<?php echo $statusColors[$t['status']] ?? 'secondary'; ?>"><?php echo htmlspecialchars($statusLabels[$t['status']] ?? $t['status']); ?></span></td>
                            <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></small></td>
                            <td>
                                <a href="index.php?page=helpdesk/add&id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-primary" title="View / Update">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Summary Footer -->
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
            <span class="text-muted small"><i class="bi bi-ticket-perforated me-1"></i>Showing: <strong><?php echo count($tickets); ?></strong> tickets</span>
            <span class="text-muted small"><i class="bi bi-flag me-1"></i>Total: <strong><?php echo array_sum($stats); ?></strong></span>
        </div>
    </div>
</div>
