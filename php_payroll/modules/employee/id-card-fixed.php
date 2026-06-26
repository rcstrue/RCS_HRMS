<?php
/**
 * RCS HRMS Pro - Employee ID Card Generator
 * Generates and downloads ID card as JPEG
 * Card Size: 54mm x 86mm
 */

$pageTitle = 'Generate ID Card';

// Handle photo upload
if (isset($_POST['upload_photo']) && isset($_POST['employee_id'])) {
    $empId = (int)$_POST['employee_id'];
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $fileType = $_FILES['profile_photo']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $ext = ($fileType === 'image/png') ? 'png' : 'jpg';
            $filename = uniqid() . '_' . time() . '.' . $ext;
            
            // Upload to web root with / prefix in database
            $uploadDir = 'uploads/employees/photos/';
            $dbPath = '/' . $uploadDir . $filename;  // Path saved in DB starts with /
            
            // Get web root path (parent of hrms folder)
            $webRoot = dirname(APP_ROOT);
            $fullPath = $webRoot . '/' . $uploadDir;
            
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $fullPath . $filename)) {
                $db->update('employees', ['profile_pic_url' => $dbPath], 'id = :id', ['id' => $empId]);
                setFlash('success', 'Photo uploaded successfully!');
            } else {
                setFlash('error', 'Failed to upload photo.');
            }
        } else {
            setFlash('error', 'Only JPG and PNG files are allowed.');
        }
    }
    redirect("index.php?page=employee/id-card&client_id={$clientId}&unit_id={$unitId}&employee_id={$empId}");
}

