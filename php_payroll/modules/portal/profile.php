<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Profile
 * Updated: Fixed session/header issues — framework handles session & auth
 */

// Check portal login — session already started by config.php, use redirect() helper
if (!isset($_SESSION['employee_portal']) || !$_SESSION['employee_portal']['logged_in']) {
    redirect('index.php?page=portal/login');
}

$pageTitle = 'My Profile';
$page = 'portal/profile';

// $db is already available from the framework (index.php auto-includes config/database)
$employeeId = $_SESSION['employee_portal']['employee_id'];

// Get full employee details
$employee = $db->fetch(
    "SELECT e.*, 
            c.name as client_name,
            u.name as unit_name
     FROM employees e
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE e.id = :id",
    ['id' => $employeeId]
);

// Get bank details
$bankDetails = [
    'bank_name' => $employee['bank_name'] ?? '',
    'account_number' => $employee['bank_account_number'] ?? $employee['account_number'] ?? '',
    'ifsc_code' => $employee['bank_ifsc_code'] ?? $employee['ifsc_code'] ?? '',
    'branch' => $employee['bank_branch'] ?? ''
];

// Get documents
$documents = $db->fetchAll(
    "SELECT * FROM employee_documents WHERE employee_id = :id ORDER BY created_at DESC",
    ['id' => $employeeId]
);

// Get family members
$familyMembers = $db->fetchAll(
    "SELECT * FROM employee_family WHERE employee_id = :id",
    ['id' => $employeeId]
);

