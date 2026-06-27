<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Login
 * Employees can login using their mobile number and employee code
 */

$pageTitle = 'Employee Portal - Login';
$showHeader = false;
$showFooter = false;

// Redirect if already logged in
session_start();
if (isset($_SESSION['employee_portal']) && $_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/dashboard');
    exit;
}

require_once '../../config/config.php';
require_once '../../includes/database.php';

$error = '';
$success = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobileNumber = trim($_POST['mobile_number'] ?? '');
    $employeeCode = trim($_POST['employee_code'] ?? '');
    
    if (empty($mobileNumber) && empty($employeeCode)) {
        $error = 'Please enter either Mobile Number or Employee Code';
    } else {
        try {
            $db = Database::getInstance();
            
            // Build query based on provided credentials
            $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.mobile_number, 
                           e.email, e.designation, e.department, e.date_of_joining,
                           e.worker_category, e.status, e.photo_path,
                           e.uan_number, e.esi_number, e.is_pf_applicable, e.is_esi_applicable,
                           c.name as client_name,
                           u.name as unit_name,
                           ess.basic_da, ess.hra, ess.gross_salary
                    FROM employees e
                    LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                        AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE e.status = 'active'";
            
            $params = [];
            
            if (!empty($mobileNumber)) {
                $sql .= " AND e.mobile_number = :mobile_number";
                $params['mobile_number'] = $mobileNumber;
            }
            
            if (!empty($employeeCode)) {
                $sql .= " AND e.employee_code = :employee_code";
                $params['employee_code'] = $employeeCode;
            }
            
            $sql .= " LIMIT 1";
            
            $employee = $db->fetch($sql, $params);
            
            if ($employee) {
                // Set session
                $_SESSION['employee_portal'] = [
                    'logged_in' => true,
                    'employee_id' => $employee['id'],
                    'employee_code' => $employee['employee_code'],
                    'full_name' => $employee['full_name'],
                    'designation' => $employee['designation'],
                    'client_name' => $employee['client_name'],
                    'unit_name' => $employee['unit_name'],
                    'photo_path' => $employee['photo_path'],
                    'login_time' => time()
                ];
                
                // Log the login
                $db->insert('activity_log', [
                    'user_id' => null,
                    'action' => 'employee_portal_login',
                    'module' => 'portal',
                    'description' => "Employee {$employee['employee_code']} - {$employee['full_name']} logged into portal",
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                header('Location: index.php?page=portal/dashboard');
                exit;
            } else {
                $error = 'Employee not found or not active. Please check your details.';
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log('Employee portal login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Portal - RCS HRMS Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 420px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .login-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            padding: 10px;
            margin-bottom: 15px;
        }
        .login-header h4 {
            margin: 0;
            font-weight: 600;
        }
        .login-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .login-body {
            padding: 30px;
        }
        .form-floating {
            margin-bottom: 15px;
        }
        .form-floating input {
            border-radius: 10px;
            border: 2px solid #e0e0e0;
        }
        .form-floating input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e0e0e0;
        }
        .divider span {
            padding: 0 15px;
            color: #888;
            font-size: 12px;
        }
        .info-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        .info-box i {
            color: #667eea;
            font-size: 20px;
        }
        .admin-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .admin-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <img src="assets/images/logo.png" alt="RCS Logo" onerror="this.src='https://via.placeholder.com/80?text=RCS'">
            <h4>RCS HRMS Pro</h4>
            <p>Employee Self-Service Portal</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-floating">
                    <input type="text" class="form-control" id="employee_code" name="employee_code" 
                           placeholder="Employee Code" value="<?php echo htmlspecialchars($_POST['employee_code'] ?? ''); ?>">
                    <label for="employee_code"><i class="bi bi-person-badge me-2"></i>Employee Code</label>
                </div>
                
                <div class="divider">
                    <span>OR</span>
                </div>
                
                <div class="form-floating">
                    <input type="tel" class="form-control" id="mobile_number" name="mobile_number" 
                           placeholder="Mobile Number" pattern="[0-9]{10}" maxlength="10"
                           value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>">
                    <label for="mobile_number"><i class="bi bi-phone me-2"></i>Mobile Number</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mt-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login to Portal
                </button>
            </form>
            
            <div class="info-box">
                <div class="d-flex align-items-start">
                    <i class="bi bi-info-circle me-2"></i>
                    <div>
                        <strong>How to Login?</strong>
                        <p class="mb-0 small text-muted">
                            Enter your Employee Code OR Mobile Number to access your portal. 
                            Contact HR if you don't know your employee code.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="admin-link">
                <a href="index.php?page=auth/login">
                    <i class="bi bi-shield-lock me-1"></i>Admin Login
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
