<?php
/**
 * RCS HRMS Pro - Form XVII (Register of Wages)
 * Under Contract Labour (Regulation & Abolition) Act, 1970
 */

$pageTitle = 'Form XVII - Register of Wages';

// Get clients for filter
$stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filters
$clientId = $_GET['client_id'] ?? null;
$unitId = $_GET['unit_id'] ?? null;
$periodId = $_GET['period_id'] ?? null;

// Get units based on selected client
$units = [];
if ($clientId) {
    $stmt = $db->prepare("SELECT u.id, u.name as unit_name, c.name as client_name FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.client_id = ? AND u.is_active = 1 ORDER BY u.name");
    $stmt->execute([$clientId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->query("SELECT u.id, u.name as unit_name, c.name as client_name FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.is_active = 1");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get payroll periods
$stmt = $db->query("SELECT * FROM payroll_periods WHERE status IN ('Processed', 'Approved', 'Paid') ORDER BY year DESC, month DESC");
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formData = [];
$unitDetails = null;
$periodDetails = null;

if ($unitId && $periodId) {
    $formData = $compliance->generateFormXVII((int)$unitId, (int)$periodId);
    
    $stmt = $db->prepare("SELECT u.*, c.name as client_name, c.address as client_address FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.id = ?");
    $stmt->execute([$unitId]);
    $unitDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$periodId]);
    $periodDetails = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Form XVII - Register of Wages</h5>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="forms/form-xvii">
                    <div class="col-md-2">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" onchange="filterUnits()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect" required>
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['client_name'] . ' - ' . $u['unit_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Period</label>
                        <select class="form-select" name="period_id" required>
                            <option value="">Select Period</option>
                            <?php foreach ($periods as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $periodId == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($p['period_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </div>
                    <?php if ($formData): ?>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-success w-100 mb-1" onclick="savePDF()">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Save as PDF
                        </button>
                        <button type="button" class="btn btn-outline-primary w-100" onclick="printForm()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Form Content -->
            <div class="card-body" id="formContent">
                <?php if (!$unitId || !$periodId): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">Select Unit and Period</h5>
                </div>
                <?php else: ?>
                
                <!-- Form Header -->
                <div class="form-header text-center mb-4" style="border: 2px solid #000; padding: 15px;">
                    <h4 class="mb-2">FORM XVII</h4>
                    <p class="mb-1"><strong>[See Rule 78(1)]</strong></p>
                    <h5 class="mb-3">REGISTER OF WAGES</h5>
                    <div class="row text-start">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Principal Employer:</strong> <?php echo sanitize($unitDetails['client_name'] ?? ''); ?></p>
                            <p class="mb-1"><strong>Contractor:</strong> RCS TRUE FACILITIES PVT LTD</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Location:</strong> <?php echo sanitize($unitDetails['unit_name'] ?? ''); ?></p>
                            <p class="mb-1"><strong>Period:</strong> <?php echo sanitize($periodDetails['period_name'] ?? ''); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Wage Register Table -->
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="formTable">
                        <thead>
                            <tr class="text-center table-light">
                                <th rowspan="2">Sr.</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Father's Name</th>
                                <th rowspan="2">Desig.</th>
                                <th rowspan="2">Days</th>
                                <th colspan="6" class="text-center">EARNINGS</th>
                                <th colspan="4" class="text-center">DEDUCTIONS</th>
                                <th rowspan="2">Net Pay</th>
                                <th rowspan="2">Signature</th>
                            </tr>
                            <tr class="text-center table-light">
                                <th>Basic</th>
                                <th>DA</th>
                                <th>HRA</th>
                                <th>OT</th>
                                <th>Other</th>
                                <th>Gross</th>
                                <th>PF</th>
                                <th>ESI</th>
                                <th>PT</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            $totals = ['gross' => 0, 'pf' => 0, 'esi' => 0, 'pt' => 0, 'ded' => 0, 'net' => 0];
                            foreach ($formData as $emp): 
                                $totals['gross'] += $emp['gross_earnings'];
                                $totals['pf'] += $emp['pf_employee'];
                                $totals['esi'] += $emp['esi_employee'];
                                $totals['pt'] += $emp['professional_tax'];
                                $totals['ded'] += $emp['total_deductions'];
                                $totals['net'] += $emp['net_pay'];
                                $empName = trim($emp['full_name']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $sr++; ?></td>
                                <td><?php echo sanitize($emp['employee_code']); ?></td>
                                <td><?php echo sanitize($empName); ?></td>
                                <td><?php echo sanitize($emp['father_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['designation'] ?? '-'); ?></td>
                                <td class="text-center"><?php echo $emp['paid_days']; ?></td>
                                <td class="text-end"><?php echo number_format($emp['basic'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['da'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['hra'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['overtime_amount'], 0); ?></td>
                                <td class="text-end"><?php echo number_format(($emp['leave_encashment'] ?? 0) + ($emp['bonus_encashment'] ?? 0) + ($emp['washing_allowance'] ?? 0), 0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($emp['gross_earnings'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['pf_employee'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['esi_employee'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($emp['professional_tax'], 0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($emp['total_deductions'], 0); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo number_format($emp['net_pay'], 0); ?></td>
                                <td></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="6" class="text-end">TOTALS:</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?php echo formatCurrency($totals['gross']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['pf']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['esi']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['pt']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['ded']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['net']); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <p><strong>Total Workers:</strong> <?php echo count($formData); ?></p>
                        <p><strong>Net Pay (in words):</strong> <?php echo amountToWords($totals['net']); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p>Signature of Contractor</p>
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
            <title>Form_XVII_Wages_<?php echo sanitize($unitDetails['unit_name'] ?? ''); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 10px; font-size: 10px; }
                table { font-size: 9px; }
                th, td { border: 1px solid #000 !important; padding: 3px 5px; }
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
</script>
<?php exit; ?>
