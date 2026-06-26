<?php
/**
 * RCS HRMS Pro - GST Invoice Generator
 * Create GST-compliant invoices for client billing
 */

$pageTitle = 'GST Invoice';

// Graceful handling — billing tables may not exist yet
$tablesExist = false;
$invoices = [];
$clients = [];
$company = ['state' => 'Gujarat'];

try {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1") ?: ['state' => 'Gujarat'];
    $clients = $db->fetchAll("SELECT id, name as client_name, gst_number, address, city, state FROM clients WHERE is_active = 1 ORDER BY name");
    $invoices = $db->fetchAll(
        "SELECT i.*, c.name as client_name, c.gst_number
         FROM invoices i
         LEFT JOIN clients c ON i.client_id = c.id
         ORDER BY i.created_at DESC
         LIMIT 50"
    );
    $tablesExist = true;
} catch (Exception $e) {
    $tablesExist = false;
}

// Handle form submission (only if tables exist)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $data = [
            'invoice_number' => generateInvoiceNumber(),
            'client_id' => (int)$_POST['client_id'],
            'invoice_date' => sanitize($_POST['invoice_date']),
            'due_date' => sanitize($_POST['due_date']),
            'month' => (int)$_POST['month'],
            'year' => (int)$_POST['year'],
            'service_type' => sanitize($_POST['service_type']),
            'sac_code' => sanitize($_POST['sac_code'] ?? '998511'),
            'place_of_supply' => sanitize($_POST['place_of_supply']),
            'billing_type' => sanitize($_POST['billing_type']),
            'cgst_rate' => floatval($_POST['cgst_rate'] ?? 9),
            'sgst_rate' => floatval($_POST['sgst_rate'] ?? 9),
            'igst_rate' => floatval($_POST['igst_rate'] ?? 0),
            'notes' => sanitize($_POST['notes'] ?? ''),
            'status' => 'draft',
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Calculate billing amount
        $billingType = $data['billing_type'];
        $clientId = $data['client_id'];
        $month = $data['month'];
        $year = $data['year'];
        
        // Get manpower count and rate
        $billingItems = [];
        $totalBeforeTax = 0;
        
        if ($billingType === 'manpower') {
            $employees = $db->fetchAll(
                "SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
                        ess.gross_salary, rc.billing_rate_per_month, rc.billing_rate_per_day
                 FROM employees e
                 LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 LEFT JOIN client_rate_cards rc ON e.client_id = rc.client_id 
                    AND (e.worker_category = rc.worker_category OR rc.worker_category IS NULL)
                    AND rc.is_active = 1
                 WHERE e.client_id = :client_id AND e.status = 'approved'",
                ['client_id' => $clientId]
            );
            
            foreach ($employees as $emp) {
                $billRate = floatval($emp['billing_rate_per_month'] ?? $emp['billing_rate_per_day'] * 26 ?? $emp['gross_salary'] ?? 0);
                $billAmount = $billRate;
                
                $billingItems[] = [
                    'description' => $emp['designation'] ?? 'Manpower',
                    'category' => $emp['worker_category'] ?? 'General',
                    'count' => 1,
                    'rate' => $billRate,
                    'amount' => $billAmount
                ];
                $totalBeforeTax += $billAmount;
            }
        } else {
            $itemCount = count($_POST['item_description'] ?? []);
            for ($i = 0; $i < $itemCount; $i++) {
                if (!empty($_POST['item_description'][$i])) {
                    $itemAmount = floatval($_POST['item_rate'][$i]) * intval($_POST['item_qty'][$i]);
                    $billingItems[] = [
                        'description' => sanitize($_POST['item_description'][$i]),
                        'category' => sanitize($_POST['item_category'][$i] ?? ''),
                        'count' => intval($_POST['item_qty'][$i]),
                        'rate' => floatval($_POST['item_rate'][$i]),
                        'amount' => $itemAmount
                    ];
                    $totalBeforeTax += $itemAmount;
                }
            }
        }
        
        // Calculate taxes
        $cgst = $totalBeforeTax * ($data['cgst_rate'] / 100);
        $sgst = $totalBeforeTax * ($data['sgst_rate'] / 100);
        $igst = $totalBeforeTax * ($data['igst_rate'] / 100);
        $totalTax = $cgst + $sgst + $igst;
        $grandTotal = $totalBeforeTax + $totalTax;
        
        $data['subtotal'] = $totalBeforeTax;
        $data['cgst_amount'] = $cgst;
        $data['sgst_amount'] = $sgst;
        $data['igst_amount'] = $igst;
        $data['total_amount'] = $grandTotal;
        
        try {
            $invoiceId = $db->insert('invoices', $data);
            
            foreach ($billingItems as $item) {
                $db->insert('invoice_items', [
                    'invoice_id' => $invoiceId,
                    'description' => $item['description'],
                    'category' => $item['category'],
                    'quantity' => $item['count'],
                    'rate_per_day' => $item['rate'],
                    'amount' => $item['amount']
                ]);
            }
            
            setFlash('success', 'Invoice created successfully! Invoice #: ' . $data['invoice_number']);
            redirect('index.php?page=billing/gst-invoice&action=view&id=' . $invoiceId);
        } catch (Exception $e) {
            setFlash('error', 'Error creating invoice: ' . $e->getMessage());
        }
    }
}

