<?php
/**
 * RCS HRMS Pro - Form F2 (Register of Contractors)
 * Under Contract Labour (Regulation & Abolition) Act, 1970
 */

$pageTitle = 'Form F2 - Register of Contractors';

// Get all clients
$stmt = $db->query("SELECT id, name, address, city, state FROM clients WHERE is_active = 1 ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clientId = $_GET['client_id'] ?? null;
$formData = [];
$clientDetails = null;

if ($clientId) {
    // Get client details
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get units for this client with employee counts
    $stmt = $db->prepare("SELECT u.id, u.name as unit_name, u.address, u.city,
                                 COUNT(DISTINCT e.id) as employee_count
                          FROM units u
                          LEFT JOIN employees e ON e.unit_id = u.id AND e.status = 'approved'
                          WHERE u.client_id = ? AND u.is_active = 1
                          GROUP BY u.id
                          ORDER BY u.name");
    $stmt->execute([$clientId]);
    $formData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Form F2 - Register of Contractors</h5>
                <div class="card-actions">
                    <?php if ($formData): ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="savePDF()">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Save as PDF
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="printForm()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="exportExcel()">
                        <i class="bi bi-download me-1"></i>Export Excel
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="forms/form-f2">
                    <div class="col-md-4">
                        <select class="form-select" name="client_id" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </div>
                </form>
            </div>
            
            <!-- Form Content -->
            <div class="card-body" id="formContent">
                <?php if (!$clientId): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">Select a Client</h5>
                    <p>Choose a client to generate Form F2 - Register of Contractors</p>
                </div>
                <?php else: ?>
                
                <!-- Form Header -->
                <div class="form-header text-center mb-4" style="border: 2px solid #000; padding: 20px;">
                    <h4 class="mb-2">FORM F2</h4>
                    <p class="mb-1"><strong>[See Rule 82]</strong></p>
                    <h5 class="mb-3">REGISTER OF CONTRACTORS</h5>
                    <p class="mb-1"><strong>1. Name of Principal Employer:</strong> <?php echo sanitize($clientDetails['name'] ?? ''); ?></p>
                    <p class="mb-1"><strong>2. Address:</strong> <?php echo sanitize(($clientDetails['address'] ?? '') . ', ' . ($clientDetails['city'] ?? '') . ', ' . ($clientDetails['state'] ?? '')); ?></p>
                </div>
                
                <!-- Contractors Table -->
                <table class="table table-bordered" id="formTable">
                    <thead>
                        <tr class="text-center">
                            <th>Sr. No.</th>
                            <th>Name & Address of Contractor</th>
                            <th>Nature of Work</th>
                            <th>Period of Contract<br>(From - To)</th>
                            <th>No. of Workmen Employed</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sr = 1;
                        $totalWorkmen = 0;
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $sr++; ?></td>
                            <td>
                                <strong>RCS TRUE FACILITIES PVT LTD</strong><br>
                                Office Address: [Company Address]<br>
                                GSTIN: [GST Number]<br>
                                PAN: [PAN Number]
                            </td>
                            <td>Contract Labour Services</td>
                            <td>
                                From: <?php echo date('01/04/Y'); ?><br>
                                To: <?php echo date('31/03/Y', strtotime('+1 year')); ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                $count = array_sum(array_column($formData, 'employee_count'));
                                $totalWorkmen = $count;
                                echo $count; 
                                ?>
                            </td>
                            <td>-</td>
                        </tr>
                        
                        <?php if (!empty($formData)): ?>
                        <tr>
                            <td colspan="6" class="fw-bold bg-light">Work Locations / Units:</td>
                        </tr>
                        <?php foreach ($formData as $unit): ?>
                        <tr>
                            <td class="text-center"><?php echo $sr++; ?></td>
                            <td>
                                <strong>Unit:</strong> <?php echo sanitize($unit['unit_name']); ?><br>
                                <?php echo sanitize($unit['address'] ?? ''); ?>
                                <?php if (!empty($unit['city'])): ?>
                                <br><?php echo sanitize($unit['city']); ?>
                                <?php endif; ?>
                            </td>
                            <td>Contract Labour Services</td>
                            <td>-</td>
                            <td class="text-center"><?php echo $unit['employee_count']; ?></td>
                            <td>-</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <tr class="table-light fw-bold">
                            <td colspan="4" class="text-end">Total Workmen:</td>
                            <td class="text-center"><?php echo $totalWorkmen; ?></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <p><strong>Total Units:</strong> <?php echo count($formData); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p>Signature of Principal Employer</p>
                        <br><br>
                        <p>Date: <?php echo date('d-m-Y'); ?></p>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function printForm() {
    const content = document.getElementById('formContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Form_F2_<?php echo sanitize($clientDetails['name'] ?? 'Export'); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; }
                @media print { body { padding: 0; } }
                table { font-size: 12px; }
                th, td { border: 1px solid #000 !important; }
            </style>
        </head>
        <body>${content}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = function() { printWindow.print(); printWindow.close(); };
}

function savePDF() {
    printForm();
}

function exportExcel() {
    const table = document.getElementById('formTable');
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(table);
    XLSX.utils.book_append_sheet(wb, ws, 'Form F2');
    XLSX.writeFile(wb, 'Form_F2_<?php echo sanitize($clientDetails['name'] ?? 'export'); ?>.xlsx');
}
</script>
<?php exit; ?>
