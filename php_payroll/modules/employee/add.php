<?php
/**
 * RCS HRMS Pro - Add/Edit Employee Page
 * Updated with file upload support
 * NOTE: All documents upload to web root (/uploads/...) with / prefix in database
 * 
 * Upload Paths:
 * - Profile photos: /uploads/profile/
 * - Aadhaar documents: /uploads/aadhaar/
 * - Bank documents: /uploads/bank/
 */

$pageTitle = 'Add Employee';
$employeeData = null;
$isEdit = false;

// Define upload path constants - paths stored in DB with / prefix
define('EMPLOYEE_PHOTO_UPLOAD_PATH', 'uploads/profile/');
define('EMPLOYEE_AADHAAR_UPLOAD_PATH', 'uploads/aadhaar/');
define('EMPLOYEE_BANK_UPLOAD_PATH', 'uploads/bank/');

// Check if editing
if (isset($_GET['id'])) {
    $employeeData = $employee->getById($_GET['id']);
    if ($employeeData) {
        $pageTitle = 'Edit Employee';
        $isEdit = true;
    }
}

/**
 * Handle file uploads to web root
 * @param array $file The $_FILES array element
 * @param string $uploadDir Directory to upload to (relative to web root)
 * @return array|null Result with 'path' or 'error' key, or null if no file
 */
