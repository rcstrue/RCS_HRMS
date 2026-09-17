<?php
/**
 * RCS HRMS Pro - View Invoice
 */
$pageTitle = 'View Invoice';
$id = (int)($_GET['id'] ?? 0);

$invoice = null;
$items = [];
$payments = [];
$client = null;

if ($id) {
    try {
        $invoice = $db->fetch(
            "SELECT i.*, c.name as client_name, c.client_code, c.gst_number, c.address, c.city, c.state
             FROM invoices i
             LEFT JOIN clients c ON i.client_id = c.id
             WHERE i.id = ?", [$id]
        );

        if ($invoice) {
            $items = $db->fetchAll(
                "SELECT ii.*, e.employee_code, e.full_name as employee_name
                 FROM invoice_items ii
                 LEFT JOIN employees e ON ii.employee_id = e.id
                 WHERE ii.invoice_id = ? ORDER BY ii.id", [$id]
            );
            $payments = $db->fetchAll(
                "SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date DESC", [$id]
            );
            $client = $db->fetch("SELECT * FROM clients WHERE id = ?", [$invoice['client_id']]);
        }
    } catch (Exception $e) {
        // Table not found
    }
}

if (!$invoice) {
    echo '<div class="alert alert-danger">Invoice not found.</div>';
    return;
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=billing/list">Invoices</a></li>
                    <li class="breadcrumb-item active"><?php echo sanitize($invoice['invoice_number']); ?></li>
                </ol>
            </nav>
            <h1 class="page-title">
                <i class="bi bi-receipt me-2"></i>Invoice #<?php echo sanitize($invoice['invoice_number']); ?>
                <span class="badge bg-<?php echo $invoice['status'] == 'paid' ? 'success' : ($invoice['status'] == 'draft' ? 'secondary' : 'info'); ?> ms-2">
                    <?php echo ucfirst($invoice['status']); ?>
                </span>
            </h1>
        </div>
        <div class="col-auto">
            <a href="index.php?page=billing/print&id=<?php echo $id; ?>" class="btn btn-outline-primary" target="_blank">
                <i class="bi bi-printer me-1"></i>Print
            </a>
            <?php if ($invoice['status'] == 'draft'): ?>
            <a href="index.php?page=billing/edit&id=<?php echo $id; ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <?php endif; ?>
            <a href="index.php?page=billing/list" class="btn btn-outline-secondary">Back to List</a>
        </div>
    </div>
</div>

<!-- Invoice Details -->
<div class="row">
    <div class="col-lg-8">
        <!-- Client & Invoice Info -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2">Bill To</h6>
                        <h5><?php echo sanitize($invoice['client_name']); ?></h5>
                        <?php if (!empty($client['address'])): ?>
                        <p class="mb-1"><?php echo sanitize($client['address']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($client['city'])): ?>
                        <p class="mb-1"><?php echo sanitize($client['city']); ?>, <?php echo sanitize($client['state'] ?? ''); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($client['gst_number'])): ?>
                        <p class="mb-0"><strong>GSTIN:</strong> <?php echo sanitize($client['gst_number']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <h6 class="text-muted mb-2">Invoice Details</h6>
                        <p class="mb-1"><strong>Invoice:</strong> <?php echo sanitize($invoice['invoice_number']); ?></p>
                        <p class="mb-1"><strong>Date:</strong> <?php echo formatDate($invoice['invoice_date']); ?></p>
                        <p class="mb-1"><strong>Due Date:</strong> <?php echo formatDate($invoice['due_date']); ?></p>
                        <?php if (!empty($invoice['period_from'])): ?>
                        <p class="mb-1"><strong>Period:</strong> <?php echo formatDate($invoice['period_from']); ?> — <?php echo formatDate($invoice['period_to']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Line Items</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Description</th>
                                <th>Employee</th>
                                <th class="text-end">Days</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                            <tr><td colspan="6" class="text-center py-3 text-muted">No items</td></tr>
                            <?php else: ?>
                            <?php $sn = 1; foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td>
                                    <strong><?php echo sanitize($item['description']); ?></strong>
                                    <?php if (!empty($item['designation'])): ?>
                                    <div class="small text-muted"><?php echo sanitize($item['designation']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($item['employee_name'])): ?>
                                    <?php echo sanitize($item['employee_name']); ?>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo $item['days_worked']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['rate_per_day']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($item['amount']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payments -->
        <?php if (!empty($payments)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Payment History</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Mode</th>
                            <th>Reference</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td><?php echo formatDate($pay['payment_date']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $pay['payment_mode'])); ?></td>
                            <td><?php echo sanitize($pay['reference_number'] ?? '—'); ?></td>
                            <td class="text-end"><strong><?php echo formatCurrency($pay['amount']); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($invoice['notes'])): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h6>Notes</h6>
                <p class="mb-0"><?php echo sanitize($invoice['notes']); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Sidebar -->
    <div class="col-lg-4">
        <div class="card sticky-top" style="top:20px;">
            <div class="card-body">
                <h5 class="card-title mb-3">Summary</h5>
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Subtotal</td>
                        <td class="text-end"><?php echo formatCurrency($invoice['subtotal'] ?? $invoice['total_amount']); ?></td>
                    </tr>
                    <?php if (!empty($invoice['cgst_amount'])): ?>
                    <tr>
                        <td>CGST</td>
                        <td class="text-end"><?php echo formatCurrency($invoice['cgst_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($invoice['sgst_amount'])): ?>
                    <tr>
                        <td>SGST</td>
                        <td class="text-end"><?php echo formatCurrency($invoice['sgst_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($invoice['igst_amount'])): ?>
                    <tr>
                        <td>IGST</td>
                        <td class="text-end"><?php echo formatCurrency($invoice['igst_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-primary">
                        <th>Total</th>
                        <th class="text-end"><?php echo formatCurrency($invoice['total_amount']); ?></th>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
