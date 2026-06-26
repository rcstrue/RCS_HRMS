<?php
/**
 * RCS HRMS Pro - Create Invoice
 * Manpower Supplier - Client Billing Management
 */
$pageTitle = 'Create Invoice';
$errors = [];
$invoice = [
    'client_id' => '',
    'unit_id' => '',
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'period_from' => date('Y-m-01'),
    'period_to' => date('Y-m-t'),
    'notes' => '',
    'terms_conditions' => 'Payment due within 30 days of invoice date.'
];

// Get clients
$clients = $db->query("SELECT id, name, client_code, gst_number FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice['client_id'] = (int)$_POST['client_id'];
    $invoice['unit_id'] = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $invoice['invoice_date'] = sanitize($_POST['invoice_date']);
    $invoice['due_date'] = sanitize($_POST['due_date']);
    $invoice['period_from'] = sanitize($_POST['period_from']);
    $invoice['period_to'] = sanitize($_POST['period_to']);
    $invoice['notes'] = sanitize($_POST['notes'] ?? '');
    $invoice['terms_conditions'] = sanitize($_POST['terms_conditions'] ?? '');
    
    // Validate
    if (empty($invoice['client_id'])) {
        $errors[] = 'Please select a client';
    }
    if (empty($invoice['invoice_date'])) {
        $errors[] = 'Invoice date is required';
    }
    
    // Get line items
    $items = [];
    $subtotal = 0;
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (!empty($item['description'])) {
                $item_data = [
                    'employee_id' => !empty($item['employee_id']) ? (int)$item['employee_id'] : null,
                    'description' => sanitize($item['description']),
                    'designation' => sanitize($item['designation'] ?? ''),
                    'days_worked' => (float)($item['days_worked'] ?? 0),
                    'rate_per_day' => (float)($item['rate_per_day'] ?? 0),
                    'quantity' => (float)($item['quantity'] ?? 1),
                    'unit_price' => (float)($item['unit_price'] ?? 0),
                    'amount' => (float)($item['amount'] ?? 0),
                    'gst_rate' => (float)($item['gst_rate'] ?? 18)
                ];
                $items[] = $item_data;
                $subtotal += $item_data['amount'];
            }
        }
    }
    
    if (empty($items)) {
        $errors[] = 'Please add at least one line item';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Generate invoice number
            $prefix = 'INV';
            $year = date('Y', strtotime($invoice['invoice_date']));
            $month = date('m', strtotime($invoice['invoice_date']));
            $stmt = $db->query("SELECT MAX(id) as max_id FROM invoices");
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
            $invoice_number = $prefix . $year . $month . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
            
            // Calculate GST
            $client_info = $db->query("SELECT gst_number FROM clients WHERE id = {$invoice['client_id']}")->fetch(PDO::FETCH_ASSOC);
            $company_state = 'GJ'; // Gujarat - get from company settings
            $client_state = substr($client_info['gst_number'] ?? '', 0, 2);
            
            $cgst = 0;
            $sgst = 0;
            $igst = 0;
            $gst_rate = 18; // Default GST rate
            
            if ($client_state === $company_state || empty($client_state)) {
                // Same state - CGST + SGST
                $cgst = round($subtotal * ($gst_rate / 2 / 100), 2);
                $sgst = round($subtotal * ($gst_rate / 2 / 100), 2);
            } else {
                // Different state - IGST
                $igst = round($subtotal * ($gst_rate / 100), 2);
            }
            
            $total = $subtotal + $cgst + $sgst + $igst;
            
            // Insert invoice
            $stmt = $db->prepare("INSERT INTO invoices (invoice_number, client_id, unit_id, invoice_date, due_date, 
                period_from, period_to, subtotal, cgst_amount, sgst_amount, igst_amount, total_amount, 
                notes, terms_conditions, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
            
            $stmt->execute([
                $invoice_number,
                $invoice['client_id'],
                $invoice['unit_id'],
                $invoice['invoice_date'],
                $invoice['due_date'],
                $invoice['period_from'],
                $invoice['period_to'],
                $subtotal,
                $cgst,
                $sgst,
                $igst,
                $total,
                $invoice['notes'],
                $invoice['terms_conditions'],
                $_SESSION['user_id']
            ]);
            
            $invoice_id = $db->lastInsertId();
            
            // Insert items
            $stmt = $db->prepare("INSERT INTO invoice_items (invoice_id, employee_id, description, designation, 
                days_worked, rate_per_day, quantity, unit_price, amount, gst_rate) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $idx => $item) {
                $stmt->execute([
                    $invoice_id,
                    $item['employee_id'],
                    $item['description'],
                    $item['designation'],
                    $item['days_worked'],
                    $item['rate_per_day'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['amount'],
                    $item['gst_rate']
                ]);
            }
            
            $db->commit();
            
            setFlash('success', "Invoice {$invoice_number} created successfully");
            redirect("index.php?page=billing/view&id={$invoice_id}");
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error creating invoice: ' . $e->getMessage();
        }
    }
}

