<?php
/**
 * RCS HRMS Pro - Employee ID Card Generator
 * Card Size: 54mm x 86mm
 * 
 * Image Paths (matching employee add/edit):
 * - Profile photos: /uploads/profile/
 * - Aadhaar: /uploads/aadhaar/
 * - Bank: /uploads/bank/
 */

// Handle preview BEFORE any output
$webRoot = dirname(APP_ROOT);

// Helper function to find photo file
function findPhotoFile($photoUrl) {
    global $webRoot;
    if (empty($photoUrl)) return null;
    
    $cleanUrl = ltrim($photoUrl, '/');
    
    $paths = [
        $webRoot . '/' . $cleanUrl,
        $webRoot . $photoUrl,
        APP_ROOT . '/' . $cleanUrl,
        $_SERVER['DOCUMENT_ROOT'] . '/' . $cleanUrl,
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) return $path;
    }
    
    return null;
}

// Handle ID card preview generation
if (isset($_GET['preview']) && isset($_GET['employee_id'])) {
    $empId = (int)$_GET['employee_id'];
    
    $emp = $db->fetch(
        "SELECT e.*, c.name as client_name, u.name as unit_name, u.city as unit_city
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.id = ?",
        [$empId]
    );
    
    $bgPath = null;
    $possibleBgPaths = [
        $webRoot . '/upload/Id card format.jpeg',
        $webRoot . '/upload/id_card.jpeg',
        APP_ROOT . '/upload/Id card format.jpeg',
    ];
    foreach ($possibleBgPaths as $path) {
        if (file_exists($path)) { $bgPath = $path; break; }
    }
    
    if (!$bgPath) {
        header('Content-Type: image/png');
        $img = imagecreatetruecolor(638, 1016);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, 5, 200, 500, 'Template not found', $black);
        imagepng($img);
        imagedestroy($img);
        exit;
    }
    
    $bgImage = imagecreatefromjpeg($bgPath);
    $bgWidth = imagesx($bgImage);
    $bgHeight = imagesy($bgImage);
    
    $outputWidth = 638;
    $outputHeight = 1016;
    
    $finalImage = imagecreatetruecolor($outputWidth, $outputHeight);
    imagecopyresampled($finalImage, $bgImage, 0, 0, 0, 0, $outputWidth, $outputHeight, $bgWidth, $bgHeight);
    
    $black = imagecolorallocate($finalImage, 0, 0, 0);
    $darkBlue = imagecolorallocate($finalImage, 30, 60, 114);
    $white = imagecolorallocate($finalImage, 255, 255, 255);
    
    $fontPath = APP_ROOT . '/assets/fonts/arial.ttf';
    $fontBoldPath = APP_ROOT . '/assets/fonts/arialbd.ttf';
    $useTTF = (file_exists($fontPath) && is_readable($fontPath) && file_exists($fontBoldPath) && is_readable($fontBoldPath));
    
    $photoW = 220; 
    $photoH = 260;
    $photoX = (int)(($outputWidth - $photoW) / 2);
    $photoY = 200;
    
    $photoUrl = $emp['profile_pic_url'] ?? '';
    $photoPath = findPhotoFile($photoUrl);
    
    if ($photoPath) {
        $photoExt = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
        $photo = ($photoExt === 'png') ? imagecreatefrompng($photoPath) : imagecreatefromjpeg($photoPath);
        
        if ($photo) {
            $photoResized = imagecreatetruecolor($photoW, $photoH);
            imagefill($photoResized, 0, 0, $white);
            $srcW = imagesx($photo); $srcH = imagesy($photo);
            imagecopyresampled($photoResized, $photo, 0, 0, 0, 0, $photoW, $photoH, $srcW, $srcH);
            imagecopy($finalImage, $photoResized, $photoX, $photoY, 0, 0, $photoW, $photoH);
            imagerectangle($finalImage, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $black);
            imagedestroy($photo); imagedestroy($photoResized);
        }
    } else {
        $noPhoto = imagecreatetruecolor($photoW, $photoH);
        $gray = imagecolorallocate($noPhoto, 220, 220, 220);
        imagefill($noPhoto, 0, 0, $gray);
        $photoGray = imagecolorallocate($noPhoto, 180, 180, 180);
        imagestring($noPhoto, 3, 50, 95, 'No Photo', $photoGray);
        imagecopy($finalImage, $noPhoto, $photoX, $photoY, 0, 0, $photoW, $photoH);
        imagerectangle($finalImage, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $black);
        imagedestroy($noPhoto);
    }
    
    $empName = $emp['full_name'] ?? '';
    $nameY = (int)($photoY + $photoH + 40);
    
    if ($useTTF) {
        $nameBox = imagettfbbox(26, 0, $fontBoldPath, $empName);
        $nameWidth = $nameBox[2] - $nameBox[0];
        imagettftext($finalImage, 26, 0, (int)(($outputWidth - $nameWidth) / 2), $nameY, $darkBlue, $fontBoldPath, $empName);
    } else {
        imagestring($finalImage, 5, (int)(($outputWidth - strlen($empName) * 9) / 2), $nameY - 18, $empName, $darkBlue);
    }
    
    // Fields - Unit City instead of Location
    $fields = [
        'Emp Code' => $emp['employee_code'] ?? '',
        'DOB' => !empty($emp['date_of_birth']) ? date('d/m/Y', strtotime($emp['date_of_birth'])) : '',
        'DOJ' => !empty($emp['date_of_joining']) ? date('d/m/Y', strtotime($emp['date_of_joining'])) : '',
        'Blood Group' => $emp['blood_group'] ?? '',
        'Designation' => $emp['designation'] ?? '',
        'Contact' => $emp['mobile_number'] ?? '',
        'Unit City' => $emp['unit_city'] ?? $emp['unit_name'] ?? '',
    ];
    
    $yPos = (int)($nameY + 48);
    foreach ($fields as $label => $value) {
        if ($useTTF) {
            imagettftext($finalImage, 22, 0, 110, $yPos, $black, $fontBoldPath, $label . ':');
            imagettftext($finalImage, 22, 0, 320, $yPos, $darkBlue, $fontBoldPath, $value);
        } else {
            imagestring($finalImage, 5, 110, $yPos - 18, $label . ':', $black);
            imagestring($finalImage, 5, 320, $yPos - 18, $value, $darkBlue);
        }
        $yPos += 38;
    }
    
    imagedestroy($bgImage);
    
    header('Content-Type: image/jpeg');
    imagejpeg($finalImage, null, 90);
    imagedestroy($finalImage);
    exit;
}