// Handle ID card generation (can be called before header for direct download)
if (isset($_GET['generate']) && isset($_GET['employee_id'])) {
    $empId = (int)$_GET['employee_id'];
    
    $emp = $db->fetch(
        "SELECT e.*, 
                c.name as client_name, 
                u.name as unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.id = ?",
        [$empId]
    );
    
    if (!$emp) {
        setFlash('error', 'Employee not found');
        redirect('index.php?page=employee/id-card');
    }
    
    // Check for photo - look in web root
    $photoUrl = $emp['profile_pic_url'] ?? '';
    $photoPath = null;
    
    if (!empty($photoUrl)) {
        // Path in DB starts with /, so prepend web root
        $webRoot = dirname(APP_ROOT);
        $testPath = $webRoot . $photoUrl;
        
        if (file_exists($testPath)) {
            $photoPath = $testPath;
        }
    }
    
    if (empty($photoPath)) {
        setFlash('error', 'Please upload a profile photo first.');
        redirect('index.php?page=employee/id-card&client_id=' . ($emp['client_id'] ?? 0) . '&unit_id=' . ($emp['unit_id'] ?? 0) . '&employee_id=' . $empId);
    }
    
    // Load background image - look in web root
    $bgPath = null;
    $webRoot = dirname(APP_ROOT);
    $possibleBgPaths = [
        $webRoot . '/upload/Id card format.jpeg',
        $webRoot . '/upload/id_card.jpeg',
    ];
    
    foreach ($possibleBgPaths as $path) {
        if (file_exists($path)) {
            $bgPath = $path;
            break;
        }
    }
    
    if (!$bgPath) {
        die('ID card template not found. Please upload to /upload/Id card format.jpeg');
    }
    
    $bgImage = imagecreatefromjpeg($bgPath);
    $bgWidth = imagesx($bgImage);
    $bgHeight = imagesy($bgImage);
    
    // 54mm x 86mm at 300 DPI
    $outputWidth = 638;
    $outputHeight = 1016;
    
    $finalImage = imagecreatetruecolor($outputWidth, $outputHeight);
    imagecopyresampled($finalImage, $bgImage, 0, 0, 0, 0, $outputWidth, $outputHeight, $bgWidth, $bgHeight);
    
    $black = imagecolorallocate($finalImage, 0, 0, 0);
    $darkBlue = imagecolorallocate($finalImage, 30, 60, 114);
    $white = imagecolorallocate($finalImage, 255, 255, 255);
    
    // Font paths - check if TTF fonts exist and are readable
    $fontPath = APP_ROOT . '/assets/fonts/arial.ttf';
    $fontBoldPath = APP_ROOT . '/assets/fonts/arialbd.ttf';
    $useTTF = (file_exists($fontPath) && is_readable($fontPath) && 
               file_exists($fontBoldPath) && is_readable($fontBoldPath));
    
    // Photo area
    $photoW = 160;
    $photoH = 190;
    $photoX = (int)(($outputWidth - $photoW) / 2);
    $photoY = 200;
    
    // Load photo
    $photoExt = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
    $photo = ($photoExt === 'png') ? imagecreatefrompng($photoPath) : imagecreatefromjpeg($photoPath);
    
    if (!$photo) {
        die('Failed to load photo.');
    }
    
    $photoResized = imagecreatetruecolor($photoW, $photoH);
    imagefill($photoResized, 0, 0, $white);
    
    $srcW = imagesx($photo);
    $srcH = imagesy($photo);
    $ratio = min($photoW / $srcW, $photoH / $srcH);
    $newW = (int)($srcW * $ratio);
    $newH = (int)($srcH * $ratio);
    $destX = (int)(($photoW - $newW) / 2);
    $destY = (int)(($photoH - $newH) / 2);
    
    imagecopyresampled($photoResized, $photo, $destX, $destY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagecopy($finalImage, $photoResized, $photoX, $photoY, 0, 0, $photoW, $photoH);
    imagerectangle($finalImage, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $black);
    
    // Employee name
    $empName = $emp['full_name'] ?? '';
    $nameY = (int)($photoY + $photoH + 50);
    
    if ($useTTF) {
        $nameBox = imagettfbbox(22, 0, $fontBoldPath, $empName);
        $nameWidth = $nameBox[2] - $nameBox[0];
        imagettftext($finalImage, 22, 0, (int)(($outputWidth - $nameWidth) / 2), (int)$nameY, $darkBlue, $fontBoldPath, $empName);
    } else {
        // Fallback: use larger built-in font
        $nameWidth = strlen($empName) * imagefontwidth(5);
        imagestring($finalImage, 5, (int)(($outputWidth - $nameWidth) / 2), (int)($nameY - 15), $empName, $darkBlue);
    }
    
    // Fields
    $fields = [
        'Emp Code' => $emp['employee_code'] ?? '',
        'DOB' => !empty($emp['date_of_birth']) ? date('d/m/Y', strtotime($emp['date_of_birth'])) : '',
        'DOJ' => !empty($emp['date_of_joining']) ? date('d/m/Y', strtotime($emp['date_of_joining'])) : '',
        'Blood Group' => $emp['blood_group'] ?? '',
        'Designation' => $emp['designation'] ?? '',
        'Contact' => $emp['mobile_number'] ?? '',
        'Location' => $emp['unit_name'] ?? '',
    ];
    
    $textStartY = (int)($nameY + 50);
    $lineHeight = 42;
    $labelX = 120;
    $valueX = 320;
    
    $yPos = (int)$textStartY;
    foreach ($fields as $label => $value) {
        if ($useTTF) {
            imagettftext($finalImage, 14, 0, $labelX, $yPos, $black, $fontPath, $label . ':');
            imagettftext($finalImage, 14, 0, $valueX, $yPos, $darkBlue, $fontBoldPath, $value);
        } else {
            imagestring($finalImage, 4, $labelX, $yPos - 15, $label . ':', $black);
            imagestring($finalImage, 4, $valueX, $yPos - 15, $value, $darkBlue);
        }
        $yPos += $lineHeight;
    }
    
    imagedestroy($photo);
    imagedestroy($photoResized);
    imagedestroy($bgImage);
    
    $filename = 'ID_Card_' . ($emp['employee_code'] ?? 'emp') . '.jpg';
    
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    imagejpeg($finalImage, null, 90);
    imagedestroy($finalImage);
    exit;
}

// If this is only ID card generation request, stop here (no HTML output needed)
if (isset($isIdCardGeneration)) {
    exit;
}

// Get clients
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");

// Get selected filters
$selectedClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$selectedUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$selectedEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// Get units based on client
$units = [];
if ($selectedClient) {
    $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$selectedClient]);
}

// Get employees based on unit or client
$employees = [];
if ($selectedUnit) {
    $employees = $db->fetchAll(
        "SELECT id, employee_code, full_name, profile_pic_url 
         FROM employees WHERE status = 'approved' AND unit_id = ? ORDER BY full_name",
        [$selectedUnit]
    );
} elseif ($selectedClient) {
    $employees = $db->fetchAll(
        "SELECT id, employee_code, full_name, profile_pic_url 
         FROM employees WHERE status = 'approved' AND client_id = ? ORDER BY full_name",
        [$selectedClient]
    );
}

// Get selected employee details
$selectedEmp = null;
if ($selectedEmployee) {
    $selectedEmp = $db->fetch(
        "SELECT e.*, c.name as client_name, u.name as unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.id = ?",
        [$selectedEmployee]
    );
}