// Handle profile update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_contact') {
        try {
            $db->update('employees', [
                'mobile_number' => sanitize($_POST['mobile_number']),
                'alternate_mobile' => sanitize($_POST['alternate_mobile'] ?? ''),
                'email' => sanitize($_POST['email'] ?? ''),
                'emergency_contact_name' => sanitize($_POST['emergency_contact_name'] ?? ''),
                'emergency_contact_number' => sanitize($_POST['emergency_contact_number'] ?? ''),
                'emergency_contact_relation' => sanitize($_POST['emergency_contact_relation'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $employeeId]);
            
            $message = 'Contact details updated successfully!';
            $messageType = 'success';
            
            // Refresh data
            $employee = $db->fetch(
                "SELECT e.*, c.name as client_name,
                        u.name as unit_name
                 FROM employees e
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 WHERE e.id = :id",
                ['id' => $employeeId]
            );
        } catch (Exception $e) {
            $message = 'Error updating details: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
    if ($_POST['action'] === 'update_bank') {
        try {
            $db->update('employees', [
                'bank_name' => sanitize($_POST['bank_name']),
                'bank_account_number' => sanitize($_POST['account_number']),
                'bank_ifsc_code' => sanitize($_POST['ifsc_code']),
                'bank_branch' => sanitize($_POST['branch'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $employeeId]);
            
            $message = 'Bank details updated successfully!';
            $messageType = 'success';
            
            // Refresh data
            $employee = $db->fetch(
                "SELECT e.*, c.name as client_name,
                        u.name as unit_name
                 FROM employees e
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 WHERE e.id = :id",
                ['id' => $employeeId]
            );
        } catch (Exception $e) {
            $message = 'Error updating bank details: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
    if ($_POST['action'] === 'update_address') {
        try {
            $db->update('employees', [
                'current_address' => sanitize($_POST['current_address']),
                'current_city' => sanitize($_POST['current_city'] ?? ''),
                'current_state' => sanitize($_POST['current_state'] ?? ''),
                'current_pincode' => sanitize($_POST['current_pincode'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $employeeId]);
            
            $message = 'Address updated successfully!';
            $messageType = 'success';
            
            // Refresh data
            $employee = $db->fetch(
                "SELECT e.*, c.name as client_name,
                        u.name as unit_name
                 FROM employees e
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 WHERE e.id = :id",
                ['id' => $employeeId]
            );
        } catch (Exception $e) {
            $message = 'Error updating address: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

include '../../templates/header.php';
?>

<style>
.profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px 15px 0 0;
}
.profile-img-large {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    border: 5px solid white;
    object-fit: cover;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
}
.info-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}
.info-section .section-header {
    background: #f8f9fa;
    padding: 15px 20px;
    border-radius: 15px 15px 0 0;
    border-bottom: 1px solid #eee;
}
.info-section .section-body {
    padding: 20px;
}
.info-label {
    color: #888;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-value {
    font-weight: 500;
    font-size: 15px;
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="info-section">
            <div class="profile-header">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <?php if (!empty($employee['photo_path'])): ?>
                        <img src="<?php echo sanitize($employee['photo_path']); ?>" class="profile-img-large" alt="Profile">
                        <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($employee['full_name']); ?>&size=150&background=ffffff&color=667eea" 
                             class="profile-img-large" alt="Profile">
                        <?php endif; ?>
                    </div>
                    <div class="col">
                        <h2 class="mb-1"><?php echo sanitize($employee['full_name']); ?></h2>
                        <p class="mb-2 opacity-75">
                            <i class="bi bi-badge-ad me-1"></i><?php echo sanitize($employee['employee_code']); ?>
                            &nbsp;|&nbsp;
                            <i class="bi bi-briefcase me-1"></i><?php echo sanitize($employee['designation'] ?? 'N/A'); ?>
                        </p>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-building me-1"></i><?php echo sanitize($employee['client_name'] ?? 'N/A'); ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-geo-alt me-1"></i><?php echo sanitize($employee['unit_name'] ?? 'N/A'); ?>
                            </span>
                            <span class="badge bg-<?php echo $employee['status'] == 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($employee['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-auto">
                        <a href="index.php?page=portal/dashboard" class="btn btn-light">
                            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Personal Information -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person me-2"></i>Personal Information</h5>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="info-label">Father's Name</div>
                        <div class="info-value"><?php echo sanitize($employee['father_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Mother's Name</div>
                        <div class="info-value"><?php echo sanitize($employee['mother_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Date of Birth</div>
                        <div class="info-value"><?php echo formatDate($employee['date_of_birth']); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Gender</div>
                        <div class="info-value"><?php echo ucfirst($employee['gender'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Blood Group</div>
                        <div class="info-value"><?php echo sanitize($employee['blood_group'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Marital Status</div>
                        <div class="info-value"><?php echo ucfirst($employee['marital_status'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Contact Information (Editable) -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-telephone me-2"></i>Contact Information</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editContactModal">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="info-label">Mobile Number</div>
                        <div class="info-value"><?php echo sanitize($employee['mobile_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Alternate Mobile</div>
                        <div class="info-value"><?php echo sanitize($employee['alternate_mobile'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo sanitize($employee['email'] ?? $employee['personal_email'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="info-label">Emergency Contact</div>
                        <div class="info-value">
                            <?php echo sanitize($employee['emergency_contact_name'] ?? 'N/A'); ?>
                            <?php if (!empty($employee['emergency_contact_number'])): ?>
                            <br><i class="bi bi-telephone me-1"></i><?php echo sanitize($employee['emergency_contact_number']); ?>
                            <?php if (!empty($employee['emergency_contact_relation'])): ?>
                            <span class="text-muted">(<?php echo sanitize($employee['emergency_contact_relation']); ?>)</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Employment Details -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header">
                <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Employment Details</h5>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="info-label">Date of Joining</div>
                        <div class="info-value"><?php echo formatDate($employee['date_of_joining']); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Employment Type</div>
                        <div class="info-value"><?php echo ucfirst($employee['employment_type'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Designation</div>
                        <div class="info-value"><?php echo sanitize($employee['designation'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo sanitize($employee['department'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Skill Category</div>
                        <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $employee['skill_category'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Worker Category</div>
                        <div class="info-value"><?php echo ucfirst($employee['worker_category'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Statutory Details -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header">
                <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Statutory Details</h5>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="info-label">UAN Number</div>
                        <div class="info-value"><?php echo sanitize($employee['uan_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">ESI Number</div>
                        <div class="info-value"><?php echo sanitize($employee['esi_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">PAN Number</div>
                        <div class="info-value"><?php echo sanitize($employee['pan_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Aadhaar Number</div>
                        <div class="info-value">
                            <?php 
                            $aadhaar = $employee['aadhaar_number'] ?? '';
                            echo $aadhaar ? 'XXXX-XXXX-' . substr($aadhaar, -4) : 'N/A';
                            ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">PF Applicable</div>
                        <div class="info-value">
                            <span class="badge bg-<?php echo ($employee['is_pf_applicable'] ?? 0) ? 'success' : 'secondary'; ?>">
                                <?php echo ($employee['is_pf_applicable'] ?? 0) ? 'Yes' : 'No'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">ESI Applicable</div>
                        <div class="info-value">
                            <span class="badge bg-<?php echo ($employee['is_esi_applicable'] ?? 0) ? 'success' : 'secondary'; ?>">
                                <?php echo ($employee['is_esi_applicable'] ?? 0) ? 'Yes' : 'No'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bank Details (Editable) -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Details</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editBankModal">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="info-label">Bank Name</div>
                        <div class="info-value"><?php echo sanitize($employee['bank_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Account Number</div>
                        <div class="info-value">
                            <?php 
                            $acc = $employee['bank_account_number'] ?? $employee['account_number'] ?? '';
                            echo $acc ? 'XXXX' . substr($acc, -4) : 'N/A';
                            ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">IFSC Code</div>
                        <div class="info-value"><?php echo sanitize($employee['bank_ifsc_code'] ?? $employee['ifsc_code'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="info-label">Branch</div>
                        <div class="info-value"><?php echo sanitize($employee['bank_branch'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Address (Editable) -->
    <div class="col-md-6">
        <div class="info-section">
            <div class="section-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Current Address</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAddressModal">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo nl2br(sanitize($employee['current_address'] ?? $employee['permanent_address'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="col-4">
                        <div class="info-label">City</div>
                        <div class="info-value"><?php echo sanitize($employee['current_city'] ?? $employee['permanent_city'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-4">
                        <div class="info-label">State</div>
                        <div class="info-value"><?php echo sanitize($employee['current_state'] ?? $employee['permanent_state'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-4">
                        <div class="info-label">Pincode</div>
                        <div class="info-value"><?php echo sanitize($employee['current_pincode'] ?? $employee['permanent_pincode'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Documents -->
    <div class="col-12">
        <div class="info-section">
            <div class="section-header">
                <h5 class="mb-0"><i class="bi bi-folder me-2"></i>My Documents</h5>
            </div>
            <div class="section-body">
                <?php if (empty($documents)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-folder2-open fs-1"></i>
                    <p>No documents uploaded</p>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                    <div class="col-md-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-file-earmark-<?php echo strpos($doc['document_path'], '.pdf') !== false ? 'pdf' : 'image'; ?> fs-1 text-primary"></i>
                                <h6 class="mt-2"><?php echo ucfirst($doc['document_type']); ?></h6>
                                <small class="text-muted"><?php echo sanitize($doc['document_name']); ?></small>
                                <div class="mt-2">
                                    <span class="badge bg-<?php echo $doc['verification_status'] == 'verified' ? 'success' : ($doc['verification_status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                        <?php echo ucfirst($doc['verification_status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit Contact Modal -->
<div class="modal fade" id="editContactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_contact">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Contact Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Mobile Number</label>
                        <input type="tel" class="form-control" name="mobile_number" 
                               value="<?php echo sanitize($employee['mobile_number'] ?? ''); ?>" maxlength="10" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alternate Mobile</label>
                        <input type="tel" class="form-control" name="alternate_mobile" 
                               value="<?php echo sanitize($employee['alternate_mobile'] ?? ''); ?>" maxlength="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" 
                               value="<?php echo sanitize($employee['email'] ?? $employee['personal_email'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Emergency Contact Name</label>
                        <input type="text" class="form-control" name="emergency_contact_name" 
                               value="<?php echo sanitize($employee['emergency_contact_name'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-8 mb-3">
                            <label class="form-label">Emergency Contact Number</label>
                            <input type="tel" class="form-control" name="emergency_contact_number" 
                                   value="<?php echo sanitize($employee['emergency_contact_number'] ?? ''); ?>" maxlength="10">
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label">Relation</label>
                            <select class="form-select" name="emergency_contact_relation">
                                <option value="">Select</option>
                                <option value="Father" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Father' ? 'selected' : ''; ?>>Father</option>
                                <option value="Mother" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Mother' ? 'selected' : ''; ?>>Mother</option>
                                <option value="Spouse" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
                                <option value="Brother" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Brother' ? 'selected' : ''; ?>>Brother</option>
                                <option value="Sister" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Sister' ? 'selected' : ''; ?>>Sister</option>
                                <option value="Other" <?php echo ($employee['emergency_contact_relation'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Bank Modal -->
<div class="modal fade" id="editBankModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_bank">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Bank Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" class="form-control" name="bank_name" 
                               value="<?php echo sanitize($employee['bank_name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Number</label>
                        <input type="text" class="form-control" name="account_number" 
                               value="<?php echo sanitize($employee['bank_account_number'] ?? $employee['account_number'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IFSC Code</label>
                        <input type="text" class="form-control" name="ifsc_code" 
                               value="<?php echo sanitize($employee['bank_ifsc_code'] ?? $employee['ifsc_code'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-control" name="branch" 
                               value="<?php echo sanitize($employee['bank_branch'] ?? ''); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Address Modal -->
<div class="modal fade" id="editAddressModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_address">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Current Address</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="current_address" rows="3" required><?php echo sanitize($employee['current_address'] ?? $employee['permanent_address'] ?? ''); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="current_city" 
                                   value="<?php echo sanitize($employee['current_city'] ?? $employee['permanent_city'] ?? ''); ?>">
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="current_state" 
                                   value="<?php echo sanitize($employee['current_state'] ?? $employee['permanent_state'] ?? ''); ?>">
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="current_pincode" 
                                   value="<?php echo sanitize($employee['current_pincode'] ?? $employee['permanent_pincode'] ?? ''); ?>" maxlength="6">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