// Get employees for selection
$employees = $db->query("SELECT id, employee_code, full_name, designation, client_id, unit_id 
    FROM employees WHERE status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=billing/list">Invoices</a></li>
                    <li class="breadcrumb-item active">Create Invoice</li>
                </ol>
            </nav>
            <h1 class="page-title">Create New Invoice</h1>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo $error; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" id="invoiceForm">
    <div class="row">
        <!-- Invoice Details -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Invoice Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Client</label>
                            <select name="client_id" id="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" 
                                        data-gst="<?php echo sanitize($client['gst_number']); ?>"
                                        <?php echo $invoice['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($client['name']); ?> (<?php echo sanitize($client['client_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit/Location</label>
                            <select name="unit_id" id="unit_id" class="form-select">
                                <option value="">All Units</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Invoice Date</label>
                            <input type="date" name="invoice_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($invoice['invoice_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Due Date</label>
                            <input type="date" name="due_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($invoice['due_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Period From</label>
                            <input type="date" name="period_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($invoice['period_from'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Period To</label>
                            <input type="date" name="period_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($invoice['period_to'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Line Items</h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="loadFromTimesheet">
                            <i class="bi bi-upload me-1"></i>Load from Timesheet
                        </button>
                        <button type="button" class="btn btn-sm btn-success" id="addItem">
                            <i class="bi bi-plus-lg me-1"></i>Add Item
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 200px;">Employee/Description</th>
                                    <th style="width: 120px;">Designation</th>
                                    <th style="width: 80px;">Days</th>
                                    <th style="width: 100px;">Rate/Day</th>
                                    <th style="width: 100px;">Amount</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <tr class="item-row">
                                    <td>
                                        <select name="items[0][employee_id]" class="form-select form-select-sm employee-select">
                                            <option value="">Select or type description</option>
                                            <?php foreach ($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>" 
                                                    data-designation="<?php echo sanitize($emp['designation']); ?>"
                                                    data-client="<?php echo $emp['client_id']; ?>">
                                                <?php echo sanitize($emp['full_name']); ?> (<?php echo $emp['employee_code']; ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="items[0][description]" class="form-control form-control-sm mt-1" placeholder="Description">
                                    </td>
                                    <td><input type="text" name="items[0][designation]" class="form-control form-control-sm designation"></td>
                                    <td><input type="number" name="items[0][days_worked]" class="form-control form-control-sm days" step="0.5" value="30"></td>
                                    <td><input type="number" name="items[0][rate_per_day]" class="form-control form-control-sm rate" step="0.01" value="0"></td>
                                    <td><input type="number" name="items[0][amount]" class="form-control form-control-sm amount" step="0.01" readonly></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Additional Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($invoice['notes'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Terms & Conditions</label>
                        <textarea name="terms_conditions" class="form-control" rows="3"><?php echo htmlspecialchars($invoice['terms_conditions'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h5 class="card-title mb-0">Invoice Summary</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>Subtotal</td>
                            <td class="text-end" id="summarySubtotal">₹0.00</td>
                        </tr>
                        <tr>
                            <td>CGST (9%)</td>
                            <td class="text-end" id="summaryCGST">₹0.00</td>
                        </tr>
                        <tr>
                            <td>SGST (9%)</td>
                            <td class="text-end" id="summarySGST">₹0.00</td>
                        </tr>
                        <tr>
                            <td>IGST (18%)</td>
                            <td class="text-end" id="summaryIGST">₹0.00</td>
                        </tr>
                        <tr class="table-primary">
                            <th>Total</th>
                            <th class="text-end" id="summaryTotal">₹0.00</th>
                        </tr>
                    </table>

                    <hr>

                    <div class="d-grid gap-2">
                        <button type="submit" name="action" value="draft" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Save as Draft
                        </button>
                        <button type="submit" name="action" value="send" class="btn btn-success">
                            <i class="bi bi-send me-1"></i>Save & Send
                        </button>
                        <a href="index.php?page=billing/list" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$extraJS = <<<'JS'
<script>
let itemIndex = 1;
let isIGST = false;

// Client change - load units and check GST
$('#client_id').change(function() {
    const selected = $(this).find(':selected');
    const clientId = $(this).val();
    const clientGST = selected.data('gst');
    
    // Check if IGST applicable (different state)
    const companyState = 'GJ';
    const clientState = clientGST ? clientGST.substring(0, 2) : '';
    isIGST = clientState !== companyState && clientState !== '';
    
    calculateTotals();
    
    // Load units
    if (clientId) {
        $.get(`index.php?page=api/units&client_id=${clientId}`, function(data) {
            let options = '<option value="">All Units</option>';
            data.forEach(unit => {
                options += `<option value="${unit.id}">${unit.name}</option>`;
            });
            $('#unit_id').html(options);
        });
    }
});

// Add item
$('#addItem').click(function() {
    const template = `
        <tr class="item-row">
            <td>
                <select name="items[${itemIndex}][employee_id]" class="form-select form-select-sm employee-select">
                    <option value="">Select or type description</option>
                    $('#employeeOptions').html()
                </select>
                <input type="text" name="items[${itemIndex}][description]" class="form-control form-control-sm mt-1" placeholder="Description">
            </td>
            <td><input type="text" name="items[${itemIndex}][designation]" class="form-control form-select-sm designation"></td>
            <td><input type="number" name="items[${itemIndex}][days_worked]" class="form-control form-control-sm days" step="0.5" value="30"></td>
            <td><input type="number" name="items[${itemIndex}][rate_per_day]" class="form-control form-control-sm rate" step="0.01" value="0"></td>
            <td><input type="number" name="items[${itemIndex}][amount]" class="form-control form-control-sm amount" step="0.01" readonly></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button></td>
        </tr>
    `;
    $('#itemsBody').append(template);
    itemIndex++;
    bindCalculation();
});

// Remove item
$(document).on('click', '.remove-item', function() {
    if ($('.item-row').length > 1) {
        $(this).closest('tr').remove();
        calculateTotals();
    }
});

// Calculate line item amounts
function bindCalculation() {
    $('.days, .rate').off('input').on('input', function() {
        const row = $(this).closest('tr');
        const days = parseFloat(row.find('.days').val()) || 0;
        const rate = parseFloat(row.find('.rate').val()) || 0;
        row.find('.amount').val((days * rate).toFixed(2));
        calculateTotals();
    });
}

// Calculate totals
function calculateTotals() {
    let subtotal = 0;
    $('.amount').each(function() {
        subtotal += parseFloat($(this).val()) || 0;
    });
    
    const gstRate = 0.18;
    let cgst = 0, sgst = 0, igst = 0;
    
    if (isIGST) {
        igst = subtotal * gstRate;
    } else {
        cgst = subtotal * gstRate / 2;
        sgst = subtotal * gstRate / 2;
    }
    
    const total = subtotal + cgst + sgst + igst;
    
    $('#summarySubtotal').text('₹' + subtotal.toFixed(2));
    $('#summaryCGST').text('₹' + cgst.toFixed(2));
    $('#summarySGST').text('₹' + sgst.toFixed(2));
    $('#summaryIGST').text('₹' + igst.toFixed(2));
    $('#summaryTotal').text('₹' + total.toFixed(2));
}

// Employee select - populate designation
$(document).on('change', '.employee-select', function() {
    const selected = $(this).find(':selected');
    const designation = selected.data('designation');
    if (designation) {
        $(this).closest('tr').find('.designation').val(designation);
        $(this).closest('tr').find('input[name$="[description]"]').val(selected.text());
    }
});

// Initialize
bindCalculation();
</script>
JS;


