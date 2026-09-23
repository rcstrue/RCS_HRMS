<?php
/**
 * RCS HRMS Pro - Gratuity Nomination Form (Form F)
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'Gratuity Nomination Form';

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
        .section-title { background: #f0f0f0; padding: 5px 10px; font-weight: bold; margin: 15px 0 10px 0; border: 1px solid #000; }
        table.form-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.form-table td, table.form-table th { border: 1px solid #000; padding: 8px; }
        table.form-table th { background: #f5f5f5; width: 35%; }
        .signature-box { border: 1px solid #000; min-height: 60px; margin: 10px 0; }
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
            <div class="form-title">THE PAYMENT OF GRATUITY ACT, 1972</div>
            <div class="form-title">FORM 'F'</div>
            <div class="form-subtitle">(See Sub-section (1) of Section 6)</div>
            <div class="form-title mt-2">NOMINATION</div>
        </div>
        
        <!-- Nomination Details -->
        <p>I, <strong><?php echo sanitize($fullName); ?></strong>, 
        hereby nominate the person(s) mentioned below under Section 6 of the Payment of Gratuity Act, 1972, to receive the gratuity payable under the Act in the event of my death:</p>
        
        <!-- Employee Details -->
        <div class="section-title">EMPLOYEE DETAILS</div>
        <table class="form-table">
            <tr>
                <th>Name of Employee</th>
                <td><?php echo sanitize($fullName); ?></td>
            </tr>
            <tr>
                <th>Employee Code</th>
                <td><?php echo sanitize($emp['employee_code']); ?></td>
            </tr>
            <tr>
                <th>Designation</th>
                <td><?php echo sanitize($emp['designation'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Department</th>
                <td><?php echo sanitize($emp['department'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Date of Joining</th>
                <td><?php echo formatDate($emp['date_of_joining']); ?></td>
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
                <th>Present Address</th>
                <td><?php 
                    $addr = [];
                    if ($emp['address']) {
                        $addr[] = sanitize($emp['address']);
                    }
                    if ($emp['district']) {
                        $addr[] = sanitize($emp['district']);
                    }
                    if ($emp['state']) {
                        $addr[] = sanitize($emp['state']);
                    }
                    if ($emp['pin_code']) {
                        $addr[] = sanitize($emp['pin_code']);
                    }
                    echo implode(', ', $addr);
                ?></td>
            </tr>
        </table>
        
        <!-- Nominee Details -->
        <div class="section-title">DETAILS OF NOMINEE(S)</div>
        <table class="form-table">
            <thead>
                <tr>
                    <th>Sr. No.</th>
                    <th>Name of Nominee</th>
                    <th>Relationship</th>
                    <th>Age</th>
                    <th>Address</th>
                    <th>Share (%)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td><?php echo sanitize($emp['nominee_name'] ?? ''); ?></td>
                    <td><?php echo sanitize($emp['nominee_relationship'] ?? ''); ?></td>
                    <td><?php 
                        if (!empty($emp['nominee_dob'])) {
                            $nomDob = new DateTime($emp['nominee_dob']);
                            echo $nomDob->diff(new DateTime())->y;
                        }
                    ?></td>
                    <td><?php echo sanitize($emp['address'] ?? ''); ?></td>
                    <td>100%</td>
                </tr>
            </tbody>
        </table>
        
        <!-- Witness Details -->
        <div class="section-title">WITNESS DETAILS</div>
        <p>This nomination is witnessed by:</p>
        <table class="form-table">
            <tr>
                <th>1. Name</th>
                <td></td>
            </tr>
            <tr>
                <th>Address</th>
                <td></td>
            </tr>
            <tr>
                <th>Signature</th>
                <td></td>
            </tr>
            <tr>
                <th>2. Name</th>
                <td></td>
            </tr>
            <tr>
                <th>Address</th>
                <td></td>
            </tr>
            <tr>
                <th>Signature</th>
                <td></td>
            </tr>
        </table>
        
        <!-- Signature Section -->
        <div class="row mt-4">
            <div class="col-6">
                <p><strong>Date:</strong> <?php echo date('d/m/Y'); ?></p>
                <br>
                <p><strong>Signature of Employee:</strong></p>
                <div class="signature-box"></div>
                <p>Name: <?php echo sanitize($fullName); ?></p>
            </div>
            <div class="col-6">
                <p><strong>Employer's Acknowledgement:</strong></p>
                <p>The above nomination has been recorded in the register maintained for this purpose.</p>
                <div class="signature-box"></div>
                <p><strong>Authorised Signatory</strong></p>
                <p><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></p>
                <p>Date: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>
        
        <!-- Note -->
        <div class="section-title">NOTE</div>
        <ol style="font-size: 12px;">
            <li>The nomination shall be made in duplicate.</li>
            <li>One copy shall be retained by the employer and the other shall be given to the employee.</li>
            <li>If nominee is a minor, the name and address of the guardian should be mentioned.</li>
            <li>The employee may, at any time, modify the nomination by giving a fresh nomination.</li>
        </ol>
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
