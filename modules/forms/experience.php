<?php
/**
 * RCS HRMS Pro - Experience Letter
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Experience Letter';

// Get employee ID
$employeeId = $_GET['id'] ?? null;

if (!$employeeId) {
    setFlash('error', 'Employee ID is required');
    redirect('index.php?page=employee/list');
}

// Get employee details
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
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

// Build full name (only salutation + employee name, NOT father's name)
$fullName = trim(($emp['salutation'] ?? '') . ' ' . $emp['full_name']);

// Calculate tenure
$tenure = '';
if ($emp['date_of_joining']) {
    $doj = new DateTime($emp['date_of_joining']);
    $endDate = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : new DateTime();
    $diff = $doj->diff($endDate);
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
        .letter-container { max-width: 700px; margin: 0 auto; }
        .letterhead { text-align: center; border-bottom: 3px double #000; padding-bottom: 20px; margin-bottom: 15px; }
        .company-name { font-size: 24px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .company-details { font-size: 12px; color: #333; }
        .letter-title { text-align: center; font-size: 18px; font-weight: bold; text-decoration: underline; margin: 10px 0; }
        .letter-body { text-align: justify; }
        .signature-section { margin-top: 25px; }
        @media print { body { padding: 20px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print mb-3">
        <button onclick="savePDF()" class="btn btn-success"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</button>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
    
    <div class="letter-container">
        <!-- Letterhead -->
        <div class="letterhead">
            <div class="company-name"><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></div>
            <div class="company-details">
                <?php echo sanitize($company['address'] ?? '110, Someswar Square, Vesu'); ?><br>
                <?php echo sanitize(($company['city'] ?? 'Surat') . ', ' . ($company['state'] ?? 'Gujarat') . ' - ' . ($company['pincode'] ?? '395007')); ?><br>
                <?php if (!empty($company['gst_number'])): ?>GST: <?php echo sanitize($company['gst_number']); ?> | <?php endif; ?>
                <?php if (!empty($company['pan_number'])): ?>PAN: <?php echo sanitize($company['pan_number']); ?><?php endif; ?>
            </div>
        </div>
        
        <!-- Ref and Date -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
            <div><strong>Ref No:</strong> RCS/EXP/<?php echo date('Y'); ?>/<?php echo str_pad($emp['employee_code'], 4, '0', STR_PAD_LEFT); ?></div>
            <div><strong>Date:</strong> <?php echo date('d/m/Y'); ?></div>
        </div>
        
        <!-- Title -->
        <div class="letter-title">EXPERIENCE CERTIFICATE</div>
        
        <!-- To -->
        <p><strong>To Whom It May Concern</strong></p>
        
        <!-- Body -->
        <div class="letter-body">
            <p>
                This is to certify that <strong><?php echo sanitize($fullName); ?></strong>, 
                S/o/D/o <strong><?php echo sanitize($emp['father_name'] ?? ''); ?></strong>, 
                was employed with <?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?> 
                as <strong><?php echo sanitize($emp['designation'] ?? 'Worker'); ?></strong> 
                <?php if ($emp['department']): ?>in the <strong><?php echo sanitize($emp['department']); ?></strong> department <?php endif; ?>
                from <strong><?php echo formatDate($emp['date_of_joining']); ?></strong> 
                <?php if ($emp['date_of_leaving']): ?>
                to <strong><?php echo formatDate($emp['date_of_leaving']); ?></strong>.
                <?php else: ?>
                to <strong><?php echo date('d/m/Y'); ?></strong> (Till Date).
                <?php endif; ?>
            </p>
            
            <p>
                During the tenure of <strong><?php echo $tenure; ?></strong>, 
                <?php echo $emp['gender'] === 'Female' ? 'she' : 'he'; ?> 
                has been sincere, hardworking, and diligent in 
                <?php echo $emp['gender'] === 'Female' ? 'her' : 'his'; ?> 
                work. 
                <?php echo $emp['gender'] === 'Female' ? 'She' : 'He'; ?> 
                has displayed excellent professional conduct and interpersonal skills.
            </p>
            
            <p>
                <?php echo $emp['gender'] === 'Female' ? 'Her' : 'His'; ?> 
                last drawn gross salary was <strong>₹<?php echo number_format($grossSalary, 2); ?></strong> per month.
            </p>
            
            <p>
                We wish <?php echo $emp['gender'] === 'Female' ? 'her' : 'him'; ?> 
                all the best for 
                <?php echo $emp['gender'] === 'Female' ? 'her' : 'his'; ?> 
                future endeavors.
            </p>
        </div>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <p>For <?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></p>
            <br><br><br>
            <p>______________________________</p>
            <p><strong>Authorized Signatory</strong></p>
            <p><?php echo sanitize($company['contact_person'] ?? 'HR Manager'); ?></p>
        </div>
        
        <!-- Employee Details for Verification -->
        <div style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 15px; font-size: 11px;">
            <p><strong>Employee Details for Verification:</strong></p>
            <table style="width: 100%;">
                <tr>
                    <td>Employee Code: <?php echo sanitize($emp['employee_code']); ?></td>
                    <td>UAN: <?php echo sanitize($emp['uan_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Aadhaar: <?php echo sanitize($emp['aadhaar_number'] ?? 'N/A'); ?></td>
                    <td>Mobile: <?php echo sanitize($emp['mobile_number'] ?? 'N/A'); ?></td>
                </tr>
            </table>
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
