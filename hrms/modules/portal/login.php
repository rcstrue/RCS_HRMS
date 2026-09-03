<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Login
 * Employees can login using their mobile number OR employee code, plus a PIN.
 *
 * SECURITY (Round 3 hardening):
 *   - CSRF token required on POST (was missing).
 *   - DB-backed lockout: 5/10/20 failures → 15min/1h/24h (was none).
 *   - session_regenerate_id(true) on successful login (was missing → fixation).
 *   - Birth-year PIN fallback REMOVED (was 4-digit brute-force). Employees with
 *     no PIN set must contact HR to set one via the ESS app/admin.
 *   - Plaintext PINs are transparently upgraded to bcrypt on successful login.
 *   - $_SESSION['csrf_token'] rotated after login.
 */

$pageTitle = 'Employee Portal - Login';
$showHeader = false;
$showFooter = false;

// Redirect if already logged in (session_start is handled by config.php,
// but we call it here for the early redirect path before config loads).
session_start();
if (isset($_SESSION['employee_portal']) && $_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/dashboard');
    exit;
}

require_once '../../config/config.php';
require_once '../../includes/database.php';
require_once '../../includes/portal-security.php';

$error = '';
$success = '';
$lockoutWarning = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF validation ──────────────────────────────────────────────────
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $mobileNumber = trim($_POST['mobile_number'] ?? '');
        $employeeCode = trim($_POST['employee_code'] ?? '');
        $pin          = trim($_POST['pin'] ?? '');

        // Require at least one identifier AND the PIN.
        if ((empty($mobileNumber) && empty($employeeCode)) || empty($pin)) {
            $error = 'Please enter your Employee Code or Mobile Number, plus your PIN.';
        } else {
            try {
                $db = Database::getInstance();
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

                // ── Lockout check (before any DB lookup) ──────────────────
                $lockMsg = portal_check_lockout($db, $mobileNumber, $employeeCode, $ip);
                if ($lockMsg) {
                    $error = $lockMsg;
                    $lockoutWarning = $lockMsg;
                } else {
                    // Build query based on provided credentials
                    $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.mobile_number,
                                   e.email, e.designation, e.department, e.date_of_joining,
                                   e.worker_category, e.status, e.profile_pic_url,
                                   e.uan_number, e.esic_number, e.date_of_birth,
                                   c.name as client_name,
                                   u.name as unit_name,
                                   ess.basic_da, ess.hra, ess.gross_salary
                            FROM employees e
                            LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
                                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                            LEFT JOIN clients c ON e.client_id = c.id
                            LEFT JOIN units u ON e.unit_id = u.id
                            WHERE e.status = 'approved'";

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

                    // Verify PIN via the hardened helper (no birth-year fallback)
                    $pinValid = false;
                    if ($employee) {
                        $pinValid = portal_verify_pin($db, (int) $employee['id'], $pin);
                    }

                    if ($employee && $pinValid) {
                        // ── Success: regenerate session ID (prevents fixation) ──
                        session_regenerate_id(true);

                        $_SESSION['employee_portal'] = [
                            'logged_in'     => true,
                            'employee_id'   => $employee['id'],
                            'employee_code' => $employee['employee_code'],
                            'full_name'     => $employee['full_name'],
                            'designation'   => $employee['designation'],
                            'client_name'   => $employee['client_name'],
                            'unit_name'     => $employee['unit_name'],
                            'photo_path'    => $employee['profile_pic_url'],
                            'login_time'    => time(),
                        ];

                        // Rotate the CSRF token for the new session.
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        // Clear any failed-login counter for this identifier+IP.
                        portal_clear_lockout($db, $mobileNumber, $employeeCode, $ip);

                        // Audit log (consistent table + column names with admin login).
                        try {
                            $db->insert('audit_log', [
                                'user_id'    => $employee['id'],
                                'action'     => 'employee_portal_login',
                                'details'    => json_encode([
                                    'employee_code' => $employee['employee_code'],
                                    'ip'            => $ip,
                                ]),
                                'ip_address' => $ip,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                        } catch (Exception $logEx) {
                            error_log('portal login audit_log insert failed: ' . $logEx->getMessage());
                        }

                        header('Location: index.php?page=portal/dashboard');
                        exit;
                    } else {
                        // ── Failure: record the attempt + apply lockout if needed ──
                        portal_record_failed_login($db, $mobileNumber, $employeeCode, $ip);

                        // Re-check lockout so we can show the lockout message immediately
                        // once the threshold is crossed.
                        $lockMsg2 = portal_check_lockout($db, $mobileNumber, $employeeCode, $ip);
                        if ($lockMsg2) {
                            $error = $lockMsg2;
                            $lockoutWarning = $lockMsg2;
                        } else {
                            $error = 'Invalid credentials. Please try again.';
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again.';
                error_log('Employee portal login error: ' . $e->getMessage());
            }
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
        .lockout-banner {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            color: #664d03;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .security-footer {
            text-align: center;
            margin-top: 15px;
            font-size: 11px;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .security-footer .badge-sec {
            background: #e8eaf6;
            color: #3f51b5;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
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

            <?php if ($lockoutWarning): ?>
            <div class="lockout-banner">
                <i class="bi bi-shield-lock-exclamation"></i>
                <span><?php echo htmlspecialchars($lockoutWarning, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php echo getCSRFTokenField(); ?>
                <div class="form-floating">
                    <input type="text" class="form-control" id="employee_code" name="employee_code" 
                           placeholder="Employee Code" value="<?php echo htmlspecialchars($_POST['employee_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="username">
                    <label for="employee_code"><i class="bi bi-person-badge me-2"></i>Employee Code</label>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="pin" name="pin"
                           placeholder="PIN" maxlength="10" required
                           autocomplete="current-password" inputmode="numeric" pattern="[0-9]*">
                    <label for="pin"><i class="bi bi-lock me-2"></i>PIN</label>
                </div>
                
                <div class="divider">
                    <span>OR use mobile</span>
                </div>
                
                <div class="form-floating">
                    <input type="tel" class="form-control" id="mobile_number" name="mobile_number" 
                           placeholder="Mobile Number" pattern="[0-9]{10}" maxlength="10"
                           value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="tel">
                    <label for="mobile_number"><i class="bi bi-phone me-2"></i>Mobile Number</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mt-3" <?php echo $lockoutWarning ? 'disabled' : ''; ?>>
                    <i class="bi bi-box-arrow-in-right me-2"></i><?php echo $lockoutWarning ? 'Account Locked' : 'Login to Portal'; ?>
                </button>
            </form>
            
            <div class="info-box">
                <div class="d-flex align-items-start">
                    <i class="bi bi-info-circle me-2"></i>
                    <div>
                        <strong>How to Login?</strong>
                        <p class="mb-0 small text-muted">
                            Enter your Employee Code (or Mobile Number) and your PIN.
                            Don't have a PIN? Please contact HR to set one.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="admin-link">
                <a href="index.php?page=auth/login">
                    <i class="bi bi-shield-lock me-1"></i>Admin Login
                </a>
            </div>
            <div class="security-footer">
                <i class="bi bi-lock-fill"></i>
                <span>Protected by</span>
                <span class="badge-sec">CSRF · Lockout · Secure PIN</span>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