// Handle ID card download
if (isset($_GET['generate']) && isset($_GET['employee_id'])) {
    $empId = (int)$_GET['employee_id'];
    
    $emp = $db->fetch(
        "SELECT e.*, c.name as client_name, u.name as unit_name, u.city as unit_city
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.id = ?",
        [$empId]
    );
    
    if (!$emp) die('Employee not found');
    
    $photoUrl = $emp['profile_pic_url'] ?? '';
    $photoPath = findPhotoFile($photoUrl);
    
    if (empty($photoPath)) {
        header('Content-Type: text/html');
        echo '<h3>Photo not found</h3>';
        echo '<p>Photo URL in database: ' . htmlspecialchars($photoUrl ?: 'empty') . '</p>';
        echo '<p>Expected path: ' . htmlspecialchars($webRoot . '/' . ltrim($photoUrl, '/')) . '</p>';
        echo '<p>Please upload a profile photo first.</p>';
        echo '<p><a href="index.php?page=employee/id-card&client_id=' . ($emp['client_id'] ?? 0) . '&unit_id=' . ($emp['unit_id'] ?? 0) . '&employee_id=' . $empId . '">Go back</a></p>';
        exit;
    }
    
    $bgPath = null;
    $possibleBgPaths = [
        $webRoot . '/upload/Id card format.jpeg',
        $webRoot . '/upload/id_card.jpeg',
        APP_ROOT . '/upload/Id card format.jpeg',
    ];
    foreach ($possibleBgPaths as $path) {
        if (file_exists($path)) { $bgPath = $path; break; }
    }
    
    if (!$bgPath) die('ID card template not found.');
    
    $bgImage = imagecreatefromjpeg($bgPath);
    $bgWidth = imagesx($bgImage);
    $bgHeight = imagesy($bgImage);
    
    $outputWidth = 638;
    $outputHeight = 1016;
    
    $finalImage = imagecreatetruecolor($outputWidth, $outputHeight);
    imagecopyresampled($finalImage, $bgImage, 0, 0, 0, 0, $outputWidth, $outputHeight, $bgWidth, $bgHeight);
    
    $black = imagecolorallocate($finalImage, 0, 0, 0);
    $darkBlue = imagecolorallocate($finalImage, 30, 60, 114);
    $white = imagecolorallocate($finalImage, 255, 255, 255);
    
    $fontPath = APP_ROOT . '/assets/fonts/arial.ttf';
    $fontBoldPath = APP_ROOT . '/assets/fonts/arialbd.ttf';
    $useTTF = (file_exists($fontPath) && is_readable($fontPath) && file_exists($fontBoldPath) && is_readable($fontBoldPath));
    
    $photoW = 220; 
    $photoH = 260;
    $photoX = (int)(($outputWidth - $photoW) / 2);
    $photoY = 200;
    
    $photoExt = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
    $photo = ($photoExt === 'png') ? imagecreatefrompng($photoPath) : imagecreatefromjpeg($photoPath);
    
    if ($photo) {
        $photoResized = imagecreatetruecolor($photoW, $photoH);
        imagefill($photoResized, 0, 0, $white);
        $srcW = imagesx($photo); $srcH = imagesy($photo);
        imagecopyresampled($photoResized, $photo, 0, 0, 0, 0, $photoW, $photoH, $srcW, $srcH);
        imagecopy($finalImage, $photoResized, $photoX, $photoY, 0, 0, $photoW, $photoH);
        imagerectangle($finalImage, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $black);
        imagedestroy($photo); imagedestroy($photoResized);
    }
    
    $empName = $emp['full_name'] ?? '';
    $nameY = (int)($photoY + $photoH + 40);
    
    if ($useTTF) {
        $nameBox = imagettfbbox(26, 0, $fontBoldPath, $empName);
        $nameWidth = $nameBox[2] - $nameBox[0];
        imagettftext($finalImage, 26, 0, (int)(($outputWidth - $nameWidth) / 2), $nameY, $darkBlue, $fontBoldPath, $empName);
    } else {
        imagestring($finalImage, 5, (int)(($outputWidth - strlen($empName) * 9) / 2), $nameY - 18, $empName, $darkBlue);
    }
    
    $fields = [
        'Emp Code' => $emp['employee_code'] ?? '',
        'DOB' => !empty($emp['date_of_birth']) ? date('d/m/Y', strtotime($emp['date_of_birth'])) : '',
        'DOJ' => !empty($emp['date_of_joining']) ? date('d/m/Y', strtotime($emp['date_of_joining'])) : '',
        'Blood Group' => $emp['blood_group'] ?? '',
        'Designation' => $emp['designation'] ?? '',
        'Contact' => $emp['mobile_number'] ?? '',
        'Unit City' => $emp['unit_city'] ?? $emp['unit_name'] ?? '',
    ];
    
    $yPos = (int)($nameY + 48);
    foreach ($fields as $label => $value) {
        if ($useTTF) {
            imagettftext($finalImage, 22, 0, 110, $yPos, $black, $fontBoldPath, $label . ':');
            imagettftext($finalImage, 22, 0, 320, $yPos, $darkBlue, $fontBoldPath, $value);
        } else {
            imagestring($finalImage, 5, 110, $yPos - 18, $label . ':', $black);
            imagestring($finalImage, 5, 320, $yPos - 18, $value, $darkBlue);
        }
        $yPos += 38;
    }
    
    imagedestroy($bgImage);
    
    $filename = 'ID_Card_' . ($emp['full_name'] ?? 'emp') . '.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    imagejpeg($finalImage, null, 90);
    imagedestroy($finalImage);
    exit;
}

