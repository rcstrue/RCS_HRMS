<?php
/**
 * RCS HRMS Pro - Form V (Register of Workmen)
 * Under Contract Labour (Regulation & Abolition) Act, 1970
 */

$pageTitle = 'Form V - Register of Workmen';

// Get clients for filter
$stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filters
$clientId = $_GET['client_id'] ?? null;
$unitId = $_GET['unit_id'] ?? null;

// Get units based on selected client
$units = [];
if ($clientId) {
    $stmt = $db->prepare("SELECT u.id, u.name as unit_name, c.name as client_name FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.client_id = ? AND u.is_active = 1 ORDER BY u.name");
    $stmt->execute([$clientId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->query("SELECT u.id, u.name as unit_name, c.name as client_name FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.is_active = 1 ORDER BY c.name, u.name");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$formData = [];
$unitDetails = null;

if ($unitId) {
    $formData = $compliance->generateFormV((int)$unitId, prev_month_num(), prev_month_year());
    
    // Get unit details
    $stmt = $db->prepare("SELECT u.*, c.name as client_name, c.address as client_address FROM units u LEFT JOIN clients c ON u.client_id = c.id WHERE u.id = ?");
    $stmt->execute([$unitId]);
    $unitDetails = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper function for age calculation
function calculateAge($dob) {
    if (empty($dob)) {
        return '-';
    }
    $birth = new DateTime($dob);
    $today = new DateTime();
    return $birth->diff($today)->y;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Form V - Register of Workmen</h5>
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
                    <input type="hidden" name="page" value="forms/form-v">
                    <div class="col-md-3">
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
                    <div class="col-md-4">
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
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </div>
                </form>
            </div>
            
            <!-- Form Content -->
            <div class="card-body" id="formContent">
                <?php if (!$unitId): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">Select a Unit</h5>
                    <p>Choose a unit to generate Form V</p>
                </div>
                <?php else: ?>
                
                <!-- Form Header -->
                <div class="form-header text-center mb-4" style="border: 2px solid #000; padding: 20px;">
                    <h4 class="mb-2">FORM V</h4>
                    <p class="mb-1"><strong>[See Rule 78(1)]</strong></p>
                    <h5 class="mb-3">REGISTER OF WORKMEN</h5>
                    <p class="mb-1"><strong>1. Name of the Principal Employer:</strong> <?php echo sanitize($unitDetails['client_name'] ?? ''); ?></p>
                    <p class="mb-1"><strong>2. Address:</strong> <?php echo sanitize($unitDetails['client_address'] ?? ''); ?></p>
                    <p class="mb-1"><strong>3. Name of Location:</strong> <?php echo sanitize($unitDetails['unit_name'] ?? ''); ?></p>
                    <p><strong>4. Name of Contractor:</strong> RCS TRUE FACILITIES PVT LTD</p>
                </div>
                
                <!-- Workers Table -->
                <table class="table table-bordered" id="formTable">
                    <thead>
                        <tr class="text-center">
                            <th rowspan="2">Sr. No.</th>
                            <th rowspan="2">Name of Workman</th>
                            <th rowspan="2">Father's Name</th>
                            <th rowspan="2">Sex</th>
                            <th rowspan="2">Date of Birth</th>
                            <th rowspan="2">Age</th>
                            <th colspan="2">Date of</th>
                            <th rowspan="2">Designation / Nature of Work</th>
                            <th rowspan="2">Category (Skilled/ Semi-skilled/ Unskilled)</th>
                            <th rowspan="2">Aadhaar No.</th>
                            <th rowspan="2">Remarks</th>
                        </tr>
                        <tr class="text-center">
                            <th>Employment</th>
                            <th>Termination</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sr = 1;
                        foreach ($formData as $emp): 
                            $empName = trim($emp['full_name']);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $sr++; ?></td>
                            <td><?php echo sanitize($empName); ?></td>
                            <td><?php echo sanitize($emp['father_name'] ?? '-'); ?></td>
                            <td class="text-center"><?php echo substr($emp['gender'], 0, 1); ?></td>
                            <td class="text-center"><?php echo formatDate($emp['date_of_birth']); ?></td>
                            <td class="text-center"><?php echo calculateAge($emp['date_of_birth']); ?></td>
                            <td class="text-center"><?php echo formatDate($emp['date_of_joining']); ?></td>
                            <td class="text-center"><?php echo $emp['date_of_leaving'] ? formatDate($emp['date_of_leaving']) : '-'; ?></td>
                            <td><?php echo sanitize($emp['designation'] ?? '-'); ?></td>
                            <td class="text-center"><?php echo sanitize($emp['worker_category']); ?></td>
                            <td><?php echo sanitize($emp['aadhaar_number'] ?? '-'); ?></td>
                            <td>-</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <p><strong>Total Workers:</strong> <?php echo count($formData); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p>Signature of Contractor or his Authorised Agent</p>
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
            <title>Form_V_<?php echo sanitize($unitDetails['unit_name'] ?? 'Export'); ?></title>
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
    XLSX.utils.book_append_sheet(wb, ws, 'Form V');
    XLSX.writeFile(wb, 'Form_V_<?php echo sanitize($unitDetails['unit_name'] ?? 'export'); ?>.xlsx');
}
</script>
<?php exit; ?>