// Template path for preview
$templatePath = '/upload/Id card format.jpeg';
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-person-badge me-2"></i>Generate Employee ID Card</h5>
            </div>
            <div class="card-body">
                <!-- Filters Form -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="employee/id-card">
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Employee</label>
                        <select class="form-select" name="employee_id" id="employeeSelect">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $selectedEmployee == $e['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Show
                        </button>
                    </div>
                </form>
                
                <?php if ($selectedEmp): ?>
                <!-- Employee Details -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">Employee Details</h6>
                            </div>
                            <div class="card-body text-center">
                                <?php if (!empty($selectedEmp['profile_pic_url'])): ?>
                                <img src="<?php echo sanitize($selectedEmp['profile_pic_url']); ?>" 
                                     alt="Profile" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 120px; height: 120px;">
                                    <i class="bi bi-person-fill text-white" style="font-size: 60px;"></i>
                                </div>
                                <?php endif; ?>
                                
                                <h5><?php echo sanitize($selectedEmp['full_name']); ?></h5>
                                <p class="text-muted mb-1"><?php echo sanitize($selectedEmp['designation'] ?? '-'); ?></p>
                                <p class="text-muted mb-1"><strong>Code:</strong> <?php echo sanitize($selectedEmp['employee_code']); ?></p>
                                <p class="text-muted mb-1"><strong>Unit:</strong> <?php echo sanitize($selectedEmp['unit_name'] ?? '-'); ?></p>
                                
                                <?php if (empty($selectedEmp['profile_pic_url'])): ?>
                                <hr>
                                <p class="text-danger small">Profile photo required for ID card</p>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="employee_id" value="<?php echo $selectedEmp['id']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
                                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit; ?>">
                                    <div class="mb-2">
                                        <input type="file" class="form-control form-control-sm" name="profile_photo" accept="image/jpeg,image/png" required>
                                    </div>
                                    <button type="submit" name="upload_photo" class="btn btn-success btn-sm">
                                        <i class="bi bi-upload me-1"></i>Upload Photo
                                    </button>
                                </form>
                                <?php else: ?>
                                <hr>
                                <a href="index.php?page=employee/id-card&generate=1&employee_id=<?php echo $selectedEmp['id']; ?>" class="btn btn-primary">
                                    <i class="bi bi-download me-1"></i>Download ID Card
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">ID Card Preview (54mm x 86mm)</h6>
                            </div>
                            <div class="card-body text-center">
                                <div class="border p-2 d-inline-block" style="max-width: 200px;">
                                    <img src="<?php echo $templatePath; ?>" alt="ID Card Template" style="width: 100%; opacity: 0.7;">
                                    <p class="text-muted small mt-2 mb-0">Template Preview</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">ID Card Details</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr><td>Emp Code</td><td><?php echo sanitize($selectedEmp['employee_code']); ?></td></tr>
                                    <tr><td>DOB</td><td><?php echo !empty($selectedEmp['date_of_birth']) ? date('d/m/Y', strtotime($selectedEmp['date_of_birth'])) : '-'; ?></td></tr>
                                    <tr><td>DOJ</td><td><?php echo !empty($selectedEmp['date_of_joining']) ? date('d/m/Y', strtotime($selectedEmp['date_of_joining'])) : '-'; ?></td></tr>
                                    <tr><td>Blood Group</td><td><?php echo sanitize($selectedEmp['blood_group'] ?? '-'); ?></td></tr>
                                    <tr><td>Designation</td><td><?php echo sanitize($selectedEmp['designation'] ?? '-'); ?></td></tr>
                                    <tr><td>Contact</td><td><?php echo sanitize($selectedEmp['mobile_number'] ?? '-'); ?></td></tr>
                                    <tr><td>Location</td><td><?php echo sanitize($selectedEmp['unit_name'] ?? '-'); ?></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($selectedClient): ?>
                <!-- Show employees list when client selected but no employee -->
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <?php if (count($employees) > 0): ?>
                        Found <?php echo count($employees); ?> employees. Select an employee and click Show.
                    <?php else: ?>
                        No employees found for this selection.
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Initial State -->
                <div class="text-center text-muted py-5">
                    <i class="bi bi-person-badge" style="font-size: 60px;"></i>
                    <p class="mt-3">Select Client and Employee to generate ID Card</p>
                    <p class="small">Card Size: 54mm × 86mm (Standard ID Card)</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
// Load units when client changes
document.getElementById('clientSelect').addEventListener('change', function() {
    const clientId = this.value;
    const unitSelect = document.getElementById('unitSelect');
    const employeeSelect = document.getElementById('employeeSelect');
    const form = document.getElementById('filterForm');
    
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    employeeSelect.innerHTML = '<option value="">Select Employee</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        return;
    }
    
    // Load units
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(response => response.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units && data.units.length > 0) {
                data.units.forEach(unit => {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
            // Auto-submit to load employees
            form.submit();
        })
        .catch(() => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            form.submit();
        });
});

// Auto-submit when unit changes
document.getElementById('unitSelect').addEventListener('change', function() {
    const clientId = document.getElementById('clientSelect').value;
    if (clientId) {
        document.getElementById('filterForm').submit();
    }
});

// Auto-submit when employee changes
document.getElementById('employeeSelect').addEventListener('change', function() {
    const clientId = document.getElementById('clientSelect').value;
    if (clientId && this.value) {
        document.getElementById('filterForm').submit();
    }
});
</script>
JS;
?>
