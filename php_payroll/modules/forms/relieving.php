<?php
/**
 * RCS HRMS Pro - Relieving Letter
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Relieving Letter';

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

// Build full name
$fullName = trim(($emp['salutation'] ?? '') . ' ' . $emp['full_name']);

// Calculate tenure
$tenure = '';
if ($emp['date_of_joining'] && $emp['date_of_leaving']) {
    $doj = new DateTime($emp['date_of_joining']);
    $endDate = new DateTime($emp['date_of_leaving']);
    $diff = $doj->diff($endDate);
    $tenure = $diff->y . ' years, ' . $diff->m . ' months';
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
    
    <?php if ($emp['status'] !== 'inactive' && $emp['status'] !== 'terminated'): ?>
    <div class="no-print alert alert-warning">
        <strong>Warning:</strong> This employee is currently marked as "<?php echo sanitize($emp['status']); ?>". 
        Relieving letter is typically issued after employee has left the organization.
    </div>
    <?php endif; ?>
    
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
            <div><strong>Ref No:</strong> RCS/REL/<?php echo date('Y'); ?>/<?php echo str_pad($emp['employee_code'], 4, '0', STR_PAD_LEFT); ?></div>
            <div><strong>Date:</strong> <?php echo date('d/m/Y'); ?></div>
        </div>
        
        <!-- Title -->
        <div class="letter-title">RELIEVING LETTER</div>
        
        <!-- To -->
        <p>
            <strong><?php echo sanitize($fullName); ?></strong><br>
            Employee Code: <?php echo sanitize($emp['employee_code']); ?><br>
            <?php if ($emp['address']): ?>
            <?php echo sanitize($emp['address']); ?><br>
            <?php echo sanitize(($emp['district'] ?? '') . ', ' . ($emp['state'] ?? '') . ' - ' . ($emp['pin_code'] ?? '')); ?>
            <?php endif; ?>
        </p>
        
        <!-- Subject -->
        <p><strong>Subject: Relieving Letter</strong></p>
        
        <!-- Body -->
        <div class="letter-body">
            <p>Dear <?php echo sanitize($emp['full_name']); ?>,</p>
            
            <p>
                With reference to your resignation letter dated <strong><?php echo $emp['date_of_leaving'] ? formatDate(date('Y-m-d', strtotime($emp['date_of_leaving'] . ' -1 month'))) : 'N/A'; ?></strong>, 
                we hereby accept your resignation from the post of <strong><?php echo sanitize($emp['designation'] ?? 'Worker'); ?></strong> 
                <?php if ($emp['department']): ?>in <strong><?php echo sanitize($emp['department']); ?></strong> department <?php endif; ?>
                with <?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?>.
            </p>
            
            <p>
                You are hereby relieved from your duties with effect from <strong><?php echo formatDate($emp['date_of_leaving']); ?></strong> 
                (close of working hours).
            </p>
            
            <p>
                During your tenure of <strong><?php echo $tenure; ?></strong> with us from <strong><?php echo formatDate($emp['date_of_joining']); ?></strong> 
                to <strong><?php echo formatDate($emp['date_of_leaving']); ?></strong>, 
                we have found you to be sincere, hardworking, and dedicated. We appreciate your contribution to the organization.
            </p>
            
            <p>
                Please ensure that all company assets, documents, ID cards, and other materials in your possession are returned 
                to the HR department. Your full and final settlement will be processed as per company policy.
            </p>
            
            <p>
                We wish you all the very best for your future endeavors.
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
        
        <!-- Clearance Note -->
        <div style="margin-top: 15px; border: 1px solid #000; padding: 15px; font-size: 12px;">
            <p><strong>CLEARANCE CERTIFICATE</strong></p>
            <p>This is to certify that all dues and assets have been cleared/returned by the above-named employee.</p>
            <table style="width: 100%; margin-top: 15px;">
                <tr>
                    <td style="width: 50%;">
                        Department: ________________<br><br>
                        Signature: ________________
                    </td>
                    <td style="width: 50%;">
                        HR Department: ________________<br><br>
                        Signature: ________________
                    </td>
                </tr>
                <tr>
                    <td>
                        Accounts: ________________<br><br>
                        Signature: ________________
                    </td>
                    <td>
                        IT Department: ________________<br><br>
                        Signature: ________________
                    </td>
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
