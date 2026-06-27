<?php
/**
 * RCS HRMS Pro - Edit Invoice
 */
$pageTitle = 'Edit Invoice';
$id = (int)($_GET['id'] ?? 0);
$errors = [];

$invoice = null;
$items = [];

if ($id) {
    try {
        $invoice = $db->fetch("SELECT * FROM invoices WHERE id = ?", [$id]);
        if ($invoice) {
            $items = $db->fetchAll(
                "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id", [$id]
            );
        }
    } catch (Exception $e) {
        // Table not found
    }
}

if (!$invoice) {
    echo '<div class="alert alert-danger">Invoice not found.</div>';
    return;
}

if ($invoice['status'] !== 'draft') {
    echo '<div class="alert alert-warning">Only draft invoices can be edited.</div>';
    return;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_date = sanitize($_POST['invoice_date']);
    $due_date = sanitize($_POST['due_date']);
    $notes = sanitize($_POST['notes'] ?? '');
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("UPDATE invoices SET invoice_date = ?, due_date = ?, notes = ? WHERE id = ?");
        $stmt->execute([$invoice_date, $due_date, $notes, $id]);
        
        $db->commit();
        setFlash('success', 'Invoice updated successfully');
        redirect("index.php?page=billing/view&id={$id}");
    } catch (Exception $e) {
        $db->rollBack();
        $errors[] = 'Error updating invoice: ' . $e->getMessage();
    }
}

$clients = $db->fetchAll("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name") ?: [];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=billing/list">Invoices</a></li>
                    <li class="breadcrumb-item"><a href="index.php?page=billing/view&id=<?php echo $id; ?>"><?php echo sanitize($invoice['invoice_number']); ?></a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
            <h1 class="page-title">Edit Invoice #<?php echo sanitize($invoice['invoice_number']); ?></h1>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo $e; ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST">
    <div class="row">
        <div class="col-lg-8">
            <!-- Invoice Details -->
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title mb-0">Invoice Details</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($invoice['client_name'] ?? 'Client #' . $invoice['client_id']); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Invoice Number</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($invoice['invoice_number']); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Invoice Date</label>
                            <input type="date" name="invoice_date" class="form-control" value="<?php echo htmlspecialchars($invoice['invoice_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo htmlspecialchars($invoice['due_date']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($invoice['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Items (read-only display) -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Line Items</h5>
                    <small class="text-muted">To modify items, delete and recreate this invoice.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th>Designation</th>
                                    <th class="text-end">Days</th>
                                    <th class="text-end">Rate/Day</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo sanitize($item['description']); ?></td>
                                    <td><?php echo sanitize($item['designation'] ?? '—'); ?></td>
                                    <td class="text-end"><?php echo $item['days_worked']; ?></td>
                                    <td class="text-end"><?php echo formatCurrency($item['rate_per_day']); ?></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($item['amount']); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top:20px;">
                <div class="card-body">
                    <h5 class="card-title mb-3">Summary</h5>
                    <table class="table table-sm mb-3">
                        <tr><td>Subtotal</td><td class="text-end"><?php echo formatCurrency($invoice['subtotal'] ?? 0); ?></td></tr>
                        <tr><td>CGST</td><td class="text-end"><?php echo formatCurrency($invoice['cgst_amount'] ?? 0); ?></td></tr>
                        <tr><td>SGST</td><td class="text-end"><?php echo formatCurrency($invoice['sgst_amount'] ?? 0); ?></td></tr>
                        <tr><td>IGST</td><td class="text-end"><?php echo formatCurrency($invoice['igst_amount'] ?? 0); ?></td></tr>
                        <tr class="table-primary"><th>Total</th><th class="text-end"><?php echo formatCurrency($invoice['total_amount']); ?></th></tr>
                    </table>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="index.php?page=billing/view&id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
