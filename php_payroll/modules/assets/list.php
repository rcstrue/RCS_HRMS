<?php
/**
 * RCS HRMS Pro - Asset List
 * Manpower Supplier - Asset/Equipment Management
 */
$pageTitle = 'Assets';

$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    $('#assetsTable').DataTable({
        order: [[1, 'asc']],
        pageLength: 25
    });
});
</script>
JS;

// Check if assets table exists
$assetsTableExists = $db->tableExists('assets');
$assets = [];

if ($assetsTableExists) {
    // Filters
    $type_filter = $_GET['type'] ?? '';
    $status_filter = $_GET['status'] ?? '';

    $where = "WHERE 1=1";
    $params = [];

    if ($type_filter) {
        $where .= " AND a.asset_type = ?";
        $params[] = $type_filter;
    }

    try {
        // Get assets
        $query = "SELECT a.*, 
                  (SELECT COUNT(*) FROM employee_assets WHERE asset_id = a.id AND status = 'issued') as issued_count
                  FROM assets a
                  $where
                  ORDER BY a.asset_name";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $assets = [];
    }
} else {
    $type_filter = '';
    $status_filter = '';
}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-box-seam me-2"></i>Assets & Equipment
            </h1>
            <p class="text-muted">Manage uniforms, tools, equipment issued to employees</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=assets/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Asset
            </a>
            <a href="index.php?page=assets/issue" class="btn btn-success">
                <i class="bi bi-arrow-up-circle me-1"></i>Issue Asset
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="assets/list">
            <div class="col-md-4">
                <label class="form-label">Asset Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="uniform" <?php echo $type_filter == 'uniform' ? 'selected' : ''; ?>>Uniform</option>
                    <option value="safety_equipment" <?php echo $type_filter == 'safety_equipment' ? 'selected' : ''; ?>>Safety Equipment</option>
                    <option value="tools" <?php echo $type_filter == 'tools' ? 'selected' : ''; ?>>Tools</option>
                    <option value="electronics" <?php echo $type_filter == 'electronics' ? 'selected' : ''; ?>>Electronics</option>
                    <option value="documents" <?php echo $type_filter == 'documents' ? 'selected' : ''; ?>>Documents</option>
                    <option value="keys" <?php echo $type_filter == 'keys' ? 'selected' : ''; ?>>Keys</option>
                    <option value="id_card" <?php echo $type_filter == 'id_card' ? 'selected' : ''; ?>>ID Card</option>
                    <option value="other" <?php echo $type_filter == 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=assets/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if (!$assetsTableExists): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Assets Module Not Configured</strong><br>
    The required database table (<code>assets</code>) has not been created yet. 
    Please run the <code>install/migration_settlement_assets.sql</code> migration to set up this module.
</div>
<?php endif; ?>

<!-- Assets Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="assetsTable">
                <thead>
                    <tr>
                        <th>Asset Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Serial No.</th>
                        <th>Total Qty</th>
                        <th>Available</th>
                        <th>Issued</th>
                        <th>Returnable</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assets)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            No assets found. <a href="index.php?page=assets/add">Add first asset</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($assets as $asset): ?>
                    <?php
                    $type_class = match($asset['asset_type']) {
                        'uniform' => 'primary',
                        'safety_equipment' => 'warning',
                        'tools' => 'info',
                        'electronics' => 'secondary',
                        'documents' => 'dark',
                        'keys' => 'success',
                        'id_card' => 'danger',
                        default => 'secondary'
                    };
                    ?>
                    <tr>
                        <td><strong><?php echo sanitize($asset['asset_code']); ?></strong></td>
                        <td>
                            <?php echo sanitize($asset['asset_name']); ?>
                            <?php if ($asset['description']): ?>
                            <div class="small text-muted"><?php echo sanitize(substr($asset['description'], 0, 50)); ?>...</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $type_class; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $asset['asset_type'])); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($asset['serial_number'] ?? '-'); ?></td>
                        <td><?php echo $asset['quantity']; ?></td>
                        <td>
                            <span class="<?php echo $asset['available_quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $asset['available_quantity']; ?>
                            </span>
                        </td>
                        <td><?php echo $asset['issued_count']; ?></td>
                        <td>
                            <?php if ($asset['is_returnable']): ?>
                            <i class="bi bi-check-circle text-success"></i> Yes
                            <?php else: ?>
                            <i class="bi bi-x-circle text-secondary"></i> No
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=assets/view&id=<?php echo $asset['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?page=assets/edit&id=<?php echo $asset['id']; ?>" 
                                   class="btn btn-outline-info" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($asset['available_quantity'] > 0): ?>
                                <a href="index.php?page=assets/issue&asset_id=<?php echo $asset['id']; ?>" 
                                   class="btn btn-outline-success" title="Issue">
                                    <i class="bi bi-arrow-up-circle"></i>
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
