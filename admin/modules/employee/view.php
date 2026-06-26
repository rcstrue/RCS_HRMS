<?php
/**
 * RCS HRMS Pro - Employee View Page
 * Updated for actual database schema
 */

$pageTitle = 'Employee Details';

// Define constant for employee view URL to avoid duplication
define('EMPLOYEE_VIEW_URL', 'index.php?page=employee/view&id=');

// Get employee ID and validate as numeric to prevent open redirect
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employeeId) {
    setFlash('error', 'Employee ID is required');
    redirect('index.php?page=employee/list');
}

// Get employee details
$emp = $employee->getById($employeeId);

if (!$emp) {
    setFlash('error', 'Employee not found');
    redirect('index.php?page=employee/list');
}

// Get salary structure
$salaryStmt = $db->prepare("SELECT * FROM employee_salary_structures WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE()) ORDER BY effective_from DESC LIMIT 1");
$salaryStmt->execute([$employeeId]);
$salary = $salaryStmt->fetch(PDO::FETCH_ASSOC);

// Get documents from employee_documents table
$docStmt = $db->prepare("SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC");
$docStmt->execute([$employeeId]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Approve employee
    if ($action === 'approve' && $emp['status'] === 'pending_hr_verification') {
        $stmt = $db->prepare("UPDATE employees SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'] ?? null, $employeeId]);
        setFlash('success', 'Employee approved successfully!');
        redirect(EMPLOYEE_VIEW_URL . $employeeId);
    }
    
    // Reject employee
    if ($action === 'reject' && $emp['status'] === 'pending_hr_verification') {
        $reason = sanitize($_POST['reason'] ?? '');
        // Try to update with leaving_reason if column exists, otherwise just update status
        try {
            $stmt = $db->prepare("UPDATE employees SET status = 'inactive', remarks = ? WHERE id = ?");
            $stmt->execute([$reason, $employeeId]);
        } catch (Exception $e) {
            $stmt = $db->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$employeeId]);
        }
        setFlash('success', 'Employee rejected.');
        redirect(EMPLOYEE_VIEW_URL . $employeeId);
    }
    
    // Mark as left
    if ($action === 'mark_left') {
        $dol = sanitize($_POST['date_of_leaving'] ?? date('Y-m-d'));
        $reason = sanitize($_POST['reason'] ?? '');
        // Try to update with remarks column, fallback to just status and date
        try {
            $stmt = $db->prepare("UPDATE employees SET status = 'inactive', date_of_leaving = ?, remarks = ? WHERE id = ?");
            $stmt->execute([$dol, $reason, $employeeId]);
        } catch (Exception $e) {
            $stmt = $db->prepare("UPDATE employees SET status = 'inactive', date_of_leaving = ? WHERE id = ?");
            $stmt->execute([$dol, $employeeId]);
        }
        setFlash('success', 'Employee marked as left.');
        redirect(EMPLOYEE_VIEW_URL . $employeeId);
    }
    
    // Document upload
    if (isset($_POST['upload_document'])) {
        // Whitelist allowed document types to prevent path traversal
        $allowedDocTypes = [
            'Aadhaar Card', 'PAN Card', 'Bank Passbook', 'Photo',
            'Police Verification', 'Education Certificate', 'Experience Certificate',
            'Medical Certificate', 'Other'
        ];
        $docType = $_POST['document_type'] ?? '';
        
        // Validate document type against whitelist
        if (!in_array($docType, $allowedDocTypes)) {
            setFlash('error', 'Invalid document type.');
            redirect(EMPLOYEE_VIEW_URL . $employeeId);
        }
        
        // Sanitize document type for filename (alphanumeric and underscore only)
        $safeDocType = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $docType));
        
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            // Use safe employee code for directory (alphanumeric only) - whitelist approach for security
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $emp['employee_code']);
            $uploadDir = 'uploads/employee_documents/' . $safeCode . '/';
            
            if (!is_dir(APP_ROOT . '/' . $uploadDir)) {
                mkdir(APP_ROOT . '/' . $uploadDir, 0755, true);
            }
            
            // Generate safe filename - prevent path traversal
            $fileExtension = pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION);
            // Only allow safe file extensions
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];
            $fileExtension = in_array(strtolower($fileExtension), $allowedExtensions) ? $fileExtension : 'pdf';
            
            $fileName = $safeDocType . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            // Validate the resolved path is within uploads directory
            $resolvedPath = realpath(APP_ROOT . '/' . $uploadDir) . '/' . $fileName;
            $uploadsBase = realpath(APP_ROOT . '/uploads');
            
            if (strpos($resolvedPath, $uploadsBase) !== 0) {
                setFlash('error', 'Invalid file path.');
                redirect(EMPLOYEE_VIEW_URL . $employeeId);
            }
            
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], APP_ROOT . '/' . $filePath)) {
                $stmt = $db->prepare("INSERT INTO employee_documents (employee_id, document_type, document_name, file_path, file_size, file_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employeeId, $docType, $fileName, $filePath, $_FILES['document_file']['size'], $_FILES['document_file']['type'], $_SESSION['user_id'] ?? null]);
                
                setFlash('success', 'Document uploaded successfully!');
                redirect(EMPLOYEE_VIEW_URL . $employeeId);
            }
        }
    }
}

