<?php
/**
 * RCS HRMS Pro - Client Timesheets
 * Manpower Supplier - Timesheet Management
 */
$pageTitle = 'Client Timesheets';

// ── Self-heal: ensure table exists ──
try { $db->exec("CREATE TABLE IF NOT EXISTS `client_timesheets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED DEFAULT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `invoice_id` INT UNSIGNED DEFAULT NULL,
    `period_from` DATE DEFAULT NULL,
    `period_to` DATE DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'draft',
    `total_manpower` DECIMAL(10,2) DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `remarks` TEXT DEFAULT NULL,
    `created_by` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_period` (`period_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (\Throwable $e) {}

// Filters
$status_filter = $_GET['status'] ?? '';
$client_filter = $_GET['client'] ?? '';
$month_filter = $_GET['month'] ?? date('m');
$year_filter = $_GET['year'] ?? date('Y');

$where = "WHERE 1=1";
$params = [];

if ($status_filter) {
    $where .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($client_filter) {
    $where .= " AND t.client_id = ?";
    $params[] = $client_filter;
}

if ($month_filter && $year_filter) {
    $where .= " AND MONTH(t.period_from) = ? AND YEAR(t.period_from) = ?";
    $params[] = $month_filter;
    $params[] = $year_filter;
}

// Get timesheets
$timesheets = [];
try {
    $query = "SELECT t.*, 
              c.name as client_name, c.client_code,
              u.name as unit_name,
              i.invoice_number
              FROM client_timesheets t
              LEFT JOIN clients c ON t.client_id = c.id
              LEFT JOIN units u ON t.unit_id = u.id
              LEFT JOIN invoices i ON t.invoice_id = i.id
              $where
              ORDER BY t.period_from DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[timesheet/list] Query failed: ' . $e->getMessage());
}

// Get clients
$clients = [];
try { $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) {}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-table me-2"></i>Client Timesheets
            </h1>
            <p class="text-muted">Manage client-wise timesheets for billing</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=timesheet/create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Timesheet
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="timesheet/list">
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
            <div class="col-md-2">
                <label class="form-label">Month</label>
                <select name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $month_filter == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Year</label>
                <select name="year" class="form-select">
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="submitted" <?php echo $status_filter == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="invoiced" <?php echo $status_filter == 'invoiced' ? 'selected' : ''; ?>>Invoiced</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=timesheet/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Timesheets Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="timesheetsTable">
                <thead>
                    <tr>
                        <th>Timesheet #</th>
                        <th>Client</th>
                        <th>Period</th>
                        <th>Employees</th>
                        <th>Man Days</th>
                        <th>OT Hours</th>
                        <th>Status</th>
                        <th>Invoice</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($timesheets)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            No timesheets found. <a href="index.php?page=timesheet/create">Create first timesheet</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($timesheets as $ts): ?>
                    <?php
                    $status_class = match($ts['status']) {
                        'draft' => 'secondary',
                        'submitted' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'invoiced' => 'primary',
                        default => 'secondary'
                    };
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=timesheet/view&id=<?php echo $ts['id']; ?>">
                                <strong><?php echo sanitize($ts['timesheet_number']); ?></strong>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo sanitize($ts['client_name']); ?></strong>
                            <div class="small text-muted"><?php echo sanitize($ts['unit_name'] ?? 'All Units'); ?></div>
                        </td>
                        <td>
                            <?php echo formatDate($ts['period_from']); ?> - 
                            <?php echo formatDate($ts['period_to']); ?>
                        </td>
                        <td><?php echo $ts['total_employees']; ?></td>
                        <td><?php echo $ts['total_man_days']; ?></td>
                        <td><?php echo $ts['total_overtime_hours']; ?></td>
                        <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo ucfirst($ts['status']); ?>
                            </span>
                            <?php if ($ts['client_approval_status'] == 'approved'): ?>
                            <span class="badge bg-success">Client Approved</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ts['invoice_number']): ?>
                            <a href="index.php?page=billing/view&id=<?php echo $ts['invoice_id']; ?>">
                                <?php echo sanitize($ts['invoice_number']); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=timesheet/view&id=<?php echo $ts['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($ts['status'] == 'draft'): ?>
                                <a href="index.php?page=timesheet/edit&id=<?php echo $ts['id']; ?>" 
                                   class="btn btn-outline-info" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="index.php?page=timesheet/submit&id=<?php echo $ts['id']; ?>" 
                                   class="btn btn-outline-success" title="Submit">
                                    <i class="bi bi-send"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($ts['status'] == 'approved' && !$ts['invoice_id']): ?>
                                <a href="index.php?page=billing/create&timesheet_id=<?php echo $ts['id']; ?>" 
                                   class="btn btn-outline-primary" title="Generate Invoice">
                                    <i class="bi bi-receipt"></i>
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
    $('#timesheetsTable').DataTable({
        order: [[2, 'desc']],
        pageLength: 25
    });
});
</script>
JS;
?>