$pageTitle = 'Generate ID Card';

// Handle inline edit
if (isset($_POST['update_details']) && isset($_POST['employee_id'])) {
    $empId = (int)$_POST['employee_id'];
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    
    $updateData = [];
    
    if (isset($_POST['full_name'])) $updateData['full_name'] = sanitize($_POST['full_name']);
    if (isset($_POST['employee_code'])) $updateData['employee_code'] = sanitize($_POST['employee_code']);
    if (isset($_POST['date_of_birth']) && !empty($_POST['date_of_birth'])) {
        $updateData['date_of_birth'] = date('Y-m-d', strtotime($_POST['date_of_birth']));
    }
    if (isset($_POST['date_of_joining']) && !empty($_POST['date_of_joining'])) {
        $updateData['date_of_joining'] = date('Y-m-d', strtotime($_POST['date_of_joining']));
    }
    if (isset($_POST['blood_group'])) $updateData['blood_group'] = sanitize($_POST['blood_group']);
    if (isset($_POST['designation'])) $updateData['designation'] = sanitize($_POST['designation']);
    if (isset($_POST['mobile_number'])) $updateData['mobile_number'] = sanitize($_POST['mobile_number']);
    
    if (!empty($updateData)) {
        $db->update('employees', $updateData, 'id = :id', ['id' => $empId]);
        setFlash('success', 'Details updated successfully!');
    }
    
    redirect("index.php?page=employee/id-card&client_id={$clientId}&unit_id={$unitId}&employee_id={$empId}");
}

