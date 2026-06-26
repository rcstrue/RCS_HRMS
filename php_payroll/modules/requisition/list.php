<?php
/**
 * RCS HRMS Pro - Manpower Requisition List
 * Manpower Supplier - Client Staff Request Management
 */
$pageTitle = 'Manpower Requisitions';

$tablesExist = true;
try {
    $tablesExist = $db->tableExists('manpower_requisitions');
} catch (Exception $e) {
    $tablesExist = false;
}

// Filters
$status_filter = $_GET['status'] ?? '';
$client_filter = $_GET['client'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

$requisitions = [];
$clients = [];
$stats = ['pending' => 0, 'open_positions' => 0, 'urgent' => 0];

if ($tablesExist) {
    $where = "WHERE 1=1";
    $params = [];

    if ($status_filter) {
        $where .= " AND r.status = ?";
        $params[] = $status_filter;
    }

    if ($client_filter) {
        $where .= " AND r.client_id = ?";
        $params[] = $client_filter;
    }

    if ($priority_filter) {
        $where .= " AND r.priority = ?";
        $params[] = $priority_filter;
    }

    // Get requisitions
    $query = "SELECT r.*, 
              c.name as client_name, c.client_code,
              u.name as unit_name
              FROM manpower_requisitions r
              LEFT JOIN clients c ON r.client_id = c.id
              LEFT JOIN units u ON r.unit_id = u.id
              $where
              ORDER BY 
              FIELD(r.priority, 'urgent', 'high', 'normal', 'low'),
              r.required_by_date ASC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = [
        'pending' => $db->query("SELECT COUNT(*) FROM manpower_requisitions WHERE status IN ('pending', 'approved', 'in_progress')")->fetchColumn(),
        'open_positions' => $db->query("SELECT SUM(quantity - filled_quantity) FROM manpower_requisitions WHERE status IN ('pending', 'approved', 'in_progress')")->fetchColumn() ?: 0,
        'urgent' => $db->query("SELECT COUNT(*) FROM manpower_requisitions WHERE priority = 'urgent' AND status IN ('pending', 'approved', 'in_progress')")->fetchColumn()
    ];
}

// Get clients
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);


?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-person-plus me-2"></i>Manpower Requisitions
            </h1>
            <p class="text-muted">Client requests for manpower/staff</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=requisition/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Requisition
            </a>
        </div>
    </div>
</div>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Requisition Module Not Configured</strong><br>
    The required database table (<code>manpower_requisitions</code>) has not been created yet.
    Please run the requisition migration SQL to set up this module.
</div>
<?php else: ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Active Requisitions</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['pending']); ?></div>
                    </div>
                    <i class="bi bi-file-earmark-text fs-1 text-primary opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Open Positions</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['open_positions']); ?></div>
                    </div>
                    <i class="bi bi-person-badge fs-1 text-warning opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-start border-4 border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Urgent Requirements</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['urgent']); ?></div>
                    </div>
                    <i class="bi bi-exclamation-triangle fs-1 text-danger opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="requisition/list">
            <div class="col-md-3">
                <label class="form-label">Client</label>
                <select name="client" class="form-select">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>" <?php echo $client_filter == $client['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($client['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="partially_filled" <?php echo $status_filter == 'partially_filled' ? 'selected' : ''; ?>>Partially Filled</option>
                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="">All Priority</option>
                    <option value="urgent" <?php echo $priority_filter == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                    <option value="normal" <?php echo $priority_filter == 'normal' ? 'selected' : ''; ?>>Normal</option>
                    <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=requisition/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Requisitions Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="requisitionsTable">
                <thead>
                    <tr>
                        <th>Req #</th>
                        <th>Client</th>
                        <th>Position</th>
                        <th>Required By</th>
                        <th>Qty</th>
                        <th>Filled</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requisitions)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            No requisitions found. <a href="index.php?page=requisition/add">Create first requisition</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($requisitions as $req): ?>
                    <?php
                    $priority_class = match($req['priority']) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'normal' => 'info',
                        'low' => 'secondary',
                        default => 'secondary'
                    };
                    $status_class = match($req['status']) {
                        'pending' => 'secondary',
                        'approved' => 'info',
                        'in_progress' => 'primary',
                        'partially_filled' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'dark',
                        default => 'secondary'
                    };
                    $is_overdue = strtotime($req['required_by_date']) < strtotime('today') && !in_array($req['status'], ['completed', 'cancelled']);
                    ?>
                    <tr class="<?php echo $is_overdue ? 'table-danger' : ''; ?>">
                        <td>
                            <a href="index.php?page=requisition/view&id=<?php echo $req['id']; ?>">
                                <strong><?php echo sanitize($req['requisition_number']); ?></strong>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo sanitize($req['client_name']); ?></strong>
                            <div class="small text-muted"><?php echo sanitize($req['unit_name'] ?? 'All Units'); ?></div>
                        </td>
                        <td>
                            <strong><?php echo sanitize($req['designation']); ?></strong>
                            <div class="small text-muted">
                                <?php echo ucfirst($req['skill_category']); ?> / <?php echo ucfirst($req['worker_category']); ?>
                            </div>
                        </td>
                        <td>
                            <?php echo formatDate($req['required_by_date']); ?>
                            <?php if ($is_overdue): ?>
                            <span class="badge bg-danger ms-1">Overdue</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo $req['quantity']; ?></strong></td>
                        <td>
                            <?php echo $req['filled_quantity']; ?>
                            <?php 
                            $progress = $req['quantity'] > 0 ? round(($req['filled_quantity'] / $req['quantity']) * 100) : 0;
                            ?>
                            <div class="progress mt-1" style="height: 5px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $priority_class; ?>">
                                <?php echo ucfirst($req['priority']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=requisition/view&id=<?php echo $req['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (in_array($req['status'], ['pending', 'approved', 'in_progress'])): ?>
                                <a href="index.php?page=recruitment/add&requisition_id=<?php echo $req['id']; ?>" 
                                   class="btn btn-outline-success" title="Add Candidate">
                                    <i class="bi bi-person-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; // end tablesExist check ?>

<?php
$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    $('#requisitionsTable').DataTable({
        order: [[3, 'asc']],
        pageLength: 25
    });
});
</script>
JS;


?>
