<?php
/**
 * RCS HRMS Pro - Deployment List
 * Manpower Supplier - Employee Deployment/Placement Tracking
 */
$pageTitle = 'Employee Deployments';

// Filters
$status_filter = $_GET['status'] ?? 'active';
$client_filter = $_GET['client'] ?? '';
$unit_filter = $_GET['unit'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($status_filter) {
    $where .= " AND d.status = ?";
    $params[] = $status_filter;
}

if ($client_filter) {
    $where .= " AND d.client_id = ?";
    $params[] = $client_filter;
}

if ($unit_filter) {
    $where .= " AND d.unit_id = ?";
    $params[] = $unit_filter;
}

// Get deployments
$query = "SELECT d.*, 
          e.full_name, e.employee_code, e.mobile_number,
          c.name as client_name, c.client_code,
          u.name as unit_name, u.unit_code
          FROM employee_deployments d
          LEFT JOIN employees e ON d.employee_id = e.id
          LEFT JOIN clients c ON d.client_id = c.id
          LEFT JOIN units u ON d.unit_id = u.id
          $where
          ORDER BY d.deployment_date DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$deployments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients and units for filters
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'active' => $db->query("SELECT COUNT(*) FROM employee_deployments WHERE status = 'active'")->fetchColumn(),
    'ended' => $db->query("SELECT COUNT(*) FROM employee_deployments WHERE status = 'ended'")->fetchColumn(),
    'transferred' => $db->query("SELECT COUNT(*) FROM employee_deployments WHERE status = 'transferred'")->fetchColumn()
];


?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-geo-alt me-2"></i>Employee Deployments
            </h1>
            <p class="text-muted">Track employee placements at client locations</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=deployment/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Deployment
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Active Deployments</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['active']); ?></div>
                    </div>
                    <i class="bi bi-people fs-1 text-success opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-start border-4 border-secondary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Ended Deployments</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['ended']); ?></div>
                    </div>
                    <i class="bi bi-x-circle fs-1 text-secondary opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-start border-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Transferred</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['transferred']); ?></div>
                    </div>
                    <i class="bi bi-arrow-left-right fs-1 text-info opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="deployment/list">
            <div class="col-md-3">
                <label class="form-label">Client</label>
                <select name="client" class="form-select" id="filterClient">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>" <?php echo $client_filter == $client['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($client['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Unit</label>
                <select name="unit" class="form-select" id="filterUnit">
                    <option value="">All Units</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="ended" <?php echo $status_filter == 'ended' ? 'selected' : ''; ?>>Ended</option>
                    <option value="transferred" <?php echo $status_filter == 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                    <option value="replaced" <?php echo $status_filter == 'replaced' ? 'selected' : ''; ?>>Replaced</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=deployment/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Deployments Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="deploymentsTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Client / Unit</th>
                        <th>Designation</th>
                        <th>Deployment Date</th>
                        <th>Billing Rate</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deployments)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            No deployments found. <a href="index.php?page=deployment/add">Create first deployment</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($deployments as $dep): ?>
                    <?php
                    $status_class = match($dep['status']) {
                        'active' => 'success',
                        'ended' => 'secondary',
                        'transferred' => 'info',
                        'replaced' => 'warning',
                        default => 'secondary'
                    };
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=employee/view&id=<?php echo $dep['employee_id']; ?>">
                                <strong><?php echo sanitize($dep['full_name']); ?></strong>
                            </a>
                            <div class="small text-muted">
                                <?php echo sanitize($dep['employee_code']); ?> | <?php echo sanitize($dep['mobile_number']); ?>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo sanitize($dep['client_name']); ?></strong>
                            <div class="small text-muted">
                                <?php echo sanitize($dep['unit_name'] ?? 'All Units'); ?>
                            </div>
                        </td>
                        <td><?php echo sanitize($dep['designation']); ?></td>
                        <td>
                            <?php echo formatDate($dep['deployment_date']); ?>
                            <?php if ($dep['end_date']): ?>
                            <div class="small text-muted">End: <?php echo formatDate($dep['end_date']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo formatCurrency($dep['billing_rate']); ?>
                            <div class="small text-muted"><?php echo ucfirst($dep['billing_type']); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo ucfirst($dep['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=deployment/view&id=<?php echo $dep['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($dep['status'] == 'active'): ?>
                                <a href="index.php?page=deployment/end&id=<?php echo $dep['id']; ?>" 
                                   class="btn btn-outline-warning" title="End Deployment">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                                <a href="index.php?page=deployment/transfer&id=<?php echo $dep['id']; ?>" 
                                   class="btn btn-outline-info" title="Transfer">
                                    <i class="bi bi-arrow-left-right"></i>
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

<?php
$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    $('#deploymentsTable').DataTable({
        order: [[3, 'desc']],
        pageLength: 25
    });
    
    // Load units on client change
    $('#filterClient').change(function() {
        const clientId = $(this).val();
        if (clientId) {
            $.get(`index.php?page=api/units&client_id=${clientId}`, function(data) {
                let options = '<option value="">All Units</option>';
                data.forEach(unit => {
                    options += `<option value="${unit.id}">${unit.name}</option>`;
                });
                $('#filterUnit').html(options);
            });
        }
    });
});
</script>
JS;


?>
