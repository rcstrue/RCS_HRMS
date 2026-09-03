<?php
/**
 * RCS HRMS Pro - Service Certificate
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Service Certificate';

// Get employee ID
$employeeId = $_GET['id'] ?? null;

if (!$employeeId) {
    setFlash('error', 'Employee ID is required');
    redirect('index.php?page=employee/list');
}

// Get employee details with client and unit names
$stmt = $db->prepare("SELECT e.*, c.name as client_name, u.name as unit_name 
                      FROM employees e 
                      LEFT JOIN clients c ON e.client_id = c.id 
                      LEFT JOIN units u ON e.unit_id = u.id 
                      WHERE e.id = ?");
$stmt->execute([$employeeId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    setFlash('error', 'Employee not found');
    redirect('index.php?page=employee/list');
}

// Get company details
$stmt = $db->query("SELECT * FROM companies LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current salary structure for gross salary
$salaryStmt = $db->prepare("SELECT gross_salary FROM employee_salary_structures WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE()) ORDER BY effective_from DESC LIMIT 1");
$salaryStmt->execute([$employeeId]);
$salary = $salaryStmt->fetch(PDO::FETCH_ASSOC);
$grossSalary = $salary['gross_salary'] ?? $emp['gross_salary'] ?? 0;

// Build full name
$fullName = trim(($emp['salutation'] ?? '') . ' ' . $emp['full_name']);

// Calculate tenure
$tenure = '';
$tenureYears = 0;
if ($emp['date_of_joining']) {
    $doj = new DateTime($emp['date_of_joining']);
    $endDate = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : new DateTime();
    $diff = $doj->diff($endDate);
    $tenureYears = $diff->y;
    $tenure = $diff->y . ' years, ' . $diff->m . ' months, ' . $diff->d . ' days';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($emp['full_name'] ?? 'Employee') . ' - ' . $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 15px; font-family: 'Times New Roman', serif; line-height: 1.8; font-size: 14px; }
        .certificate-container { max-width: 800px; margin: 0 auto; border: 3px double #000; padding: 15px; }
        .letterhead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 15px; }
        .company-name { font-size: 26px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .company-details { font-size: 11px; color: #333; }
        .certificate-title { text-align: center; font-size: 22px; font-weight: bold; margin: 10px 0; text-transform: uppercase; letter-spacing: 3px; }
        .certificate-body { text-align: justify; font-size: 15px; }
        .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .details-table td { padding: 8px; border: 1px solid #000; }
        .details-table td:first-child { width: 40%; font-weight: bold; background: #f5f5f5; }
        .signature-section { margin-top: 25px; text-align: right; }
        .seal-box { position: absolute; right: 60px; bottom: 100px; border: 1px dashed #ccc; width: 100px; height: 100px; text-align: center; line-height: 100px; color: #ccc; font-size: 12px; }
        @media print { body { padding: 20px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print mb-3">
        <button onclick="savePDF()" class="btn btn-success"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</button>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
    
    <div class="certificate-container">
        <!-- Letterhead -->
        <div class="letterhead">
            <div class="company-name"><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></div>
            <div class="company-details">
                <?php echo sanitize($company['address'] ?? '110, Someswar Square, Vesu'); ?><br>
                <?php echo sanitize(($company['city'] ?? 'Surat') . ', ' . ($company['state'] ?? 'Gujarat') . ' - ' . ($company['pincode'] ?? '395007')); ?><br>
                Phone: <?php echo sanitize($company['contact_phone'] ?? '+91-XXXXXXXXXX'); ?> | 
                Email: <?php echo sanitize($company['contact_email'] ?? 'hr@rcsfacility.com'); ?><br>
                <?php if (!empty($company['gst_number'])): ?>GST: <?php echo sanitize($company['gst_number']); ?> | <?php endif; ?>
                <?php if (!empty($company['pan_number'])): ?>PAN: <?php echo sanitize($company['pan_number']); ?><?php endif; ?>
            </div>
        </div>
        
        <!-- Title -->
        <div class="certificate-title">SERVICE CERTIFICATE</div>
        
        <!-- Ref and Date -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
            <div><strong>Certificate No:</strong> RCS/SVC/<?php echo date('Y'); ?>/<?php echo str_pad($emp['employee_code'], 4, '0', STR_PAD_LEFT); ?></div>
            <div><strong>Date:</strong> <?php echo date('d/m/Y'); ?></div>
        </div>
        
        <!-- Body -->
        <div class="certificate-body">
            <p>This is to certify that the particulars given below are true and correct as per the records of this establishment:</p>
            
            <table class="details-table">
                <tr>
                    <td>Name of Employee</td>
                    <td><?php echo sanitize($fullName); ?></td>
                </tr>
                <tr>
                    <td>Father's/Husband's Name</td>
                    <td><?php echo sanitize($emp['father_name'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Employee Code</td>
                    <td><?php echo sanitize($emp['employee_code']); ?></td>
                </tr>
                <tr>
                    <td>Date of Birth</td>
                    <td><?php echo formatDate($emp['date_of_birth']); ?></td>
                </tr>
                <tr>
                    <td>Gender</td>
                    <td><?php echo sanitize($emp['gender']); ?></td>
                </tr>
                <tr>
                    <td>Aadhaar Number</td>
                    <td><?php echo sanitize($emp['aadhaar_number'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Designation</td>
                    <td><?php echo sanitize($emp['designation'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Department</td>
                    <td><?php echo sanitize($emp['department'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Worker Category</td>
                    <td><?php echo sanitize($emp['worker_category']); ?></td>
                </tr>
                <tr>
                    <td>Employment Type</td>
                    <td><?php echo sanitize($emp['employment_type']); ?></td>
                </tr>
                <tr>
                    <td>Date of Joining</td>
                    <td><?php echo formatDate($emp['date_of_joining']); ?></td>
                </tr>
                <tr>
                    <td>Date of Leaving</td>
                    <td><?php echo $emp['date_of_leaving'] ? formatDate($emp['date_of_leaving']) : 'Currently Employed'; ?></td>
                </tr>
                <tr>
                    <td>Total Service Period</td>
                    <td><?php echo $tenure; ?></td>
                </tr>
                <tr>
                    <td>Last Drawn Gross Salary</td>
                    <td>₹<?php echo number_format($grossSalary, 2); ?> per month</td>
                </tr>
                <tr>
                    <td>Client/Work Location</td>
                    <td><?php echo sanitize($emp['client_name'] ?? 'N/A'); ?> - <?php echo sanitize($emp['unit_name'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>UAN Number</td>
                    <td><?php echo sanitize($emp['uan_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>ESIC IP Number</td>
                    <td><?php echo sanitize($emp['esic_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Remarks</td>
                    <td>Good conduct during service period</td>
                </tr>
            </table>
            
            <p style="margin-top: 20px;">
                This certificate is issued upon the request of the employee for 
                <?php echo $emp['gender'] === 'Female' ? 'her' : 'his'; ?> 
                personal records and future reference.
            </p>
        </div>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <p>For <?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></p>
            <br><br><br>
            <p>______________________________</p>
            <p><strong>Authorized Signatory</strong></p>
            <p><?php echo sanitize($company['contact_person'] ?? 'HR Manager'); ?></p>
            <p>Date: <?php echo date('d/m/Y'); ?></p>
        </div>
        
        <!-- Seal placeholder -->
        <div class="seal-box">Company Seal</div>
        
        <!-- Footer Note -->
        <div style="margin-top: 15px; font-size: 10px; text-align: center; border-top: 1px solid #ccc; padding-top: 10px;">
            This is a computer-generated certificate. For verification, please contact HR Department at <?php echo sanitize($company['contact_email'] ?? 'hr@rcsfacility.com'); ?>
        </div>
    </div>
<script>
function savePDF() {
    document.title = '<?php echo sanitize($emp['full_name'] ?? 'Employee') . " - " . $pageTitle; ?>';
    window.print();
}
</script>
</body>
</html>
<?php exit; ?>