// Handle photo upload - SAME PATH AS EMPLOYEE ADD/EDIT
if (isset($_POST['upload_photo']) && isset($_POST['employee_id'])) {
    $empId = (int)$_POST['employee_id'];
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $fileType = $_FILES['profile_photo']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $ext = ($fileType === 'image/png') ? 'png' : 'jpg';
            
            // Upload to /uploads/profile/ - SAME AS EMPLOYEE ADD/EDIT
            $uploadDir = 'uploads/profile/';
            $filename = uniqid() . '_' . time() . '.' . $ext;
            
            $fullPath = $webRoot . '/' . $uploadDir;
            if (!is_dir($fullPath)) mkdir($fullPath, 0755, true);
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $fullPath . $filename)) {
                // Database path with / prefix
                $dbPath = '/' . $uploadDir . $filename;
                
                $db->update('employees', [
                    'profile_pic_url' => $dbPath
                ], 'id = :id', ['id' => $empId]);
                
                setFlash('success', 'Photo uploaded successfully!');
            } else {
                setFlash('error', 'Failed to upload photo.');
            }
        } else {
            setFlash('error', 'Only JPG and PNG files are allowed.');
        }
    } else {
        setFlash('error', 'Please select a photo to upload.');
    }
    
    redirect("index.php?page=employee/id-card&client_id={$clientId}&unit_id={$unitId}&employee_id={$empId}");
}

// Get data
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$selectedClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$selectedUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$selectedEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

$units = [];
if ($selectedClient) {
    $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$selectedClient]);
}

$employees = [];
if ($selectedUnit) {
    $employees = $db->fetchAll("SELECT id, employee_code, full_name, profile_pic_url FROM employees WHERE status = 'approved' AND unit_id = ? ORDER BY full_name", [$selectedUnit]);
} elseif ($selectedClient) {
    $employees = $db->fetchAll("SELECT id, employee_code, full_name, profile_pic_url FROM employees WHERE status = 'approved' AND client_id = ? ORDER BY full_name", [$selectedClient]);
}

