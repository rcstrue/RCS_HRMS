<?php
/**
 * RCS HRMS Pro - ESI Nomination Form
 * Company: RCS TRUE FACILITIES PVT LTD
 */

$pageTitle = 'ESI Nomination Form';

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

// Get family members for ESI - use try/catch in case table doesn't exist
$familyMembers = [];
try {
    $stmt = $db->prepare("SELECT * FROM employee_family WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $familyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist, use empty array
    $familyMembers = [];
}

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
            <div class="form-title">EMPLOYEES' STATE INSURANCE CORPORATION</div>
            <div class="form-title">DECLARATION FORM</div>
            <div class="form-subtitle">(Regulation 10 & 14 of ESI (General) Regulations, 1950)</div>
        </div>
        
        <!-- Section 1: Employee Details -->
        <div class="section-title">1. INSURED PERSON'S DETAILS</div>
        <table class="form-table">
            <tr>
                <th>Insurance Number (IP Number)</th>
                <td><?php echo sanitize($emp['esic_number'] ?? ''); ?></td>
            </tr>
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
            <tr>
                <th>Occupation/Designation</th>
                <td><?php echo sanitize($emp['designation'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Date of Entry into Employment</th>
                <td><?php echo formatDate($emp['date_of_joining']); ?></td>
            </tr>
        </table>
        
        <!-- Section 2: Family Details -->
        <div class="section-title">2. FAMILY DETAILS (For Medical Benefit)</div>
        <table class="form-table">
            <thead>
                <tr>
                    <th>Sr. No.</th>
                    <th>Name</th>
                    <th>Relationship</th>
                    <th>Date of Birth</th>
                    <th>Dependent (Yes/No)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($familyMembers)): ?>
                    <?php foreach ($familyMembers as $i => $member): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo sanitize($member['member_name']); ?></td>
                        <td><?php echo sanitize($member['relationship']); ?></td>
                        <td><?php echo formatDate($member['date_of_birth']); ?></td>
                        <td><?php echo $member['is_dependent'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td>1</td>
                        <td><?php echo sanitize($emp['nominee_name'] ?? ''); ?></td>
                        <td><?php echo sanitize($emp['nominee_relationship'] ?? ''); ?></td>
                        <td></td>
                        <td>Yes</td>
                    </tr>
                <?php endif; ?>
                <?php for ($i = count($familyMembers) + 1; $i <= 5; $i++): ?>
                <tr>
                    <td><?php echo $i; ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        
        <!-- Section 3: Nomination -->
        <div class="section-title">3. NOMINATION FOR DEPOSIT OF BENEFITS</div>
        <p>In the event of my death, I nominate the following person to receive the benefits payable under the Act:</p>
        <table class="form-table">
            <tr>
                <th>Name of Nominee</th>
                <td><?php echo sanitize($emp['nominee_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Relationship</th>
                <td><?php echo sanitize($emp['nominee_relationship'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Address</th>
                <td><?php echo sanitize($emp['address'] ?? ''); ?></td>
            </tr>
        </table>
        
        <!-- Declaration -->
        <div class="section-title">4. DECLARATION</div>
        <p>
            I hereby declare that the particulars given above are true and correct to the best of my knowledge and belief. 
            I undertake to inform my employer of any change in the above particulars.
        </p>
        
        <!-- Signature Section -->
        <div class="row mt-4">
            <div class="col-6">
                <p><strong>Date:</strong> <?php echo date('d/m/Y'); ?></p>
                <br>
                <p><strong>Signature/Thumb Impression of Insured Person:</strong></p>
                <div class="signature-box"></div>
                <p>Name: <?php echo sanitize($fullName); ?></p>
            </div>
            <div class="col-6">
                <p><strong>Certified by Employer:</strong></p>
                <p>Certified that the particulars furnished by the insured person have been verified and recorded.</p>
                <div class="signature-box"></div>
                <p><strong>Authorised Signatory</strong></p>
                <p><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></p>
                <p>Employer's Code: <?php echo sanitize($company['esi_establishment_id'] ?? ''); ?></p>
            </div>
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