// Calculate age
$age = '';
if (!empty($emp['date_of_birth'])) {
    $dob = new DateTime($emp['date_of_birth']);
    $now = new DateTime();
    $age = $dob->diff($now)->y;
}

// Calculate tenure
$tenure = '';
if (!empty($emp['date_of_joining'])) {
    $doj = new DateTime($emp['date_of_joining']);
    $endDate = !empty($emp['date_of_leaving']) ? new DateTime($emp['date_of_leaving']) : new DateTime();
    $diff = $doj->diff($endDate);
    $tenure = $diff->y . ' years, ' . $diff->m . ' months';
}

// Document types
$documentTypes = [
    'Aadhaar Card' => 'Aadhaar Card',
    'PAN Card' => 'PAN Card',
    'Bank Passbook' => 'Bank Passbook',
    'Photo' => 'Photo',
    'Police Verification' => 'Police Verification',
    'Education Certificate' => 'Education Certificate',
    'Experience Certificate' => 'Experience Certificate',
    'Medical Certificate' => 'Medical Certificate',
    'Other' => 'Other Document'
];

// Status colors
$statusColors = [
    'approved' => 'success',
    'pending_hr_verification' => 'warning',
    'inactive' => 'secondary',
    'terminated' => 'danger'
];
$statusLabels = [
    'approved' => 'Active',
    'pending_hr_verification' => 'Pending Verification',
    'inactive' => 'Inactive',
    'terminated' => 'Terminated'
];
?>

