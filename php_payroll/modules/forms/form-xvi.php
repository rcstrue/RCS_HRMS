<?php
/**
 * RCS HRMS Pro - Form XVI (Muster Roll)
 * Under Contract Labour (Regulation & Abolition) Act, 1970
 */

$pageTitle = 'Form XVI - Muster Roll';

// Get units
$stmt = $db->query("SELECT u.id, u.name as unit_name, c.name as client_name FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.is_active = 1 ORDER BY c.name, u.name");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get months/years
$months = [];
for ($m = 1; $m <= 12; $m++) {
    $months[$m] = date('F', mktime(0, 0, 0, $m, 1));
}
$years = range(date('Y'), date('Y') - 2);

$unitId = $_GET['unit_id'] ?? null;
$month = $_GET['month'] ?? prev_month_num();
$year = $_GET['year'] ?? date('Y');

$formData = [];
$unitDetails = null;

if ($unitId) {
    $formData = $compliance->generateFormXVI((int)$unitId, (int)$month, (int)$year);
    
    $stmt = $db->prepare("SELECT u.*, c.name as client_name, c.address as client_address FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.id = ?");
    $stmt->execute([$unitId]);
    $unitDetails = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Form XVI - Muster Roll</h5>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="forms/form-xvi">
                    <div class="col-md-3">
                        <select class="form-select" name="unit_id" required>
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['client_name'] . ' - ' . $u['unit_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="month">
                            <?php foreach ($months as $m => $name): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="year">
                            <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </div>
                    <?php if ($formData): ?>
                    <div class="col-md-3 text-end">
                        <button type="button" class="btn btn-success" onclick="savePDF()">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Save as PDF
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="printForm()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Form Content -->
            <div class="card-body" id="formContent">
                <?php if (!$unitId): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">Select Unit and Period</h5>
                </div>
                <?php else: ?>
                
                <!-- Form Header -->
                <div class="form-header text-center mb-4" style="border: 2px solid #000; padding: 20px;">
                    <h4 class="mb-2">FORM XVI</h4>
                    <p class="mb-1"><strong>[See Rule 78(1)]</strong></p>
                    <h5 class="mb-3">MUSTER ROLL</h5>
                    <p class="mb-1"><strong>Principal Employer:</strong> <?php echo sanitize($unitDetails['client_name'] ?? ''); ?></p>
                    <p class="mb-1"><strong>Contractor:</strong> RCS TRUE FACILITIES PVT LTD</p>
                    <p><strong>Month & Year:</strong> <?php echo htmlspecialchars($months[$month] ?? '', ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                
                <!-- Muster Roll Table -->
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="formTable">
                        <thead>
                            <tr class="text-center">
                                <th>Sr. No.</th>
                                <th>Employee Code</th>
                                <th>Name</th>
                                <th>Father's Name</th>
                                <th>Designation</th>
                                <th>Category</th>
                                <th>Present Days</th>
                                <th>Absent Days</th>
                                <th>Weekly Offs</th>
                                <th>Holidays</th>
                                <th>Working Days</th>
                                <th>Overtime Hours</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            $totals = ['present' => 0, 'absent' => 0, 'woff' => 0, 'holiday' => 0, 'working' => 0, 'ot' => 0];
                            foreach ($formData as $emp): 
                                $totals['present'] += $emp['present_days'] ?? 0;
                                $totals['absent'] += $emp['absent_days'] ?? 0;
                                $totals['woff'] += $emp['weekly_offs'] ?? 0;
                                $totals['holiday'] += $emp['holidays'] ?? 0;
                                $totals['working'] += $emp['total_working_days'] ?? 0;
                                $totals['ot'] += $emp['total_overtime_hours'] ?? 0;
                                $empName = trim($emp['full_name']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $sr++; ?></td>
                                <td><?php echo sanitize($emp['employee_code']); ?></td>
                                <td><?php echo sanitize($empName); ?></td>
                                <td><?php echo sanitize($emp['father_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['designation'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['worker_category']); ?></td>
                                <td class="text-center"><?php echo $emp['present_days'] ?? 0; ?></td>
                                <td class="text-center"><?php echo $emp['absent_days'] ?? 0; ?></td>
                                <td class="text-center"><?php echo $emp['weekly_offs'] ?? 0; ?></td>
                                <td class="text-center"><?php echo $emp['holidays'] ?? 0; ?></td>
                                <td class="text-center fw-bold"><?php echo $emp['total_working_days'] ?? 0; ?></td>
                                <td class="text-center"><?php echo $emp['total_overtime_hours'] ?? 0; ?></td>
                                <td>-</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="6" class="text-end">TOTALS:</td>
                                <td class="text-center"><?php echo $totals['present']; ?></td>
                                <td class="text-center"><?php echo $totals['absent']; ?></td>
                                <td class="text-center"><?php echo $totals['woff']; ?></td>
                                <td class="text-center"><?php echo $totals['holiday']; ?></td>
                                <td class="text-center"><?php echo $totals['working']; ?></td>
                                <td class="text-center"><?php echo $totals['ot']; ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-4">
                        <p><strong>Total Workers:</strong> <?php echo count($formData); ?></p>
                    </div>
                    <div class="col-md-4 text-center">
                        <p>Prepared by:</p>
                        <br><br>
                        <p>___________________</p>
                    </div>
                    <div class="col-md-4 text-end">
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
            <title>Form_XVI_Muster_Roll_<?php echo sanitize($unitDetails['unit_name'] ?? ''); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; font-size: 11px; }
                @media print { body { padding: 0; } }
                table { font-size: 10px; }
                th, td { border: 1px solid #000 !important; padding: 4px 6px; }
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