// Helper function
function generateInvoiceNumber() {
    global $db;
    $prefix = 'INV';
    $year = date('Y');
    
    $lastInvoice = $db->fetchColumn(
        "SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1",
        [$prefix . $year . '%']
    );
    
    if ($lastInvoice) {
        $lastNum = (int)substr($lastInvoice, -5);
        return $prefix . $year . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);
    }
    
    return $prefix . $year . '00001';
}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-receipt me-2"></i>GST Invoice Generator
            </h1>
            <p class="text-muted">Create GST-compliant invoices for client billing</p>
        </div>
        <?php if ($tablesExist): ?>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createInvoiceModal">
                <i class="bi bi-plus-lg me-1"></i>Create Invoice
            </button>
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
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Invoices</div>
                        <div class="h3 mb-0"><?php echo count($invoices); ?></div>
                    </div>
                    <i class="bi bi-file-text fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Paid</div>
                        <div class="h3 mb-0">
                            <?php 
                            $paid = array_filter($invoices, fn($i) => $i['status'] == 'paid');
                            echo count($paid);
                            ?>
                        </div>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Pending</div>
                        <div class="h3 mb-0">
                            <?php 
                            $pending = array_filter($invoices, fn($i) => in_array($i['status'], ['draft', 'sent']));
                            echo count($pending);
                            ?>
                        </div>
                    </div>
                    <i class="bi bi-clock fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Amount</div>
                        <div class="h4 mb-0">
                            <?php 
                            $total = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] == 'paid'), 'total_amount'));
                            echo formatCurrency($total);
                            ?>
                        </div>
                    </div>
                    <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Invoices Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>Recent Invoices</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="invoiceTable">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th>Period</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end">GST</th>
                                <th class="text-end">Total</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    No invoices yet. Click "Create Invoice" to get started.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><code><?php echo sanitize($inv['invoice_number']); ?></code></td>
                                <td>
                                    <div><?php echo sanitize($inv['client_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($inv['gst_number'] ?? ''); ?></small>
                                </td>
                                <td><?php echo formatDate($inv['invoice_date']); ?></td>
                                <td><?php echo date('F Y', mktime(0, 0, 0, $inv['month'], 1, $inv['year'])); ?></td>
                                <td class="text-end"><?php echo formatCurrency($inv['subtotal']); ?></td>
                                <td class="text-end text-muted">
                                    <?php echo formatCurrency($inv['cgst_amount'] + $inv['sgst_amount'] + $inv['igst_amount']); ?>
                                </td>
                                <td class="text-end"><strong><?php echo formatCurrency($inv['total_amount']); ?></strong></td>
                                <td>
                                    <?php
                                    $statusColors = ['draft' => 'secondary', 'sent' => 'info', 'paid' => 'success', 'cancelled' => 'danger'];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$inv['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($inv['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="index.php?page=billing/gst-invoice&action=view&id=<?php echo $inv['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="View/Print">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="index.php?page=billing/gst-invoice&action=print&id=<?php echo $inv['id']; ?>" 
                                       class="btn btn-sm btn-outline-success" title="Print" target="_blank">
                                        <i class="bi bi-printer"></i>
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
    </div>
</div>

<!-- Create Invoice Modal -->
<div class="modal fade" id="createInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create GST Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Client</label>
                            <select class="form-select select2" name="client_id" required id="clientSelect">
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>" 
                                        data-gst="<?php echo sanitize($c['gst_number']); ?>"
                                        data-state="<?php echo sanitize($c['state']); ?>">
                                    <?php echo sanitize($c['client_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required">Invoice Date</label>
                            <input type="date" class="form-control" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required">Due Date</label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="year">
                                <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Service Type</label>
                            <select class="form-select" name="service_type">
                                <option value="Manpower Supply">Manpower Supply</option>
                                <option value="Security Services">Security Services</option>
                                <option value="Housekeeping">Housekeeping</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">SAC Code</label>
                            <input type="text" class="form-control" name="sac_code" value="998511">
                            <small class="text-muted">Manpower supply services</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Place of Supply</label>
                            <select class="form-select" name="place_of_supply" id="placeOfSupply">
                                <option value="Gujarat">Gujarat</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Rajasthan">Rajasthan</option>
                                <option value="Madhya Pradesh">Madhya Pradesh</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Billing Type</label>
                            <select class="form-select" name="billing_type" id="billingType">
                                <option value="manpower">Auto (From Deployed Manpower)</option>
                                <option value="manual">Manual Items</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Company State</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($company['state'] ?? 'Gujarat'); ?>" readonly>
                        </div>
                        
                        <!-- Tax Rates -->
                        <div class="col-12">
                            <h6 class="text-muted mt-3">Tax Rates (%)</h6>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">CGST %</label>
                            <input type="number" class="form-control" name="cgst_rate" value="9" step="0.01" id="cgstRate">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">SGST %</label>
                            <input type="number" class="form-control" name="sgst_rate" value="9" step="0.01" id="sgstRate">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">IGST %</label>
                            <input type="number" class="form-control" name="igst_rate" value="0" step="0.01" id="igstRate">
                        </div>
                        
                        <!-- Manual Items (hidden by default) -->
                        <div class="col-12" id="manualItemsSection" style="display: none;">
                            <h6 class="text-muted mt-3">Billing Items</h6>
                            <div id="billingItems">
                                <div class="row g-2 mb-2 billing-item">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" name="item_description[]" placeholder="Description">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control" name="item_category[]" placeholder="Category">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control" name="item_qty[]" placeholder="Qty" value="1">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control" name="item_rate[]" placeholder="Rate">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-danger" onclick="$(this).closest('.billing-item').remove()">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBillingItem()">
                                <i class="bi bi-plus me-1"></i>Add Item
                            </button>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Any additional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Create Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle billing type
$('#billingType').on('change', function() {
    if ($(this).val() === 'manual') {
        $('#manualItemsSection').show();
    } else {
        $('#manualItemsSection').hide();
    }
});

// Add billing item
function addBillingItem() {
    const html = `
        <div class="row g-2 mb-2 billing-item">
            <div class="col-md-5">
                <input type="text" class="form-control" name="item_description[]" placeholder="Description">
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control" name="item_category[]" placeholder="Category">
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control" name="item_qty[]" placeholder="Qty" value="1">
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control" name="item_rate[]" placeholder="Rate">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger" onclick="$(this).closest('.billing-item').remove()">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    $('#billingItems').append(html);
}

// Set IGST if inter-state
$('#clientSelect, #placeOfSupply').on('change', function() {
    const clientState = $('#clientSelect option:selected').data('state');
    const placeOfSupply = $('#placeOfSupply').val();
    const companyState = '<?php echo $company["state"] ?? "Gujarat"; ?>';
    
    if (placeOfSupply !== companyState) {
        // Inter-state: IGST applicable
        $('#cgstRate').val(0);
        $('#sgstRate').val(0);
        $('#igstRate').val(18);
    } else {
        // Intra-state: CGST + SGST
        $('#cgstRate').val(9);
        $('#sgstRate').val(9);
        $('#igstRate').val(0);
    }
});

$(document).ready(function() {
    $('#invoiceTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[2, 'desc']]
    });
});
</script>

<?php endif; // end tablesExist check ?>