function handleFileUpload($file, $uploadDir = 'uploads/profile/') {
    $response = null;
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'application/pdf'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return $response;
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $response;
    }
    
    // Validate file type
    if (!in_array($file['type'], $allowedTypes)) {
        $response = ['error' => 'Invalid file type. Only JPG, PNG, GIF, PDF allowed.'];
        return $response;
    }
    
    // Validate file size
    if ($file['size'] > $maxSize) {
        $response = ['error' => 'File size must be less than 5MB.'];
        return $response;
    }
    
    // Get web root (parent of APP_ROOT which is hrms folder)
    $webRoot = dirname(APP_ROOT);
    
    // Create upload directory if not exists
    $fullPath = $webRoot . '/' . $uploadDir;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    
    // Database path starts with / for web root
    $dbPath = '/' . $uploadDir . $filename;
    
    // Move uploaded file to web root
    if (move_uploaded_file($file['tmp_name'], $fullPath . $filename)) {
        $response = ['path' => $dbPath];
    } else {
        $response = ['error' => 'Failed to upload file.'];
    }
    
    return $response;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'full_name' => sanitize($_POST['full_name']),
        'father_name' => sanitize($_POST['father_name'] ?? ''),
        'mobile_number' => sanitize($_POST['mobile_number'] ?? ''),
        'alternate_mobile' => sanitize($_POST['alternate_mobile'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'gender' => sanitize($_POST['gender'] ?? ''),
        'date_of_birth' => !empty($_POST['date_of_birth']) ? date('Y-m-d', strtotime($_POST['date_of_birth'])) : null,
        'marital_status' => sanitize($_POST['marital_status'] ?? ''),
        'blood_group' => sanitize($_POST['blood_group'] ?? ''),
        'aadhaar_number' => sanitize($_POST['aadhaar_number'] ?? ''),
        'uan_number' => sanitize($_POST['uan_number'] ?? ''),
        'esic_number' => sanitize($_POST['esic_number'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'pin_code' => sanitize($_POST['pin_code'] ?? ''),
        'state' => sanitize($_POST['state'] ?? ''),
        'district' => sanitize($_POST['district'] ?? ''),
        'bank_name' => sanitize($_POST['bank_name'] ?? ''),
        'account_number' => sanitize($_POST['account_number'] ?? ''),
        'ifsc_code' => sanitize($_POST['ifsc_code'] ?? ''),
        'account_holder_name' => sanitize($_POST['account_holder_name'] ?? ''),
        'client_id' => !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null,
        'client_name' => sanitize($_POST['client_name'] ?? ''),
        'unit_id' => !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null,
        'unit_name' => sanitize($_POST['unit_name'] ?? ''),
        'designation' => sanitize($_POST['designation'] ?? ''),
        'department' => sanitize($_POST['department'] ?? ''),
        'worker_category' => sanitize($_POST['worker_category'] ?? 'Unskilled'),
        'employment_type' => sanitize($_POST['employment_type'] ?? 'Contract'),
        'date_of_joining' => !empty($_POST['date_of_joining']) ? date('Y-m-d', strtotime($_POST['date_of_joining'])) : null,
        'probation_period' => (int)($_POST['probation_period'] ?? 3),
        'nominee_name' => sanitize($_POST['nominee_name'] ?? ''),
        'nominee_relationship' => sanitize($_POST['nominee_relationship'] ?? ''),
        'nominee_dob' => !empty($_POST['nominee_dob']) ? date('Y-m-d', strtotime($_POST['nominee_dob'])) : null,
        'nominee_contact' => sanitize($_POST['nominee_contact'] ?? ''),
        'emergency_contact_name' => sanitize($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_relation' => sanitize($_POST['emergency_contact_relation'] ?? ''),
        'emergency_contact_number' => sanitize($_POST['emergency_contact_number'] ?? ''),
        'status' => 'pending_hr_verification',
        // Salary structure fields (matching database columns)
        'basic_da' => floatval($_POST['basic_da'] ?? 0),
        'hra' => floatval($_POST['hra'] ?? 0),
        'leave_encashment' => floatval($_POST['leave_encashment'] ?? 0),
        'bonus_encashment' => floatval($_POST['bonus_encashment'] ?? 0),
        'washing_allowance' => floatval($_POST['washing_allowance'] ?? 0),
        'gross_salary' => floatval($_POST['gross_salary'] ?? 0),
        'pf_applicable' => isset($_POST['pf_applicable']) ? 1 : 0,
        'esi_applicable' => isset($_POST['esi_applicable']) ? 1 : 0,
        'pt_applicable' => isset($_POST['pt_applicable']) ? 1 : 0,
        'lwf_applicable' => isset($_POST['lwf_applicable']) ? 1 : 0,
        'bonus_applicable' => isset($_POST['bonus_applicable']) ? 1 : 0,
        'gratuity_applicable' => isset($_POST['gratuity_applicable']) ? 1 : 0,
        'overtime_applicable' => isset($_POST['overtime_applicable']) ? 1 : 0,
    ];
    
    // Handle file uploads with correct paths
    if (!empty($_FILES['profile_pic']['name'])) {
        $result = handleFileUpload($_FILES['profile_pic'], EMPLOYEE_PHOTO_UPLOAD_PATH);
        if (isset($result['path'])) {
            $data['profile_pic_url'] = $result['path'];
        }
    }
    
    if (!empty($_FILES['aadhaar_front']['name'])) {
        $result = handleFileUpload($_FILES['aadhaar_front'], EMPLOYEE_AADHAAR_UPLOAD_PATH);
        if (isset($result['path'])) {
            $data['aadhaar_front_url'] = $result['path'];
        }
    }
    
    if (!empty($_FILES['aadhaar_back']['name'])) {
        $result = handleFileUpload($_FILES['aadhaar_back'], EMPLOYEE_AADHAAR_UPLOAD_PATH);
        if (isset($result['path'])) {
            $data['aadhaar_back_url'] = $result['path'];
        }
    }
    
    if (!empty($_FILES['bank_document']['name'])) {
        $result = handleFileUpload($_FILES['bank_document'], EMPLOYEE_BANK_UPLOAD_PATH);
        if (isset($result['path'])) {
            $data['bank_document_url'] = $result['path'];
        }
    }
    
    // For edit, also set status if provided
    if ($isEdit && !empty($_POST['status'])) {
        $data['status'] = sanitize($_POST['status']);
    }
    
    if ($isEdit) {
        $result = $employee->update($employeeData['id'], $data);
    } else {
        $result = $employee->create($data);
    }
    
    if (isset($result['success']) && $result['success']) {
        setFlash('success', $isEdit ? 'Employee updated successfully!' : 'Employee added successfully!');
        redirect('index.php?page=employee/view&id=' . ($isEdit ? $employeeData['id'] : $result['employee_id']));
    } else {
        setFlash('error', $result['message'] ?? 'Failed to save employee');
    }
}

// Get dropdown data
$clients = [];
try {
    $stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet - log error silently
    error_log('Failed to load clients: ' . $e->getMessage());
}

$units = [];
try {
    if ($isEdit && !empty($employeeData['client_id'])) {
        $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$employeeData['client_id']]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Failed to load units: ' . $e->getMessage());
}

// Salary components for display
$salaryComponents = [
    'basic_da' => ['label' => 'Basic + DA', 'default' => 0],
    'hra' => ['label' => 'HRA', 'default' => 0],
    'leave_encashment' => ['label' => 'Leave Encashment', 'default' => 0],
    'bonus_encashment' => ['label' => 'Bonus Encashment', 'default' => 0],
    'washing_allowance' => ['label' => 'Washing Allowance', 'default' => 0],
];

// Blood group options
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

// Gender options
$genders = ['Male', 'Female', 'Other'];

// Marital status options
$maritalStatuses = ['Single', 'Married', 'Divorced', 'Widowed'];

// Worker categories
$workerCategories = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly Skilled', 'Supervisor', 'Manager'];

// Employment types
$employmentTypes = ['Contract', 'Permanent', 'Temporary', 'Apprentice'];

// States list (Indian states)
$states = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
    'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
    'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
    'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
    'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
    'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'
];

// Nominee relationships
$nomineeRelationships = ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister', ''];
// Emergency contact relationships (includes Other)
$relationships = ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister', 'Other', ''];
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-plus me-2"></i>
                    <?php echo $isEdit ? 'Edit Employee: ' . sanitize($employeeData['full_name'] ?? '') : 'Add New Employee'; ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="employeeForm">
                    
                    <!-- Personal Information -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-person me-2"></i>Personal Information
                            </h6>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?php echo sanitize($employeeData['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="father_name" class="form-label">Father's Name</label>
                            <input type="text" class="form-control" id="father_name" name="father_name" 
                                   value="<?php echo sanitize($employeeData['father_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="mobile_number" class="form-label">Mobile Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="mobile_number" name="mobile_number" 
                                   value="<?php echo sanitize($employeeData['mobile_number'] ?? ''); ?>" 
                                   pattern="[0-9]{10}" maxlength="10" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="alternate_mobile" class="form-label">Alternate Mobile</label>
                            <input type="tel" class="form-control" id="alternate_mobile" name="alternate_mobile" 
                                   value="<?php echo sanitize($employeeData['alternate_mobile'] ?? ''); ?>" 
                                   pattern="[0-9]{10}" maxlength="10">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo sanitize($employeeData['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select" id="gender" name="gender">
                                <option value="">Select</option>
                                <?php foreach ($genders as $g): ?>
                                <option value="<?php echo $g; ?>" <?php echo ($employeeData['gender'] ?? '') == $g ? 'selected' : ''; ?>>
                                    <?php echo $g; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                   value="<?php echo $employeeData['date_of_birth'] ?? ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="marital_status" class="form-label">Marital Status</label>
                            <select class="form-select" id="marital_status" name="marital_status">
                                <option value="">Select</option>
                                <?php foreach ($maritalStatuses as $m): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($employeeData['marital_status'] ?? '') == $m ? 'selected' : ''; ?>>
                                    <?php echo $m; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="blood_group" class="form-label">Blood Group</label>
                            <select class="form-select" id="blood_group" name="blood_group">
                                <option value="">Select</option>
                                <?php foreach ($bloodGroups as $bg): ?>
                                <option value="<?php echo $bg; ?>" <?php echo ($employeeData['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>>
                                    <?php echo $bg; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="aadhaar_number" class="form-label">Aadhaar Number</label>
                            <input type="text" class="form-control" id="aadhaar_number" name="aadhaar_number" 
                                   value="<?php echo sanitize($employeeData['aadhaar_number'] ?? ''); ?>" 
                                   pattern="[0-9]{12}" maxlength="12" placeholder="12 digit Aadhaar">
                        </div>
                    </div>
                    
                    <!-- Address Information -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-geo-alt me-2"></i>Address Information
                            </h6>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"><?php echo sanitize($employeeData['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="pin_code" class="form-label">PIN Code</label>
                            <input type="text" class="form-control" id="pin_code" name="pin_code" 
                                   value="<?php echo sanitize($employeeData['pin_code'] ?? ''); ?>" 
                                   pattern="[0-9]{6}" maxlength="6">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="state" class="form-label">State</label>
                            <select class="form-select" id="state" name="state">
                                <option value="">Select</option>
                                <?php foreach ($states as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo ($employeeData['state'] ?? '') == $s ? 'selected' : ''; ?>>
                                    <?php echo $s; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="district" class="form-label">District</label>
                            <input type="text" class="form-control" id="district" name="district" 
                                   value="<?php echo sanitize($employeeData['district'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Employment Details -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-briefcase me-2"></i>Employment Details
                            </h6>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="client_id" class="form-label">Client</label>
                            <select class="form-select" id="client_id" name="client_id" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($employeeData['client_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="unit_id" class="form-label">Unit</label>
                            <select class="form-select" id="unit_id" name="unit_id">
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo ($employeeData['unit_id'] ?? '') == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="designation" class="form-label">Designation</label>
                            <input type="text" class="form-control" id="designation" name="designation" 
                                   value="<?php echo sanitize($employeeData['designation'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" class="form-control" id="department" name="department" 
                                   value="<?php echo sanitize($employeeData['department'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="worker_category" class="form-label">Worker Category</label>
                            <select class="form-select" id="worker_category" name="worker_category">
                                <?php foreach ($workerCategories as $wc): ?>
                                <option value="<?php echo $wc; ?>" <?php echo ($employeeData['worker_category'] ?? 'Unskilled') == $wc ? 'selected' : ''; ?>>
                                    <?php echo $wc; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="employment_type" class="form-label">Employment Type</label>
                            <select class="form-select" id="employment_type" name="employment_type">
                                <?php foreach ($employmentTypes as $et): ?>
                                <option value="<?php echo $et; ?>" <?php echo ($employeeData['employment_type'] ?? 'Contract') == $et ? 'selected' : ''; ?>>
                                    <?php echo $et; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_of_joining" class="form-label">Date of Joining</label>
                            <input type="date" class="form-control" id="date_of_joining" name="date_of_joining" 
                                   value="<?php echo $employeeData['date_of_joining'] ?? ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="probation_period" class="form-label">Probation Period (Months)</label>
                            <input type="number" class="form-control" id="probation_period" name="probation_period" 
                                   value="<?php echo $employeeData['probation_period'] ?? 3; ?>" min="0" max="12">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="uan_number" class="form-label">UAN Number</label>
                            <input type="text" class="form-control" id="uan_number" name="uan_number" 
                                   value="<?php echo sanitize($employeeData['uan_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="esic_number" class="form-label">ESIC Number</label>
                            <input type="text" class="form-control" id="esic_number" name="esic_number" 
                                   value="<?php echo sanitize($employeeData['esic_number'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Salary Structure -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-currency-rupee me-2"></i>Salary Structure
                            </h6>
                        </div>
                        <?php foreach ($salaryComponents as $field => $comp): ?>
                        <div class="col-md-2 mb-3">
                            <label for="<?php echo $field; ?>" class="form-label"><?php echo $comp['label']; ?></label>
                            <input type="number" class="form-control salary-component" id="<?php echo $field; ?>" 
                                   name="<?php echo $field; ?>" 
                                   value="<?php echo $employeeData[$field] ?? $comp['default']; ?>" 
                                   min="0" step="0.01">
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-2 mb-3">
                            <label for="gross_salary" class="form-label">Gross Salary</label>
                            <input type="number" class="form-control" id="gross_salary" name="gross_salary" 
                                   value="<?php echo $employeeData['gross_salary'] ?? 0; ?>" readonly>
                        </div>
                        
                        <!-- Applicability Checkboxes -->
                        <div class="col-12 mt-2">
                            <div class="row">
                                <div class="col-md-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="pf_applicable" name="pf_applicable" 
                                               <?php echo !empty($employeeData['pf_applicable']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="pf_applicable">PF Applicable</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="esi_applicable" name="esi_applicable" 
                                               <?php echo !empty($employeeData['esi_applicable']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="esi_applicable">ESI Applicable</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="pt_applicable" name="pt_applicable" 
                                               <?php echo !empty($employeeData['pt_applicable']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="pt_applicable">PT Applicable</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="bonus_applicable" name="bonus_applicable" 
                                               <?php echo !empty($employeeData['bonus_applicable']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="bonus_applicable">Bonus Applicable</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Details -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-bank me-2"></i>Bank Details
                            </h6>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                   value="<?php echo sanitize($employeeData['bank_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="account_number" class="form-label">Account Number</label>
                            <input type="text" class="form-control" id="account_number" name="account_number" 
                                   value="<?php echo sanitize($employeeData['account_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="ifsc_code" class="form-label">IFSC Code</label>
                            <input type="text" class="form-control" id="ifsc_code" name="ifsc_code" 
                                   value="<?php echo sanitize($employeeData['ifsc_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="account_holder_name" class="form-label">Account Holder Name</label>
                            <input type="text" class="form-control" id="account_holder_name" name="account_holder_name" 
                                   value="<?php echo sanitize($employeeData['account_holder_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Documents Upload -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-file-earmark me-2"></i>Documents
                            </h6>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="profile_pic" class="form-label">Profile Photo</label>
                            <?php if (!empty($employeeData['profile_pic_url'])): ?>
                            <div class="mb-2">
                                <img id="empProfilePhoto" src="<?php echo sanitize($employeeData['profile_pic_url']); ?>" 
                                     alt="Profile photo" class="img-thumbnail" style="max-height: 100px;">
                            </div>
                            <?php if ($isEdit): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary mb-2" onclick="editEmpProfilePhoto()">
                                <i class="bi bi-crop me-1"></i>Edit Photo
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="profile_pic" name="profile_pic" accept="image/*">
                            <small class="text-muted">JPG, PNG (max 5MB) - Saved to /uploads/profile/</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="aadhaar_front" class="form-label">Aadhaar Front</label>
                            <?php if (!empty($employeeData['aadhaar_front_url'])): ?>
                            <div class="mb-2">
                                <a href="<?php echo sanitize($employeeData['aadhaar_front_url']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="aadhaar_front" name="aadhaar_front" accept="image/*,.pdf">
                            <small class="text-muted">JPG, PNG, PDF (max 5MB)</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="aadhaar_back" class="form-label">Aadhaar Back</label>
                            <?php if (!empty($employeeData['aadhaar_back_url'])): ?>
                            <div class="mb-2">
                                <a href="<?php echo sanitize($employeeData['aadhaar_back_url']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="aadhaar_back" name="aadhaar_back" accept="image/*,.pdf">
                            <small class="text-muted">JPG, PNG, PDF (max 5MB)</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="bank_document" class="form-label">Bank Passbook</label>
                            <?php if (!empty($employeeData['bank_document_url'])): ?>
                            <div class="mb-2">
                                <a href="<?php echo sanitize($employeeData['bank_document_url']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="bank_document" name="bank_document" accept="image/*,.pdf">
                            <small class="text-muted">JPG, PNG, PDF (max 5MB)</small>
                        </div>
                    </div>
                    
                    <!-- Nominee Details -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-people me-2"></i>Nominee Details
                            </h6>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="nominee_name" class="form-label">Nominee Name</label>
                            <input type="text" class="form-control" id="nominee_name" name="nominee_name" 
                                   value="<?php echo sanitize($employeeData['nominee_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="nominee_relationship" class="form-label">Relationship</label>
                            <select class="form-select" id="nominee_relationship" name="nominee_relationship">
                                <?php foreach ($nomineeRelationships as $r): ?>
                                <option value="<?php echo $r; ?>" <?php echo ($employeeData['nominee_relationship'] ?? '') == $r ? 'selected' : ''; ?>>
                                    <?php echo $r ?: 'Select'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="nominee_dob" class="form-label">Nominee DOB</label>
                            <input type="date" class="form-control" id="nominee_dob" name="nominee_dob" 
                                   value="<?php echo $employeeData['nominee_dob'] ?? ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="nominee_contact" class="form-label">Nominee Contact</label>
                            <input type="tel" class="form-control" id="nominee_contact" name="nominee_contact" 
                                   value="<?php echo sanitize($employeeData['nominee_contact'] ?? ''); ?>" 
                                   pattern="[0-9]{10}" maxlength="10">
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-telephone me-2"></i>Emergency Contact
                            </h6>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_name" class="form-label">Contact Name</label>
                            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" 
                                   value="<?php echo sanitize($employeeData['emergency_contact_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_relation" class="form-label">Relationship</label>
                            <select class="form-select" id="emergency_contact_relation" name="emergency_contact_relation">
                                <?php foreach ($relationships as $r): ?>
                                <option value="<?php echo $r; ?>" <?php echo ($employeeData['emergency_contact_relation'] ?? '') == $r ? 'selected' : ''; ?>>
                                    <?php echo $r ?: 'Select'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_number" class="form-label">Contact Number</label>
                            <input type="tel" class="form-control" id="emergency_contact_number" name="emergency_contact_number" 
                                   value="<?php echo sanitize($employeeData['emergency_contact_number'] ?? ''); ?>" 
                                   pattern="[0-9]{10}" maxlength="10">
                        </div>
                    </div>
                    
                    <?php if ($isEdit): ?>
                    <!-- Status (Edit Only) -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="pending_hr_verification" <?php echo ($employeeData['status'] ?? '') == 'pending_hr_verification' ? 'selected' : ''; ?>>Pending HR Verification</option>
                                <option value="approved" <?php echo ($employeeData['status'] ?? '') == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo ($employeeData['status'] ?? '') == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="inactive" <?php echo ($employeeData['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Submit Buttons -->
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>
                                <?php echo $isEdit ? 'Update Employee' : 'Add Employee'; ?>
                            </button>
                            <a href="index.php?page=employee/list" class="btn btn-secondary ms-2">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Unit dropdown population on client change
document.getElementById('client_id')?.addEventListener('change', function() {
    const clientId = this.value;
    const unitSelect = document.getElementById('unit_id');
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (clientId) {
        fetch('index.php?page=api/units&client_id=' + clientId)
            .then(r => r.json())
            .then(data => {
                let html = '<option value="">Select Unit</option>';
                (data.units || []).forEach(u => {
                    html += `<option value="${u.id}">${u.name}</option>`;
                });
                unitSelect.innerHTML = html;
            })
            .catch(() => {
                unitSelect.innerHTML = '<option value="">Select Unit</option>';
            });
    } else {
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
    }
});

// Calculate gross salary
function calculateGross() {
    const components = document.querySelectorAll('.salary-component');
    let total = 0;
    components.forEach(c => {
        total += parseFloat(c.value) || 0;
    });
    document.getElementById('gross_salary').value = total.toFixed(2);
}

// Add event listeners to salary components
document.querySelectorAll('.salary-component').forEach(input => {
    input.addEventListener('input', calculateGross);
});

// Edit profile photo with lite editor (only available in edit mode)
function editEmpProfilePhoto() {
    const photoImg = document.getElementById('empProfilePhoto');
    if (!photoImg || !photoImg.src) return;
    
    const photoUrl = '<?php echo sanitize($employeeData['profile_pic_url'] ?? ''); ?>';
    
    openLiteEditor(photoImg.src + '?t=' + Date.now(), function(base64DataUrl) {
        const formData = new FormData();
        formData.append('action', 'save_canvas');
        formData.append('file', photoUrl);
        formData.append('image_data', base64DataUrl);
        
        fetch('?page=api/image-tool&ie_action=save_canvas', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                const newSrc = (res.rel ? '/' + res.rel : photoUrl) + '?t=' + Date.now();
                photoImg.src = newSrc;
                alert('Photo saved successfully!');
            } else {
                alert('Save failed: ' + (res.msg || 'Unknown error'));
            }
        })
        .catch(err => alert('Error saving photo: ' + err.message));
    });
}

// Initial calculation
calculateGross();
</script>

<?php if ($isEdit): ?>
<?php include_once 'modules/settings/image-tool-lite.php'; ?>
<?php endif; ?>
