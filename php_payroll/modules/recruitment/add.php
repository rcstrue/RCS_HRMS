<?php
/**
 * RCS HRMS Pro - Add Candidate/Applicant
 * Manpower Supplier - Add Job Applicant
 */
$pageTitle = 'Add Candidate';
$errors = [];

$applicant = [
    'full_name' => '',
    'father_name' => '',
    'date_of_birth' => '',
    'gender' => 'male',
    'mobile_number' => '',
    'email' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'aadhaar_number' => '',
    'pan_number' => '',
    'qualification' => '',
    'experience_years' => 0,
    'current_employer' => '',
    'current_salary' => '',
    'expected_salary' => '',
    'skill_category' => 'unskilled',
    'preferred_location' => '',
    'source' => 'walk-in',
    'source_reference' => '',
    'requisition_id' => isset($_GET['requisition_id']) ? (int)$_GET['requisition_id'] : ''
];

// Get open requisitions
$requisitions = $db->query("SELECT r.id, r.requisition_number, r.designation, c.name as client_name 
    FROM manpower_requisitions r 
    LEFT JOIN clients c ON r.client_id = c.id 
    WHERE r.status IN ('pending', 'approved', 'in_progress') AND (r.quantity - r.filled_quantity) > 0
    ORDER BY r.required_by_date")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicant['full_name'] = sanitize($_POST['full_name']);
    $applicant['father_name'] = sanitize($_POST['father_name'] ?? '');
    $applicant['date_of_birth'] = sanitize($_POST['date_of_birth'] ?? '');
    $applicant['gender'] = sanitize($_POST['gender'] ?? 'male');
    $applicant['mobile_number'] = sanitize($_POST['mobile_number']);
    $applicant['email'] = sanitize($_POST['email'] ?? '');
    $applicant['address'] = sanitize($_POST['address'] ?? '');
    $applicant['city'] = sanitize($_POST['city'] ?? '');
    $applicant['state'] = sanitize($_POST['state'] ?? '');
    $applicant['pincode'] = sanitize($_POST['pincode'] ?? '');
    $applicant['aadhaar_number'] = sanitize($_POST['aadhaar_number'] ?? '');
    $applicant['pan_number'] = sanitize($_POST['pan_number'] ?? '');
    $applicant['qualification'] = sanitize($_POST['qualification'] ?? '');
    $applicant['experience_years'] = (int)($_POST['experience_years'] ?? 0);
    $applicant['current_employer'] = sanitize($_POST['current_employer'] ?? '');
    $applicant['current_salary'] = !empty($_POST['current_salary']) ? (float)$_POST['current_salary'] : null;
    $applicant['expected_salary'] = !empty($_POST['expected_salary']) ? (float)$_POST['expected_salary'] : null;
    $applicant['skill_category'] = sanitize($_POST['skill_category'] ?? 'unskilled');
    $applicant['preferred_location'] = sanitize($_POST['preferred_location'] ?? '');
    $applicant['source'] = sanitize($_POST['source'] ?? 'walk-in');
    $applicant['source_reference'] = sanitize($_POST['source_reference'] ?? '');
    $applicant['requisition_id'] = !empty($_POST['requisition_id']) ? (int)$_POST['requisition_id'] : null;
    
    // Validate
    if (empty($applicant['full_name'])) {
        $errors[] = 'Full name is required';
    }
    if (empty($applicant['mobile_number'])) {
        $errors[] = 'Mobile number is required';
    }
    
    // Check duplicate mobile
    $stmt = $db->prepare("SELECT COUNT(*) FROM job_applicants WHERE mobile_number = ?");
    $stmt->execute([$applicant['mobile_number']]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'An applicant with this mobile number already exists';
    }
    
    if (empty($errors)) {
        try {
            // Generate applicant code
            $prefix = 'CAN';
            $year = date('Y');
            $stmt = $db->query("SELECT MAX(id) as max_id FROM job_applicants");
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
            $applicant_code = $prefix . $year . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO job_applicants 
                (applicant_code, full_name, father_name, date_of_birth, gender, mobile_number, email,
                address, city, state, pincode, aadhaar_number, pan_number, qualification, 
                experience_years, current_employer, current_salary, expected_salary, skill_category,
                preferred_location, source, source_reference, requisition_id, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?)");
            
            $stmt->execute([
                $applicant_code,
                $applicant['full_name'],
                $applicant['father_name'],
                $applicant['date_of_birth'],
                $applicant['gender'],
                $applicant['mobile_number'],
                $applicant['email'],
                $applicant['address'],
                $applicant['city'],
                $applicant['state'],
                $applicant['pincode'],
                $applicant['aadhaar_number'],
                $applicant['pan_number'],
                $applicant['qualification'],
                $applicant['experience_years'],
                $applicant['current_employer'],
                $applicant['current_salary'],
                $applicant['expected_salary'],
                $applicant['skill_category'],
                $applicant['preferred_location'],
                $applicant['source'],
                $applicant['source_reference'],
                $applicant['requisition_id'],
                $_SESSION['user_id']
            ]);
            
            setFlash('success', "Candidate {$applicant_code} added successfully");
            redirect('index.php?page=recruitment/list');
            
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=recruitment/list">Recruitment</a></li>
                    <li class="breadcrumb-item active">Add Candidate</li>
                </ol>
            </nav>
            <h1 class="page-title">Add New Candidate</h1>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo $error; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <div class="row">
        <div class="col-lg-8">
            <!-- Personal Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Personal Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Full Name</label>
                            <input type="text" name="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Father's Name</label>
                            <input type="text" name="father_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['father_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['date_of_birth'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="male" <?php echo $applicant['gender'] == 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $applicant['gender'] == 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo $applicant['gender'] == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Skill Category</label>
                            <select name="skill_category" class="form-select">
                                <option value="unskilled" <?php echo $applicant['skill_category'] == 'unskilled' ? 'selected' : ''; ?>>Unskilled</option>
                                <option value="semi-skilled" <?php echo $applicant['skill_category'] == 'semi-skilled' ? 'selected' : ''; ?>>Semi-Skilled</option>
                                <option value="skilled" <?php echo $applicant['skill_category'] == 'skilled' ? 'selected' : ''; ?>>Skilled</option>
                                <option value="highly-skilled" <?php echo $applicant['skill_category'] == 'highly-skilled' ? 'selected' : ''; ?>>Highly Skilled</option>
                                <option value="supervisor" <?php echo $applicant['skill_category'] == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Contact Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Mobile Number</label>
                            <input type="tel" name="mobile_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['mobile_number'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['email'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($applicant['address'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['city'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['state'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['pincode'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="6">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ID Documents -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Identity Documents</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Aadhaar Number</label>
                            <input type="text" name="aadhaar_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['aadhaar_number'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="12">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PAN Number</label>
                            <input type="text" name="pan_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['pan_number'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="10">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Professional Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Professional Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Qualification</label>
                            <input type="text" name="qualification" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['qualification'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., 10th Pass, ITI, Graduate">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Experience (Years)</label>
                            <input type="number" name="experience_years" class="form-control" 
                                   value="<?php echo $applicant['experience_years']; ?>" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Employer</label>
                            <input type="text" name="current_employer" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['current_employer'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred Location</label>
                            <input type="text" name="preferred_location" class="form-control" 
                                   value="<?php echo htmlspecialchars($applicant['preferred_location'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Salary</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="current_salary" class="form-control" 
                                       value="<?php echo $applicant['current_salary']; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected Salary</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="expected_salary" class="form-control" 
                                       value="<?php echo $applicant['expected_salary']; ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h5 class="card-title mb-0">Recruitment Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Link to Requisition</label>
                        <select name="requisition_id" class="form-select">
                            <option value="">Select Requisition</option>
                            <?php foreach ($requisitions as $req): ?>
                            <option value="<?php echo $req['id']; ?>" <?php echo $applicant['requisition_id'] == $req['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($req['requisition_number']); ?> - 
                                <?php echo sanitize($req['designation']); ?> 
                                (<?php echo sanitize($req['client_name']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Source</label>
                        <select name="source" class="form-select">
                            <option value="walk-in" <?php echo $applicant['source'] == 'walk-in' ? 'selected' : ''; ?>>Walk-in</option>
                            <option value="reference" <?php echo $applicant['source'] == 'reference' ? 'selected' : ''; ?>>Reference</option>
                            <option value="job-portal" <?php echo $applicant['source'] == 'job-portal' ? 'selected' : ''; ?>>Job Portal</option>
                            <option value="agency" <?php echo $applicant['source'] == 'agency' ? 'selected' : ''; ?>>Agency</option>
                            <option value="social-media" <?php echo $applicant['source'] == 'social-media' ? 'selected' : ''; ?>>Social Media</option>
                            <option value="other" <?php echo $applicant['source'] == 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Source Reference</label>
                        <input type="text" name="source_reference" class="form-control" 
                               value="<?php echo htmlspecialchars($applicant['source_reference'], ENT_QUOTES, 'UTF-8'); ?>" 
                               placeholder="e.g., Reference name, portal name">
                    </div>
                    
                    <hr>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Add Candidate
                        </button>
                        <a href="index.php?page=recruitment/list" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>