$selectedEmp = null;
if ($selectedEmployee) {
    $selectedEmp = $db->fetch(
        "SELECT e.*, c.name as client_name, u.name as unit_name, u.city as unit_city 
         FROM employees e 
         LEFT JOIN clients c ON e.client_id = c.id 
         LEFT JOIN units u ON e.unit_id = u.id 
         WHERE e.id = ?", 
        [$selectedEmployee]
    );
}

$hasPhoto = false;
$photoUrl = '';
if ($selectedEmp) {
    $photoUrl = $selectedEmp['profile_pic_url'] ?? '';
    $hasPhoto = !empty($photoUrl) && findPhotoFile($photoUrl);
}
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-person-badge me-2"></i>Generate Employee ID Card</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="employee/id-card">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Employee</label>
                        <select class="form-select" name="employee_id" id="employeeSelect">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $selectedEmployee == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Show</button>
                    </div>
                </form>
                
                <?php if ($selectedEmp): ?>
                <div class="row">
                    <!-- Photo Section -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Profile Photo</h6>
                                <?php if ($hasPhoto): ?>
                                <span class="badge bg-success">Photo Available</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">No Photo</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body text-center">
                                <?php if ($hasPhoto): ?>
                                <img id="idCardPhoto" src="<?php echo sanitize($photoUrl); ?>?t=<?php echo time(); ?>" 
                                     data-photo-path="<?php echo sanitize($photoUrl); ?>"
                                     class="img-thumbnail mb-3" 
                                     style="max-height: 250px; width: auto;"
                                     alt="Profile Photo">
                                <div class="d-flex gap-2 mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="editIdCardPhoto()">
                                        <i class="bi bi-crop me-1"></i>Edit Photo
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center mb-3" style="height: 250px;">
                                    <div class="text-center text-muted">
                                        <i class="bi bi-person" style="font-size: 64px;"></i>
                                        <p class="mb-0 mt-2">No Photo Uploaded</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Simple Upload Form -->
                                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                    <input type="hidden" name="employee_id" value="<?php echo $selectedEmp['id']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
                                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit; ?>">
                                    
                                    <div class="input-group mb-2">
                                        <input type="file" class="form-control" name="profile_photo" id="photoInput" accept="image/jpeg,image/png">
                                        <button type="submit" name="upload_photo" class="btn btn-primary">
                                            <i class="bi bi-upload me-1"></i>Upload
                                        </button>
                                    </div>
                                    <small class="text-muted">Upload JPG or PNG (max 5MB)</small>
                                </form>
                                
                                <?php if ($hasPhoto): ?>
                                <hr>
                                <a href="index.php?page=employee/id-card&generate=1&employee_id=<?php echo $selectedEmp['id']; ?>" 
                                   class="btn btn-success w-100" target="_blank">
                                    <i class="bi bi-download me-1"></i>Download ID Card
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preview & Edit Section -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">ID Card Preview</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshPreview()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                            </div>
                            <div class="card-body text-center">
                                <img id="idCardPreview" 
                                     src="index.php?page=employee/id-card&preview=1&employee_id=<?php echo $selectedEmp['id']; ?>&t=<?php echo time(); ?>" 
                                     style="max-width: 100%; max-height: 400px; border: 1px solid #dee2e6; border-radius: 8px;" 
                                     alt="ID Card Preview">
                            </div>
                            <div class="card-footer">
                                <form method="POST" id="detailsForm">
                                    <input type="hidden" name="update_details" value="1">
                                    <input type="hidden" name="employee_id" value="<?php echo $selectedEmp['id']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
                                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit; ?>">
                                    
                                    <h6 class="text-primary mb-3"><i class="bi bi-pencil-square me-1"></i>Edit ID Card Details</h6>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Employee Code</label>
                                            <input type="text" name="employee_code" class="form-control" 
                                                   value="<?php echo sanitize($selectedEmp['employee_code'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Full Name</label>
                                            <input type="text" name="full_name" class="form-control" 
                                                   value="<?php echo sanitize($selectedEmp['full_name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Date of Birth</label>
                                            <input type="date" name="date_of_birth" class="form-control" 
                                                   value="<?php echo $selectedEmp['date_of_birth'] ?? ''; ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Date of Joining</label>
                                            <input type="date" name="date_of_joining" class="form-control" 
                                                   value="<?php echo $selectedEmp['date_of_joining'] ?? ''; ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Blood Group</label>
                                            <select name="blood_group" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                                <option value="<?php echo $bg; ?>" <?php echo ($selectedEmp['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Designation</label>
                                            <input type="text" name="designation" class="form-control" 
                                                   value="<?php echo sanitize($selectedEmp['designation'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Contact Number</label>
                                            <input type="text" name="mobile_number" class="form-control" 
                                                   value="<?php echo sanitize($selectedEmp['mobile_number'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Unit City</label>
                                            <input type="text" class="form-control bg-light" 
                                                   value="<?php echo sanitize($selectedEmp['unit_city'] ?? $selectedEmp['unit_name'] ?? '-'); ?>" 
                                                   disabled title="City is set from Unit settings">
                                            <small class="text-muted">Managed in Unit settings</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 d-flex gap-2">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-save me-1"></i>Save Changes
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="refreshPreview()">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh Preview
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($selectedClient): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <?php if (count($employees) > 0): ?>Found <?php echo count($employees); ?> employees. Select an employee to generate ID card.<?php else: ?>No employees found for selected criteria.<?php endif; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-person-badge" style="font-size: 60px;"></i>
                    <p class="mt-3">Select Client and Employee to generate ID card</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Filter handlers
document.getElementById('clientSelect')?.addEventListener('change', function() {
    const cid = this.value;
    document.getElementById('unitSelect').innerHTML = '<option value="">Loading...</option>';
    document.getElementById('employeeSelect').innerHTML = '<option value="">Select Employee</option>';
    if (cid) {
        fetch('index.php?page=api/units&client_id=' + cid)
            .then(r => r.json())
            .then(data => {
                let html = '<option value="">All Units</option>';
                (data.units || []).forEach(u => html += `<option value="${u.id}">${u.name}</option>`);
                document.getElementById('unitSelect').innerHTML = html;
                document.getElementById('filterForm').submit();
            });
    }
});

document.getElementById('unitSelect')?.addEventListener('change', function() {
    if (document.getElementById('clientSelect').value) document.getElementById('filterForm').submit();
});

document.getElementById('employeeSelect')?.addEventListener('change', function() {
    if (document.getElementById('clientSelect').value && this.value) document.getElementById('filterForm').submit();
});

function refreshPreview() {
    const img = document.getElementById('idCardPreview');
    const empId = document.querySelector('input[name="employee_id"]')?.value;
    if (img && empId) {
        img.src = 'index.php?page=employee/id-card&preview=1&employee_id=' + empId + '&t=' + Date.now();
    }
}

// Edit photo with lite editor
function editIdCardPhoto() {
    var photoImg = document.getElementById('idCardPhoto');
    if (!photoImg) return;
    var empId = document.querySelector('input[name="employee_id"]');
    if (!empId) return;
    
    // Get the raw photo URL (with /uploads/ prefix) - safe_path() on server handles stripping
    var rawUrl = photoImg.getAttribute('data-photo-path') || photoImg.src;
    
    openLiteEditor(photoImg.src, function(base64DataUrl) {
        var formData = new FormData();
        formData.append('action', 'save_canvas');
        formData.append('file', rawUrl);
        formData.append('image_data', base64DataUrl);
        
        fetch('?page=api/image-tool&ie_action=save_canvas', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) {
                // res.rel already contains uploads/ prefix (e.g. "uploads/profile/xxx.jpg")
                photoImg.src = '/' + (res.rel || rawUrl.split('?')[0].replace(/^\//, '')) + '?t=' + Date.now();
                refreshPreview();
                alert('Photo saved successfully!');
            } else {
                alert('Save failed: ' + (res.msg || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Error saving photo: ' + err.message); });
    });
}
</script>

<?php include_once 'modules/settings/image-tool-lite.php'; ?>
