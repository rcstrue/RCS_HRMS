<?php
/**
 * RCS HRMS Pro - PF Nomination Form (Form 2)
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'PF Nomination Form';

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($emp['full_name'] ?? 'Employee') . ' - ' . $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 15px; font-family: 'Times New Roman', serif; font-size: 14px; }
        .form-container { max-width: 800px; margin: 0 auto; border: 2px solid #000; padding: 15px; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 10px; }
        .form-title { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .form-subtitle { font-size: 12px; }
        .section-title { background: #f0f0f0; padding: 5px 10px; font-weight: bold; margin: 15px 0 10px 0; }
        table.form-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.form-table td, table.form-table th { border: 1px solid #000; padding: 8px; }
        table.form-table th { background: #f5f5f5; width: 35%; }
        .signature-box { border: 1px solid #000; min-height: 60px; margin: 10px 0; }
        .photo-box { width: 100px; height: 120px; border: 1px solid #000; float: right; text-align: center; line-height: 120px; }
        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print mb-3">
        <button onclick="savePDF()" class="btn btn-success"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</button>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
    
    <div class="form-container">
        <!-- Header -->
        <div class="header">
            <div class="form-title">EMPLOYEES' PROVIDENT FUND SCHEME, 1952</div>
            <div class="form-title">FORM 2</div>
            <div class="form-subtitle">(Para 18, 26, 57 & 61 of EPF Scheme)</div>
            <div class="form-title mt-2">NOMINATION AND DECLARATION FORM FOR UNEXEMPTED/EXEMPTED ESTABLISHMENT</div>
        </div>
        
        <!-- Photo Box -->
        <div class="photo-box">Photo</div>
        
        <!-- Section 1: Account Number -->
        <div class="section-title">1. ACCOUNT NUMBER</div>
        <table class="form-table">
            <tr>
                <th>Establishment Code No.</th>
                <td><?php echo sanitize($company['pf_establishment_id'] ?? 'MH/XXXXX/XXXX'); ?></td>
            </tr>
            <tr>
                <th>Account Number (Member's PF A/c No.)</th>
                <td><?php echo sanitize($emp['pf_number'] ?? $emp['uan_number'] ?? ''); ?></td>
            </tr>
        </table>
        
        <!-- Section 2: Member Details -->
        <div class="section-title">2. MEMBER'S PARTICULARS</div>
        <table class="form-table">
            <tr>
                <th>Name (in Block Letters)</th>
                <td><?php echo sanitize($fullName); ?></td>
            </tr>
            <tr>
                <th>Father's/Husband's Name</th>
                <td><?php echo sanitize($emp['father_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Date of Birth</th>
                <td><?php echo formatDate($emp['date_of_birth']); ?></td>
            </tr>
            <tr>
                <th>Sex</th>
                <td><?php echo sanitize($emp['gender']); ?></td>
            </tr>
            <tr>
                <th>Marital Status</th>
                <td><?php echo sanitize($emp['marital_status'] ?? 'Single'); ?></td>
            </tr>
            <tr>
                <th>Date of Joining</th>
                <td><?php echo formatDate($emp['date_of_joining']); ?></td>
            </tr>
            <tr>
                <th>Relation with Employer</th>
                <td>Employee</td>
            </tr>
            <tr>
                <th>Total Period of Service</th>
                <td><?php 
                    if ($emp['date_of_joining']) {
                        $doj = new DateTime($emp['date_of_joining']);
                        $now = new DateTime();
                        $diff = $doj->diff($now);
                        echo $diff->y . ' Years, ' . $diff->m . ' Months';
                    }
                ?></td>
            </tr>
        </table>
        
        <!-- Section 3: Nomination Details -->
        <div class="section-title">3. DETAILS OF NOMINATION</div>
        <p><strong>I hereby nominate the following person(s) to receive the amount standing to my credit in the Provident Fund, in the event of my death.</strong></p>
        
        <table class="form-table">
            <thead>
                <tr>
                    <th>Sr. No.</th>
                    <th>Name of Nominee</th>
                    <th>Relationship with Member</th>
                    <th>Date of Birth</th>
                    <th>Address</th>
                    <th>Share (%)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td><?php echo sanitize($emp['nominee_name'] ?? ''); ?></td>
                    <td><?php echo sanitize($emp['nominee_relationship'] ?? ''); ?></td>
                    <td><?php echo formatDate($emp['nominee_dob'] ?? null); ?></td>
                    <td><?php echo sanitize($emp['address'] ?? ''); ?></td>
                    <td>100%</td>
                </tr>
            </tbody>
        </table>
        
        <!-- Section 4: Declaration -->
        <div class="section-title">4. DECLARATION</div>
        <p>
            I hereby declare that the particulars furnished above are true and correct to the best of my knowledge and belief. 
            I undertake to intimate the employer any change in the nomination.
        </p>
        
        <!-- Signature Section -->
        <div class="row mt-4">
            <div class="col-6">
                <p><strong>Date:</strong> <?php echo date('d/m/Y'); ?></p>
                <br>
                <p><strong>Signature/Thumb Impression of Member:</strong></p>
                <div class="signature-box"></div>
                <p>Name: <?php echo sanitize($fullName); ?></p>
            </div>
            <div class="col-6">
                <p><strong>Employer's Certification:</strong></p>
                <p>Certified that the above nomination has been made by the member and the same has been recorded in the register of nomination.</p>
                <div class="signature-box"></div>
                <p><strong>Authorised Signatory</strong></p>
                <p><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></p>
                <p>Date: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>
        
        <!-- For Office Use -->
        <div class="section-title">FOR OFFICE USE</div>
        <table class="form-table">
            <tr>
                <th>Received on</th>
                <td></td>
            </tr>
            <tr>
                <th>Entry made in Nomination Register at Sr. No.</th>
                <td></td>
            </tr>
            <tr>
                <th>Signature of Dealing Hand</th>
                <td></td>
            </tr>
        </table>
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
