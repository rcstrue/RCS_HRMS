<?php
/**
 * RCS HRMS Pro - Contracts List Page
 */

$pageTitle = 'Contracts';

$statusFilter = $_GET['status'] ?? '';
$clientFilter = $_GET['client_id'] ?? '';
$searchQuery = $_GET['search'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($statusFilter !== '') {
    $where .= " AND c.is_active = :status";
    $params['status'] = $statusFilter === 'active' ? 1 : 0;
}
if ($clientFilter) {
    $where .= " AND c.client_id = :client_id";
    $params['client_id'] = $clientFilter;
}
if ($searchQuery) {
    $where .= " AND (c.contract_number LIKE :search OR cl.name LIKE :search)";
    $params['search'] = '%' . $searchQuery . '%';
}

$page = isset($_GET['pg']) ? max(1, (int)$_GET['pg']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$sql = "SELECT c.*, cl.name as client_name, u.name as unit_name
        FROM contracts c
        LEFT JOIN clients cl ON c.client_id = cl.id
        LEFT JOIN units u ON c.unit_id = u.id
        $where ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "SELECT COUNT(*) FROM contracts c LEFT JOIN clients cl ON c.client_id = cl.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalContracts = $stmt->fetchColumn();
$totalPages = ceil($totalContracts / $perPage);

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total' => $db->query("SELECT COUNT(*) FROM contracts")->fetchColumn() ?: 0,
    'active' => $db->query("SELECT COUNT(*) FROM contracts WHERE is_active = 1")->fetchColumn() ?: 0,
    'expiring' => $db->query("SELECT COUNT(*) FROM contracts WHERE is_active = 1 AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn() ?: 0,
];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Contracts</h4>
            <a href="index.php?page=contract/add" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Contract</a>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body"><h6>Total Contracts</h6><h3><?php echo $stats['total']; ?></h3></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body"><h6>Active</h6><h3><?php echo $stats['active']; ?></h3></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-warning text-dark">
                    <div class="card-body"><h6>Expiring Soon</h6><h3><?php echo $stats['expiring']; ?></h3></div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="contract/list">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?php echo $cl['id']; ?>" <?php echo $clientFilter == $cl['id'] ? 'selected' : ''; ?>><?php echo sanitize($cl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Search</button>
                        <a href="index.php?page=contract/list" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Contract #</th><th>Client</th><th>Type</th><th>Period</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($contracts)): ?>
                            <tr><td colspan="6" class="text-center py-4">No contracts found</td></tr>
                            <?php else: ?>
                            <?php foreach ($contracts as $c): ?>
                            <tr>
                                <td><a href="index.php?page=contract/add&id=<?php echo $c['id']; ?>"><strong><?php echo sanitize($c['contract_number']); ?></strong></a></td>
                                <td><?php echo sanitize($c['client_name']); ?></td>
                                <td><?php echo ucfirst($c['contract_type'] ?? 'manpower'); ?></td>
                                <td><?php echo formatDate($c['start_date']); ?> - <?php echo $c['end_date'] ? formatDate($c['end_date']) : 'Ongoing'; ?></td>
                                <td><span class="badge bg-<?php echo $c['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $c['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td><a href="index.php?page=contract/add&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
