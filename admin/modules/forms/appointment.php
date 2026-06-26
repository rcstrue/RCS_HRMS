<?php
/**
 * RCS HRMS Pro - Appointment Letter Generator
 * Updated for new database schema
 */

$pageTitle = 'Appointment Letter';

// Get employees
$stmt = $db->query("SELECT id, employee_code, full_name, designation FROM employees WHERE status = 'approved' ORDER BY full_name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employeeId = $_GET['id'] ?? $_GET['employee_id'] ?? null;
$employeeData = null;

if ($employeeId) {
    $employeeData = $employee->getById($employeeId);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = $_POST['employee_id'];
    $employeeData = $employee->getById($employeeId);
}
?>

<div class="row">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Generate Appointment Letter</h5>
            </div>
            <div class="card-body">
                <form method="GET">
                    <input type="hidden" name="page" value="forms/appointment">
                    
                    <div class="mb-3">
                        <label class="form-label required">Select Employee</label>
                        <select class="form-select select2" name="employee_id" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $employeeId == $e['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-file-text me-1"></i>Generate Letter
                    </button>
                </form>
            </div>
        </div>
        
        <?php if ($employeeData): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Letter Preview</h5>
            </div>
            <div class="card-body">
                <button type="button" class="btn btn-success w-100 mb-2" onclick="savePDF()">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Save as PDF
                </button>
                <button type="button" class="btn btn-outline-primary w-100 mb-2" onclick="printLetter()">
                    <i class="bi bi-printer me-1"></i>Print Letter
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-8">
        <?php if (!$employeeData): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-file-text fs-1 text-muted"></i>
                <h5 class="mt-3 text-muted">Select an Employee</h5>
                <p class="text-muted">Choose an employee to generate appointment letter</p>
            </div>
        </div>
        <?php else: ?>
        
        <div class="card" id="letterContainer">
            <div class="card-body p-5" id="letterContent" style="font-family: 'Times New Roman', serif; line-height: 1.8;">
                <!-- Letterhead -->
                <div class="text-center mb-4" style="border-bottom: 2px solid #000; padding-bottom: 20px;">
                    <h2 class="mb-1" style="font-weight: bold;">RCS TRUE FACILITIES PVT LTD</h2>
                    <p class="mb-0">110, Someswar Square, Vesu, Surat - 395007, Gujarat</p>
                    <p class="mb-0">GST: 24AAICR1390M1Z3 | PAN: AAICR1390M</p>
                    <p class="mb-0">Email: hr@rcsfacility.com | Phone: +91-XXXXXXXXXX</p>
                </div>
                
                <!-- Ref Number and Date -->
                <div class="d-flex justify-content-between mb-4">
                    <div>
                        <strong>Ref No:</strong> RCS/APP/<?php echo date('Y'); ?>/<?php echo $employeeData['employee_code']; ?>
                    </div>
                    <div>
                        <strong>Date:</strong> <?php echo date('d/m/Y'); ?>
                    </div>
                </div>
                
                <!-- Employee Address -->
                <div class="mb-4">
                    <p class="mb-1"><strong>To,</strong></p>
                    <p class="mb-1"><?php echo sanitize($employeeData['full_name'] ?? ''); ?></p>
                    <p class="mb-1"><?php echo sanitize($employeeData['address'] ?? ''); ?></p>
                    <p class="mb-0"><?php echo sanitize(($employeeData['district'] ?? '') . ', ' . ($employeeData['state'] ?? '') . ' - ' . ($employeeData['pin_code'] ?? '')); ?></p>
                </div>
                
                <!-- Subject -->
                <div class="mb-4">
                    <p><strong>Subject: Letter of Appointment</strong></p>
                </div>
                
                <!-- Salutation -->
                <p>Dear <?php echo sanitize(explode(' ', $employeeData['full_name'] ?? '')[0]); ?>,</p>
                
                <!-- Body -->
                <p>
                    With reference to your application and subsequent interview, we are pleased to inform you that you have been selected for the post of <strong><?php echo sanitize($employeeData['designation'] ?? 'Worker'); ?></strong> in our organization. You are hereby appointed on the following terms and conditions:
                </p>
                
                <p><strong>1. DATE OF JOINING:</strong></p>
                <p>You will join your duties on <strong><?php echo formatDate($employeeData['date_of_joining'] ?? date('Y-m-d')); ?></strong>.</p>
                
                <p><strong>2. PROBATION PERIOD:</strong></p>
                <p>You will be on probation for a period of <strong><?php echo $employeeData['probation_period'] ?? 3; ?> months</strong> from the date of joining. Your confirmation will be subject to satisfactory performance during the probation period.</p>
                
                <p><strong>3. REMUNERATION:</strong></p>
                <p>Your monthly remuneration will be as follows:</p>
                <table class="table table-bordered mb-3" style="width: 60%;">
                    <tr><td>Basic + DA</td><td class="text-end">₹<?php echo number_format($employeeData['basic_da'] ?? 0, 2); ?></td></tr>
                    <tr><td>HRA</td><td class="text-end">₹<?php echo number_format($employeeData['hra'] ?? 0, 2); ?></td></tr>
                    <tr><td><strong>Gross Salary</strong></td><td class="text-end"><strong>₹<?php echo number_format($employeeData['gross_salary'] ?? 0, 2); ?></strong></td></tr>
                </table>
                
                <p><strong>4. STATUTORY BENEFITS:</strong></p>
                <p>
                    <?php if (!empty($employeeData['pf_applicable'])): ?>You will be covered under Employees' Provident Fund and Misc. Provisions Act, 1952. <?php endif; ?>
                    <?php if (!empty($employeeData['esi_applicable'])): ?>You will be covered under Employees' State Insurance Act, 1948. <?php endif; ?>
                    You will be entitled to other statutory benefits as per applicable laws.
                </p>
                
                <p><strong>5. WORKING HOURS:</strong></p>
                <p>Your working hours will be 8 hours per day with a weekly off. Overtime will be paid as per applicable laws.</p>
                
                <p><strong>6. LEAVE:</strong></p>
                <p>You will be entitled to leaves as per the company policy and applicable laws (Casual Leave, Sick Leave, Earned Leave, etc.).</p>
                
                <p><strong>7. TERMINATION:</strong></p>
                <p>Your services can be terminated by giving one month's notice or salary in lieu thereof from either side.</p>
                
                <p><strong>8. GENERAL:</strong></p>
                <p>You will be governed by the rules and regulations of the company and applicable laws of India.</p>
                
                <p>We welcome you to RCS TRUE FACILITIES PVT LTD family and hope for a long and fruitful association.</p>
                
                <p>Please sign and return the duplicate copy of this letter as a token of your acceptance of the above terms and conditions.</p>
                
                <br>
                <p>Yours faithfully,</p>
                <br><br>
                <p><strong>For RCS TRUE FACILITIES PVT LTD</strong></p>
                <br><br>
                <p>________________________</p>
                <p>Authorized Signatory</p>
                
                <br><br>
                <p><strong>I accept the above terms and conditions:</strong></p>
                <br><br>
                <p>Signature: ________________________</p>
                <p>Name: <?php echo sanitize($employeeData['full_name'] ?? ''); ?></p>
                <p>Date: ________________________</p>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</div>

<?php if ($employeeData): ?>
<script>
function printLetter() {
    const content = document.getElementById('letterContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo sanitize($employeeData['full_name'] ?? 'Employee') . ' - Appointment Letter'; ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 15px; font-family: 'Times New Roman', serif; line-height: 1.8; }
                @media print { body { padding: 10px; } }
            </style>
        </head>
        <body>${content}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = function() { printWindow.print(); printWindow.close(); };
}

function savePDF() {
    printLetter();
}
</script>
<?php endif; ?>
<?php exit; ?>