<div class="row">
    <!-- Sidebar Quick Actions -->
    <div class="col-lg-3 col-md-4 mb-3">
        <!-- Employee Photo & Quick Info -->
        <div class="card mb-3">
            <div class="card-body text-center">
                <?php if (!empty($emp['profile_pic_cropped_url'])): ?>
                <img src="<?php echo sanitize($emp['profile_pic_cropped_url']); ?>" alt="Employee" class="rounded-circle mb-2" style="width: 100px; height: 100px; object-fit: cover; border: 3px solid #dee2e6;">
                <?php elseif (!empty($emp['profile_pic_url'])): ?>
                <img src="<?php echo sanitize($emp['profile_pic_url']); ?>" alt="Employee" class="rounded-circle mb-2" style="width: 100px; height: 100px; object-fit: cover; border: 3px solid #dee2e6;">
                <?php else: ?>
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-2" style="width: 100px; height: 100px; font-size: 36px;">
                    <?php echo strtoupper(substr($emp['full_name'] ?? 'U', 0, 1)); ?>
                </div>
                <?php endif; ?>
                <h5 class="mb-1"><?php echo sanitize($emp['full_name'] ?? '-'); ?></h5>
                <p class="text-muted mb-2"><code><?php echo sanitize($emp['employee_code']); ?></code></p>
                <span class="badge bg-<?php echo $statusColors[$emp['status']] ?? 'secondary'; ?> fs-6">
                    <?php echo $statusLabels[$emp['status']] ?? sanitize($emp['status']); ?>
                </span>
                <?php if ($emp['status'] === 'approved' && !empty($emp['approved_at'])): ?>
                <br><small class="text-muted">Approved: <?php echo formatDate($emp['approved_at']); ?></small>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-lightning-fill me-2"></i>Quick Actions</h6>
            </div>
            <div class="card-body p-2">
                <div class="d-grid gap-2">
                    <!-- Approve/Reject for pending employees -->
                    <?php if ($emp['status'] === 'pending_hr_verification'): ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="approveEmployee()">
                        <i class="bi bi-check-circle me-2"></i>Approve Employee
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="rejectEmployee()">
                        <i class="bi bi-x-circle me-2"></i>Reject
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($emp['status'] === 'approved'): ?>
                    <button type="button" class="btn btn-warning btn-sm" onclick="markAsLeft()">
                        <i class="bi bi-person-x me-2"></i>Mark as Left
                    </button>
                    <?php endif; ?>
                    
                    <hr class="my-2">
                    
                    <a href="index.php?page=employee/add&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-2"></i>Edit Employee
                    </a>
                    
                    <?php if ($emp['status'] === 'approved'): ?>
                    <a href="index.php?page=employee/id-card&client_id=<?php echo (int)($emp['client_id'] ?? 0); ?>&unit_id=<?php echo (int)($emp['unit_id'] ?? 0); ?>&employee_id=<?php echo sanitize($employeeId); ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-badge me-2"></i>ID Card
                    </a>
                    <?php endif; ?>
                    
                    <hr class="my-2">
                    
                    <!-- Forms & Certificates -->
                    <small class="text-muted fw-bold">FORMS & CERTIFICATES</small>
                    
                    <a href="index.php?page=forms/appointment&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-success btn-sm" target="_blank">
                        <i class="bi bi-file-earmark-text me-2"></i>Appointment Letter
                    </a>
                    
                    <a href="index.php?page=forms/relieving&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-info btn-sm" target="_blank">
                        <i class="bi bi-file-earmark-minus me-2"></i>Relieving Letter
                    </a>
                    
                    <a href="index.php?page=forms/service_certificate&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-info btn-sm" target="_blank">
                        <i class="bi bi-award me-2"></i>Service Certificate
                    </a>
                    
                    <a href="index.php?page=forms/experience&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-info btn-sm" target="_blank">
                        <i class="bi bi-briefcase me-2"></i>Experience Certificate
                    </a>
                    
                    <hr class="my-2">
                    
                    <small class="text-muted fw-bold">STATUTORY FORMS</small>
                    
                    <a href="index.php?page=forms/form-v&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                        <i class="bi bi-file-text me-2"></i>Form V
                    </a>
                    
                    <a href="index.php?page=forms/form-xvi&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                        <i class="bi bi-file-text me-2"></i>Form XVI
                    </a>
                    
                    <a href="index.php?page=forms/form-xvii&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                        <i class="bi bi-file-text me-2"></i>Form XVII
                    </a>
                    
                    <hr class="my-2">
                    
                    <small class="text-muted fw-bold">NOMINATIONS</small>
                    
                    <a href="index.php?page=forms/nomination_pf&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-warning btn-sm" target="_blank">
                        <i class="bi bi-file-earmark-person me-2"></i>PF Nomination
                    </a>
                    
                    <a href="index.php?page=forms/nomination_esi&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-warning btn-sm" target="_blank">
                        <i class="bi bi-file-earmark-person me-2"></i>ESI Nomination
                    </a>
                    
                    <a href="index.php?page=forms/nomination_gratuity&id=<?php echo sanitize($employeeId); ?>" class="btn btn-outline-warning btn-sm" target="_blank">
                        <i class="bi bi-file-earmark-person me-2"></i>Gratuity Nomination
                    </a>
                    
                    <?php if ($emp['status'] === 'approved'): ?>
                    <hr class="my-2">
                    <a href="index.php?page=payroll/payslips&emp=<?php echo sanitize($emp['employee_code']); ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-receipt me-2"></i>View Payslips
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Salary Summary -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Salary Info</h6>
            </div>
            <div class="card-body p-2">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted">Gross Salary</td>
                        <td class="text-end fw-bold text-success"><?php echo formatCurrency($salary['gross_salary'] ?? $emp['gross_salary'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Basic + DA</td>
                        <td class="text-end"><?php echo formatCurrency($salary['basic_da'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">HRA</td>
                        <td class="text-end"><?php echo formatCurrency($salary['hra'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Washing Allow.</td>
                        <td class="text-end"><?php echo formatCurrency($salary['washing_allowance'] ?? 0); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="col-lg-9 col-md-8">
        <!-- Personal Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Personal Details</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-fullname">Full Name</label>
                        <div class="fw-medium" aria-labelledby="lbl-fullname"><?php echo sanitize($emp['full_name'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-fathername">Father's Name</label>
                        <div aria-labelledby="lbl-fathername"><?php echo sanitize($emp['father_name'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-gender">Gender</label>
                        <div aria-labelledby="lbl-gender"><?php echo sanitize($emp['gender'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-dob">Date of Birth</label>
                        <div aria-labelledby="lbl-dob"><?php echo formatDate($emp['date_of_birth']); ?>
                            <?php if ($age): ?><span class="text-muted">(<?php echo $age; ?> years)</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-marital">Marital Status</label>
                        <div aria-labelledby="lbl-marital"><?php echo sanitize($emp['marital_status'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-blood">Blood Group</label>
                        <div>
                            <?php if (!empty($emp['blood_group'])): ?>
                            <span class="badge bg-danger"><?php echo sanitize($emp['blood_group']); ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small" id="lbl-role">Employee Role</label>
                        <div aria-labelledby="lbl-role"><?php echo ucfirst(sanitize($emp['employee_role'] ?? 'employee')); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Contact Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-telephone me-2"></i>Contact Details</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Mobile Number</label>
                        <div>
                            <?php if (!empty($emp['mobile_number'])): ?>
                            <a href="tel:<?php echo sanitize($emp['mobile_number']); ?>"><?php echo sanitize($emp['mobile_number']); ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Alternate Mobile</label>
                        <div><?php echo sanitize($emp['alternate_mobile'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Email</label>
                        <div>
                            <?php if (!empty($emp['email'])): ?>
                            <a href="mailto:<?php echo sanitize($emp['email']); ?>"><?php echo sanitize($emp['email']); ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Address Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Address Details</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="text-muted small">Full Address</label>
                        <div>
                            <?php
                            $addr = [];
                            if (!empty($emp['address'])) {
                                $addr[] = sanitize($emp['address']);
                            }
                            if (!empty($emp['district'])) {
                                $addr[] = sanitize($emp['district']);
                            }
                            if (!empty($emp['state'])) {
                                $addr[] = sanitize($emp['state']);
                            }
                            if (!empty($emp['pin_code'])) {
                                $addr[] = sanitize($emp['pin_code']);
                            }
                            echo !empty($addr) ? implode(', ', $addr) : '-';
                            ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-muted small">State</label>
                        <div><?php echo sanitize($emp['state'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-muted small">District</label>
                        <div><?php echo sanitize($emp['district'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-muted small">Pin Code</label>
                        <div><?php echo sanitize($emp['pin_code'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Identity Documents -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-card-heading me-2"></i>Identity Documents</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Aadhaar Number</label>
                        <div>
                            <?php if (!empty($emp['aadhaar_number'])): ?>
                            <!-- NOTE: Aadhaar number is NOT masked as per user requirement - full number visible for HR operations -->
                            <code><?php echo sanitize($emp['aadhaar_number']); ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">UAN Number</label>
                        <div>
                            <?php if (!empty($emp['uan_number'])): ?>
                            <code><?php echo sanitize($emp['uan_number']); ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">ESIC Number</label>
                        <div>
                            <?php if (!empty($emp['esic_number'])): ?>
                            <code><?php echo sanitize($emp['esic_number']); ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Document Images (Aadhaar & Bank) -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-image me-2"></i>Document Images</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Profile Photo -->
                    <div class="col-md-3 mb-3 text-center">
                        <label class="text-muted small d-block" id="lbl-profile">Profile Photo</label>
                        <?php if (!empty($emp['profile_pic_cropped_url'])): ?>
                        <a href="<?php echo sanitize($emp['profile_pic_cropped_url']); ?>" target="blank">
                            <img id="doc-profile" src="<?php echo sanitize($emp['profile_pic_cropped_url']); ?>" data-field="profile_pic_cropped_url" alt="Profile photo of <?php echo sanitize($emp['full_name'] ?? 'Employee'); ?>" class="img-thumbnail" style="max-height: 120px;">
                        </a>
                        <button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="cropDocImage('profile_pic_cropped_url', document.getElementById('doc-profile'))">
                            <i class="bi bi-crop"></i> Crop
                        </button>
                        <?php elseif (!empty($emp['profile_pic_url'])): ?>
                        <a href="<?php echo sanitize($emp['profile_pic_url']); ?>" target="_blank">
                            <img id="doc-profile" src="<?php echo sanitize($emp['profile_pic_url']); ?>" data-field="profile_pic_url" alt="Profile photo of <?php echo sanitize($emp['full_name'] ?? 'Employee'); ?>" class="img-thumbnail" style="max-height: 120px;">
                        </a>
                        <button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="cropDocImage('profile_pic_url', document.getElementById('doc-profile'))">
                            <i class="bi bi-crop"></i> Crop
                        </button>
                        <?php else: ?>
                        <div class="img-thumbnail d-flex align-items-center justify-content-center bg-light" style="height: 120px;">
                            <span class="text-muted">No Photo</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Aadhaar Front -->
                    <div class="col-md-3 mb-3 text-center">
                        <label class="text-muted small d-block" id="lbl-aadhaarfront">Aadhaar Front</label>
                        <?php if (!empty($emp['aadhaar_front_url'])): ?>
                        <a href="<?php echo sanitize($emp['aadhaar_front_url']); ?>" target="_blank">
                            <img id="doc-aadhaar-front" src="<?php echo sanitize($emp['aadhaar_front_url']); ?>" data-field="aadhaar_front_url" alt="Aadhaar card front side" class="img-thumbnail" style="max-height: 120px;">
                        </a>
                        <button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="cropDocImage('aadhaar_front_url', document.getElementById('doc-aadhaar-front'))">
                            <i class="bi bi-crop"></i> Crop
                        </button>
                        <?php else: ?>
                        <div class="img-thumbnail d-flex align-items-center justify-content-center bg-light" style="height: 120px;">
                            <span class="text-muted">Not Uploaded</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Aadhaar Back -->
                    <div class="col-md-3 mb-3 text-center">
                        <label class="text-muted small d-block" id="lbl-aadhaarback">Aadhaar Back</label>
                        <?php if (!empty($emp['aadhaar_back_url'])): ?>
                        <a href="<?php echo sanitize($emp['aadhaar_back_url']); ?>" target="_blank">
                            <img id="doc-aadhaar-back" src="<?php echo sanitize($emp['aadhaar_back_url']); ?>" data-field="aadhaar_back_url" alt="Aadhaar card back side" class="img-thumbnail" style="max-height: 120px;">
                        </a>
                        <button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="cropDocImage('aadhaar_back_url', document.getElementById('doc-aadhaar-back'))">
                            <i class="bi bi-crop"></i> Crop
                        </button>
                        <?php else: ?>
                        <div class="img-thumbnail d-flex align-items-center justify-content-center bg-light" style="height: 120px;">
                            <span class="text-muted">Not Uploaded</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Bank Document -->
                    <div class="col-md-3 mb-3 text-center">
                        <label class="text-muted small d-block" id="lbl-bankdoc">Bank Passbook</label>
                        <?php if (!empty($emp['bank_document_url'])): ?>
                        <a href="<?php echo sanitize($emp['bank_document_url']); ?>" target="_blank">
                            <img id="doc-bank" src="<?php echo sanitize($emp['bank_document_url']); ?>" data-field="bank_document_url" alt="Bank passbook image" class="img-thumbnail" style="max-height: 120px;">
                        </a>
                        <button type="button" class="btn btn-xs btn-outline-primary mt-1" onclick="cropDocImage('bank_document_url', document.getElementById('doc-bank'))">
                            <i class="bi bi-crop"></i> Crop
                        </button>
                        <?php else: ?>
                        <div class="img-thumbnail d-flex align-items-center justify-content-center bg-light" style="height: 120px;">
                            <span class="text-muted">Not Uploaded</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bank Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Details</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Bank Name</label>
                        <div><?php echo sanitize($emp['bank_name'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Account Number</label>
                        <div>
                            <?php if (!empty($emp['account_number'])): ?>
                            <code><?php echo sanitize($emp['account_number']); ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">IFSC Code</label>
                        <div>
                            <?php if (!empty($emp['ifsc_code'])): ?>
                            <code><?php echo sanitize($emp['ifsc_code']); ?></code>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Account Holder Name</label>
                        <div><?php echo sanitize($emp['account_holder_name'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Employment Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-briefcase me-2"></i>Employment Details</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Client</label>
                        <div><?php echo sanitize($emp['client_name_display'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Unit</label>
                        <div><?php echo sanitize($emp['unit_name_display'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Designation</label>
                        <div><?php echo sanitize($emp['designation'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Department</label>
                        <div><?php echo sanitize($emp['department'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Worker Category</label>
                        <div><span class="badge bg-info"><?php echo sanitize($emp['worker_category'] ?? '-'); ?></span></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Employment Type</label>
                        <div><?php echo sanitize($emp['employment_type'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Date of Joining</label>
                        <div><?php echo formatDate($emp['date_of_joining']); ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Tenure</label>
                        <div><?php echo $tenure ?: '-'; ?></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Probation Period</label>
                        <div><?php echo !empty($emp['probation_period']) ? $emp['probation_period'] . ' months' : '-'; ?></div>
                    </div>
                    <?php if (!empty($emp['date_of_leaving'])): ?>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Date of Leaving</label>
                        <div class="text-danger"><?php echo formatDate($emp['date_of_leaving']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($emp['confirmation_date'])): ?>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Confirmation Date</label>
                        <div><?php echo formatDate($emp['confirmation_date']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Wage Details -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-currency-rupee me-2"></i>Wage Details</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Basic + DA</th>
                                <th scope="col">HRA</th>
                                <th scope="col">Washing</th>
                                <th scope="col">Leave Enc.</th>
                                <th scope="col">Bonus Enc.</th>
                                <th scope="col">Gross</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo formatCurrency($salary['basic_da'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($salary['hra'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($salary['washing_allowance'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($salary['leave_encashment'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($salary['bonus_encashment'] ?? 0); ?></td>
                                <td class="fw-bold text-success"><?php echo formatCurrency($salary['gross_salary'] ?? 0); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Statutory Applicability -->
                <h6 class="mt-3 mb-2">Statutory Applicability</h6>
                <div class="row">
                    <div class="col-md-12">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">PF</th>
                                    <th scope="col">ESI</th>
                                    <th scope="col">PT</th>
                                    <th scope="col">LWF</th>
                                    <th scope="col">Bonus</th>
                                    <th scope="col">Gratuity</th>
                                    <th scope="col">Overtime</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo !empty($salary['pf_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['esi_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['pt_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['lwf_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['bonus_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['gratuity_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td><?php echo !empty($salary['overtime_applicable']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Nominee & Emergency Contact -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Nominee & Emergency Contact</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="text-primary"><i class="bi bi-telephone-fill me-1"></i>Emergency Contact</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Name:</th>
                                <td><?php echo sanitize($emp['emergency_contact_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Relation:</th>
                                <td><?php echo sanitize($emp['emergency_contact_relation'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Phone:</th>
                                <td>
                                    <?php if (!empty($emp['emergency_contact_number'])): ?>
                                    <a href="tel:<?php echo sanitize($emp['emergency_contact_number']); ?>"><?php echo sanitize($emp['emergency_contact_number']); ?></a>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="text-primary"><i class="bi bi-person-check me-1"></i>Nominee</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Name:</th>
                                <td><?php echo sanitize($emp['nominee_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Relation:</th>
                                <td><?php echo sanitize($emp['nominee_relationship'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted fw-normal">DOB:</th>
                                <td><?php echo formatDate($emp['nominee_dob'] ?? null); ?></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted fw-normal">Contact:</th>
                                <td><?php echo sanitize($emp['nominee_contact'] ?? '-'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Documents Section -->
        <div class="card mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-folder me-2"></i>Uploaded Documents</h6>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="bi bi-upload me-1"></i>Upload Document
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Document Type</th>
                                <th scope="col">File Name</th>
                                <th scope="col">Uploaded On</th>
                                <th scope="col">Verified</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($documents)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No documents uploaded yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><?php echo sanitize($doc['document_type']); ?></td>
                                <td><?php echo sanitize($doc['document_name'] ?? basename($doc['file_path'])); ?></td>
                                <td><?php echo formatDateTime($doc['created_at']); ?></td>
                                <td>
                                    <?php if (!empty($doc['verified'])): ?>
                                    <span class="badge bg-success">Verified</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo sanitize($doc['file_path']); ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve <strong><?php echo sanitize($emp['full_name']); ?></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reject-reason" class="form-label">Reason for Rejection</label>
                        <textarea id="reject-reason" class="form-control" name="reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark as Left Modal -->
<div class="modal fade" id="leftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="mark_left">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Employee as Left</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="date-of-leaving" class="form-label">Date of Leaving</label>
                        <input type="date" id="date-of-leaving" class="form-control" name="date_of_leaving" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="leaving-reason" class="form-label">Reason</label>
                        <textarea id="leaving-reason" class="form-control" name="reason" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Mark as Left</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="upload_document" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="document-type" class="form-label">Document Type</label>
                        <select id="document-type" class="form-select" name="document_type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($documentTypes as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="document-file" class="form-label">File</label>
                        <input type="file" id="document-file" class="form-control" name="document_file" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveEmployee() {
    new bootstrap.Modal('#approveModal').show();
}

function rejectEmployee() {
    new bootstrap.Modal('#rejectModal').show();
}

function markAsLeft() {
    new bootstrap.Modal('#leftModal').show();
}

// Crop document image (Aadhaar / Bank / Profile)
function cropDocImage(field, imgEl) {
    if (!imgEl) return;
    var empId = <?php echo (int)$employeeId; ?>;
    var currentUrl = imgEl.getAttribute('src');
    if (!currentUrl) return;

    openLiteEditor(currentUrl, function(base64DataUrl) {
        var formData = new FormData();
        formData.append('action', 'crop_save_doc');
        formData.append('employee_id', empId);
        formData.append('field', field);
        formData.append('image_data', base64DataUrl);
        formData.append('original_url', currentUrl);

        fetch(window.location.pathname + '?page=api/crop-save', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) {
                imgEl.src = res.url + '?t=' + Date.now();
                var link = imgEl.closest('a');
                if (link) link.href = res.url;
                // Also update the sidebar profile photo if it was the profile pic
                if (field.indexOf('profile_pic') !== -1) {
                    var sidePhoto = document.querySelector('.card-body.text-center img.rounded-circle');
                    if (sidePhoto) sidePhoto.src = res.url + '?t=' + Date.now();
                }
                alert('Image saved successfully!');
            } else {
                alert('Save failed: ' + (res.msg || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
    });
}
</script>

<?php include_once 'modules/settings/image-tool-lite.php'; ?>
