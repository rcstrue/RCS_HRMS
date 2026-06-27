<?php
/**
 * RCS HRMS Pro - User Profile Settings
 */

$pageTitle = 'Profile Settings';

// Get current user
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    setFlash('error', 'Please login to access this page');
    redirect('index.php?page=auth/login');
}

// Get user details - using correct column names from Auth class
try {
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.phone, 
                                 u.is_active, u.created_at, u.last_login, r.role_name, r.role_code
                          FROM users u 
                          LEFT JOIN roles r ON u.role_id = r.id
                          WHERE u.id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If roles table doesn't exist, try without join
    $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, phone, is_active, 
                                 created_at, last_login, role as role_name, role as role_code
                          FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) {
    setFlash('error', 'User not found');
    redirect('index.php?page=dashboard');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        
        try {
            $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$firstName, $lastName, $email, $phone, $userId]);
            
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            
            setFlash('success', 'Profile updated successfully!');
        } catch (Exception $e) {
            setFlash('error', 'Failed to update profile: ' . $e->getMessage());
        }
        redirect('index.php?page=profile/settings');
    }
    
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Get current password hash
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userPass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userPass || !password_verify($currentPassword, $userPass['password'])) {
            setFlash('error', 'Current password is incorrect');
        } elseif ($newPassword !== $confirmPassword) {
            setFlash('error', 'New passwords do not match');
        } elseif (strlen($newPassword) < 6) {
            setFlash('error', 'Password must be at least 6 characters');
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            setFlash('success', 'Password changed successfully!');
        }
        redirect('index.php?page=profile/settings');
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Information -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-circle me-2"></i>Profile Information
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" required
                                   value="<?php echo sanitize($user['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name"
                                   value="<?php echo sanitize($user['last_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($user['username']); ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?php echo sanitize($user['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" maxlength="10"
                                   value="<?php echo sanitize($user['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($user['role_name'] ?? $_SESSION['role_name'] ?? 'N/A'); ?>" disabled>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Update Profile
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-key me-2"></i>Change Password
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-key me-1"></i>Change Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Account Info -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2"></i>Account Info
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="text-muted">Account Status</td>
                        <td>
                            <?php if ($user['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Created</td>
                        <td><?php echo formatDate($user['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Last Login</td>
                        <td><?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'N/A'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-lightning me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="index.php?page=auth/logout" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
