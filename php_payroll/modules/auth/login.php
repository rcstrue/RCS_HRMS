<?php
/**
 * RCS HRMS Pro - Login Page
 * Updated with CSRF protection
 */

$pageTitle = 'Login';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken()) {
        setFlash('error', 'Invalid request. Please try again.');
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? ''; // Don't sanitize password before verification
        
        if (empty($username) || empty($password)) {
            setFlash('error', 'Please enter username and password.');
        } else {
            $result = $auth->login($username, $password);
            
            if (!empty($result['success'])) {
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                redirect('index.php?page=dashboard');
            } else {
                $errorMsg = $result['error'] ?? $result['message'] ?? 'Login failed. Please try again.';
                setFlash('error', $errorMsg);
            }
        }
    }
}

// Get flash message
$flash = getFlash();
?>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <h1>🏢 RCS HRMS Pro</h1>
            <p>Human Resource Management System</p>
        </div>
        
        <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
            <?php echo sanitize($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="login-form">
            <?php echo getCSRFTokenField(); ?>
            <div class="form-group mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" 
                       placeholder="Enter your username" required autofocus
                       value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            
            <div class="form-group mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" 
                       placeholder="Enter your password" required>
            </div>
            
            <div class="form-group mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>
        
        <div class="login-footer mt-4">
            <p class="mb-1">
                <a href="#">Forgot Password?</a>
            </p>
            <p class="text-muted mb-0 small">
                © <?php echo date('Y'); ?> RCS TRUE FACILITIES PVT LTD
            </p>
        </div>
    </div>
    
    <div class="text-center mt-3">
        <small class="text-white-50">Version 1.0.0</small>
    </div>
</div>

<style>
.login-wrapper {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
}

.login-card {
    background: white;
    border-radius: 10px;
    padding: 40px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

.login-logo h1 {
    font-size: 24px;
    margin-bottom: 5px;
    color: #333;
}

.login-logo p {
    color: #666;
    margin-bottom: 30px;
}

.login-form .form-control {
    padding: 12px;
    font-size: 14px;
}

.login-form .btn-primary {
    padding: 12px;
    font-size: 16px;
    margin-top: 10px;
}

.login-footer {
    text-align: center;
}

.login-footer a {
    color: #667eea;
}
</style>
