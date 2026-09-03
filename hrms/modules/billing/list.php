<?php
/**
 * RCS HRMS Pro - Invoice List
 * Manpower Supplier - Client Billing Management
 * v2 — graceful handling for missing invoice tables
 */
$pageTitle = 'Invoices';

// Graceful handling — tables may not exist yet
try {
    // Filters
    $status_filter = $_GET['status'] ?? '';
    $client_filter = $_GET['client'] ?? '';
    $month_filter = $_GET['month'] ?? date('m');
    $year_filter = $_GET['year'] ?? date('Y');

    // Build query
    $where = "WHERE 1=1";
    $params = [];

    if ($status_filter) {
        $where .= " AND i.status = ?";
        $params[] = $status_filter;
    }

    if ($client_filter) {
        $where .= " AND i.client_id = ?";
        $params[] = $client_filter;
    }

    if ($month_filter && $year_filter) {
        $where .= " AND MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?";
        $params[] = $month_filter;
        $params[] = $year_filter;
    }

    // Get invoices
    $query = "SELECT i.*, c.name as client_name, c.client_code,
              (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count,
              (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = i.id) as paid_amount
              FROM invoices i
              LEFT JOIN clients c ON i.client_id = c.id
              $where
              ORDER BY i.invoice_date DESC, i.id DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get clients for filter
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $totals = [
        'total_amount' => 0,
        'paid_amount' => 0,
        'pending_amount' => 0
    ];

    foreach ($invoices as $inv) {
        $totals['total_amount'] += $inv['total_amount'];
        $totals['paid_amount'] += $inv['paid_amount'];
        if ($inv['status'] !== 'cancelled') {
            $totals['pending_amount'] += ($inv['total_amount'] - $inv['paid_amount']);
        }
    }

    $tablesExist = true;
} catch (Exception $e) {
    $invoices = [];
    $clients = [];
    $totals = ['total_amount' => 0, 'paid_amount' => 0, 'pending_amount' => 0];
    $tablesExist = false;
}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-receipt me-2"></i>Client Invoices
            </h1>
            <p class="text-muted">Manage client billing and invoices for deployed manpower</p>
        </div>
        <?php if ($tablesExist): ?>
        <div class="col-auto">
            <a href="index.php?page=billing/create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Invoice
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Billing Module Not Configured</strong><br>
    The required database tables (<code>invoices</code>, <code>invoice_items</code>, <code>invoice_payments</code>) have not been created yet.
    Please run the billing migration SQL to set up this module.
</div>
<?php else: ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Billed</div>
                        <div class="h4 mb-0"><?php echo formatCurrency($totals['total_amount']); ?></div>
                    </div>
                    <i class="bi bi-currency-rupee fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Amount Received</div>
                        <div class="h4 mb-0"><?php echo formatCurrency($totals['paid_amount']); ?></div>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Pending Amount</div>
                        <div class="h4 mb-0"><?php echo formatCurrency($totals['pending_amount']); ?></div>
                    </div>
                    <i class="bi bi-clock fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="billing/list">
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
                    <option value="sent" <?php echo $status_filter == 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=billing/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Invoices Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="invoicesTable">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Invoice Date</th>
                        <th>Period</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr class="odd">
                        <td colspan="8" class="text-center py-4 text-muted" data-tables-empty="1">
                            No invoices found. <a href="index.php?page=billing/create">Create your first invoice</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $status_class = match($invoice['status']) {
                        'draft' => 'secondary',
                        'sent' => 'info',
                        'paid' => 'success',
                        'partial' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'dark',
                        default => 'secondary'
                    };
                    $balance = $invoice['total_amount'] - $invoice['paid_amount'];
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=billing/view&id=<?php echo $invoice['id']; ?>">
                                <strong><?php echo sanitize($invoice['invoice_number']); ?></strong>
                            </a>
                            <div class="small text-muted"><?php echo $invoice['item_count']; ?> items</div>
                        </td>
                        <td>
                            <strong><?php echo sanitize($invoice['client_name']); ?></strong>
                            <div class="small text-muted"><?php echo sanitize($invoice['client_code']); ?></div>
                        </td>
                        <td>
                            <?php echo formatDate($invoice['invoice_date']); ?>
                            <div class="small text-muted">Due: <?php echo formatDate($invoice['due_date']); ?></div>
                        </td>
                        <td>
                            <?php echo formatDate($invoice['period_from']); ?> - 
                            <?php echo formatDate($invoice['period_to']); ?>
                        </td>
                        <td><strong><?php echo formatCurrency($invoice['total_amount']); ?></strong></td>
                        <td>
                            <?php echo formatCurrency($invoice['paid_amount']); ?>
                            <?php if ($balance > 0 && $invoice['status'] !== 'cancelled'): ?>
                            <div class="small text-danger">Bal: <?php echo formatCurrency($balance); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo ucfirst($invoice['status']); ?></span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=billing/view&id=<?php echo $invoice['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?page=billing/print&id=<?php echo $invoice['id']; ?>" 
                                   class="btn btn-outline-secondary" title="Print" target="_blank">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <?php if ($invoice['status'] == 'draft'): ?>
                                <a href="index.php?page=billing/edit&id=<?php echo $invoice['id']; ?>" 
                                   class="btn btn-outline-info" title="Edit">
                                    <i class="bi bi-pencil"></i>
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
$extraJS = <<<JS
<script>
$(document).ready(function() {
    if ($('#invoicesTable tbody tr').length > 0) {
        $('#invoicesTable').DataTable({
            order: [[2, 'desc']],
            pageLength: 25,
            responsive: true
        });
    }
});
</script>
JS;
?>

<?php endif; // end tablesExist check ?>
