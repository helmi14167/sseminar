<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check if admin is already logged in
if (Auth::isAdminLoggedIn()) {
    header("Location: admin_dashboard.php");
    exit();
}

// Initialize variables
$errors = [];
$username = '';
$success_message = '';

// Handle logout message
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success_message = "You have been successfully logged out.";
}

// Handle timeout message
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $errors[] = "Your session has expired. Please log in again.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please try again.";
        Security::logSecurityEvent('admin_csrf_token_invalid', ['page' => 'admin_login']);
    } else {
        // Sanitize input
        $username = Security::sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Basic validation
        if (empty($username)) {
            $errors[] = "Please enter your username.";
        }
        
        if (empty($password)) {
            $errors[] = "Please enter your password.";
        }
        
        // Check rate limiting
        $client_ip = Security::getClientIP();
        if (!Security::checkRateLimit($client_ip . '_admin', MAX_LOGIN_ATTEMPTS, 300)) {
            $errors[] = "Too many login attempts. Please try again later.";
            Security::logSecurityEvent('admin_rate_limit_exceeded', ['ip' => $client_ip]);
        }
        
        if (empty($errors)) {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check admin credentials
                $stmt = $db->prepare("
                    SELECT id, username, password, email, full_name, is_active, role, last_login
                    FROM admins 
                    WHERE username = ?
                ");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();
                
                if ($admin) {
                    // Check if account is active
                    if (!$admin['is_active']) {
                        $errors[] = "Your admin account has been deactivated. Please contact the system administrator.";
                        Security::logSecurityEvent('admin_login_attempt_inactive_account', ['username' => $username]);
                    }
                    // Verify password
                    elseif (Security::verifyPassword($password, $admin['password'])) {
                        // Successful login
                        
                        // Update last login
                        $stmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                        $stmt->execute([$admin['id']]);
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Set session variables
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_login_time'] = time();
                        $_SESSION['admin_last_activity'] = time();
                        
                        // Log successful login
                        Security::logSecurityEvent('admin_login_success', [
                            'role' => $admin['role']
                        ], null, $admin['id']);
                        
                        // Redirect to admin dashboard
                        header("Location: admin_dashboard.php");
                        exit();
                    } else {
                        // Invalid password
                        $errors[] = "Invalid username or password.";
                        Security::logSecurityEvent('admin_login_failed', [
                            'username' => $username,
                            'reason' => 'invalid_password'
                        ]);
                    }
                } else {
                    // User not found
                    $errors[] = "Invalid username or password.";
                    Security::logSecurityEvent('admin_login_attempt_unknown_user', ['username' => $username]);
                }
                
                // Log login attempt for rate limiting
                Security::logSecurityEvent('admin_login_attempt', ['username' => $username]);
                
            } catch (Exception $e) {
                error_log("Admin login error: " . $e->getMessage());
                $errors[] = "An error occurred during login. Please try again.";
            }
        }
    }
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Admin Login</title>
    <meta name="description" content="Admin login for the University Council Election System">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        
        /* Animated background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 119, 48, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(120, 200, 255, 0.3) 0%, transparent 50%);
            filter: blur(40px);
            animation: float 20s ease-in-out infinite;
            z-index: -1;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-30px) rotate(120deg); }
            66% { transform: translateY(-20px) rotate(240deg); }
        }
        
        header {
            background: rgba(17, 17, 17, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid #333;
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #ff6b35;
            text-decoration: none;
        }
        
        nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        nav a {
            color: #fff;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        nav a:hover {
            background: rgba(255, 107, 53, 0.2);
            color: #ff6b35;
        }
        
        main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }
        
        .login-container {
            background: rgba(51, 51, 51, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 107, 53, 0.1), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
            position: relative;
            z-index: 2;
        }
        
        .admin-badge {
            display: inline-block;
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .login-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1rem;
            background: #fff;
            padding: 10px;
            border: 3px solid #ff6b35;
        }
        
        .login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: #fff;
        }
        
        .login-header p {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #fff;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #555;
            border-radius: 8px;
            background: rgba(34, 34, 34, 0.8);
            color: #fff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }
        
        .form-group input::placeholder {
            color: #999;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #f7931e, #ff6b35);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid rgba(244, 67, 54, 0.3);
            color: #ff6b6b;
        }
        
        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #4CAF50;
        }
        
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #444;
        }
        
        .back-link a {
            color: #ff6b35;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .back-link a:hover {
            color: #f7931e;
            text-decoration: underline;
        }
        
        .security-features {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: #ffc107;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            margin-top: 1rem;
            text-align: center;
        }
        
        .security-features ul {
            list-style: none;
            margin: 0.5rem 0 0 0;
            padding: 0;
        }
        
        .security-features li {
            margin-bottom: 0.25rem;
        }
        
        footer {
            background: rgba(17, 17, 17, 0.95);
            text-align: center;
            padding: 1rem;
            border-top: 1px solid #333;
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .loading {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .header-content {
                padding: 0 1rem;
            }
            
            nav ul {
                gap: 1rem;
            }
            
            .login-container {
                margin: 1rem;
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <a href="index.html" class="logo">🔐 Admin Portal</a>
            <nav>
                <ul>
                    <li><a href="index.html">← Back to Site</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="login-container">
            <div class="login-header">
                <div class="admin-badge">🛡️ Administrator Access</div>
                <img src="logo.png" alt="University Logo" onerror="this.style.display='none'">
                <h1>Admin Login</h1>
                <p>Secure access to election management</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="adminLoginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="username">Admin Username</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           placeholder="Enter admin username"
                           value="<?php echo htmlspecialchars($username); ?>"
                           required
                           autocomplete="username"
                           maxlength="50">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Enter admin password"
                           required
                           autocomplete="current-password"
                           minlength="6">
                </div>

                <button type="submit" class="btn" id="loginBtn">
                    🔓 Admin Sign In
                    <span class="loading" id="loading"></span>
                </button>
            </form>

            <div class="security-features">
                <div><strong>🔒 Security Features Active</strong></div>
                <ul>
                    <li>• Encrypted connections</li>
                    <li>• Rate limiting protection</li>
                    <li>• Session security</li>
                    <li>• Audit logging</li>
                </ul>
            </div>

            <div class="back-link">
                <p><a href="index.html">← Return to main site</a></p>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Unauthorized access is prohibited and monitored.</p>
    </footer>

    <script>
        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const loading = document.getElementById('loading');
            
            btn.disabled = true;
            loading.style.display = 'inline-block';
            btn.innerHTML = '🔄 Authenticating... <span class="loading"></span>';
            
            // Re-enable button after 10 seconds as fallback
            setTimeout(function() {
                btn.disabled = false;
                loading.style.display = 'none';
                btn.innerHTML = '🔓 Admin Sign In <span class="loading" id="loading"></span>';
            }, 10000);
        });

        // Auto-focus first empty field
        document.addEventListener('DOMContentLoaded', function() {
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            
            if (!username.value) {
                username.focus();
            } else if (!password.value) {
                password.focus();
            }
        });

        // Enhanced security warnings
        console.warn('🚨 ADMIN PORTAL - Unauthorized access is prohibited and logged.');
        console.warn('🛡️ All activities are monitored and audited.');
        
        // Disable right-click context menu for added security
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Disable F12 and developer tools shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F12' || 
                (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J')) ||
                (e.ctrlKey && e.key === 'U')) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>