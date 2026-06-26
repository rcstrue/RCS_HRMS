<?php
$pageTitle = 'Audit Trail';

$statusFilter = $_GET['status'] ?? '';
$moduleFilter = $_GET['module'] ?? '';
$userFilter = $_GET['user_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($moduleFilter) { $where .= " AND module = ?"; $params['module'] = $moduleFilter; }
if ($userFilter) { $where .= " AND user_id = ?"; $params['user_id'] = $userFilter; }
if ($dateFrom) { $where .= " AND DATE(created_at) >= ?"; $params['date_from'] = $dateFrom; }
if ($dateTo) { $where .= " AND DATE(created_at) <= ?"; $params['date_to'] = $dateTo; }

$stmt = $db->prepare("SELECT al.*, u.username, u.first_name, u.last_name
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        $where
        ORDER BY al.created_at DESC");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT COUNT(*) FROM audit_log al $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();

$modules = $db->query("SELECT DISTINCT module FROM audit_log WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$users = $db->query("SELECT DISTINCT u.id, u.username, u.first_name, u.last_name FROM users u INNER JOIN audit_log al ON al.user_id = u.id ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Audit Trail</h4>
            <button onclick="exportAuditLog()" class="btn btn-outline-primary"><i class="bi bi-download me-1"></i>Export</button>
        </div>
        
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="audit/list">
                    <div class="col-md-2">
                        <label class="form-label">Module</label>
                        <select name="module" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($modules as $m): ?><option value="<?php echo sanitize($m); ?>" <?php echo $moduleFilter === $m ? 'selected' : ''; ?>><?php echo ucfirst(sanitize($m)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">User</label>
                        <select name="user_id" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($users as $u): ?><option value="<?php echo $u['id']; ?>" <?php echo $userFilter == $u['id'] ? 'selected' : ''; ?>><?php echo sanitize($u['username']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="index.php?page=audit/list" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead><tr><th>Timestamp</th><th>User</th><th>Module</th><th>Action</th><th>IP</th><th>Details</th></tr></thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr><td colspan="6" class="text-center py-4">No audit records found</td></tr>
                            <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><small><?php echo formatDateTime($log['created_at']); ?></small></td>
                                <td><?php echo sanitize($log['username'] ?: 'System'); ?></td>
                                <td><span class="badge bg-secondary"><?php echo ucfirst(sanitize($log['module'])); ?></span></td>
                                <td><span class="badge bg-primary"><?php echo sanitize($log['action']); ?></span></td>
                                <td><small class="text-muted"><?php echo sanitize($log['ip_address']); ?></small></td>
                                <td>
                                    <?php if ($log['new_values']): ?>
                                    <button class="btn btn-sm btn-outline-info" onclick='showDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)'><i class="bi bi-eye"></i></button>
                                    <?php endif; ?>
                                </td>
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

<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="detailsContent"></div>
    </div></div>
</div>

<?php
$inlineJS = <<<'JS'
// Global functions for onclick handlers
window.showDetails = function(log) {
    document.getElementById('detailsContent').innerHTML = '<pre>' + JSON.stringify(log, null, 2) + '</pre>';
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
};
window.exportAuditLog = function() {
    window.location.href = 'index.php?page=audit/list&export=csv';
};
JS;
?>
