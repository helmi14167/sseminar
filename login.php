<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check if user is already logged in
if (Auth::isLoggedIn()) {
    header("Location: welcome.php");
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

// Handle registration success
if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success_message = "Registration successful! You can now log in.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please try again.";
        Security::logSecurityEvent('csrf_token_invalid', ['page' => 'login']);
    } else {
        // Sanitize input
        $username = Security::sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Basic validation
        if (empty($username)) {
            $errors[] = "Please enter your student ID.";
        }
        
        if (empty($password)) {
            $errors[] = "Please enter your password.";
        }
        
        // Check rate limiting
        $client_ip = Security::getClientIP();
        if (!Security::checkRateLimit($client_ip, MAX_LOGIN_ATTEMPTS, 300)) {
            $errors[] = "Too many login attempts. Please try again later.";
            Security::logSecurityEvent('rate_limit_exceeded', ['ip' => $client_ip]);
        }
        
        if (empty($errors)) {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check if account is locked
                $stmt = $db->prepare("
                    SELECT id, username, password, is_active, failed_login_attempts, locked_until, last_login
                    FROM users 
                    WHERE username = ?
                ");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Check if account is locked
                    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                        $errors[] = "Account is temporarily locked due to multiple failed login attempts.";
                        Security::logSecurityEvent('login_attempt_locked_account', ['username' => $username]);
                    }
                    // Check if account is active
                    elseif (!$user['is_active']) {
                        $errors[] = "Your account has been deactivated. Please contact the administrator.";
                        Security::logSecurityEvent('login_attempt_inactive_account', ['username' => $username]);
                    }
                    // Verify password
                    elseif (Security::verifyPassword($password, $user['password'])) {
                        // Successful login
                        
                        // Reset failed login attempts
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$user['id']]);
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();
                        
                        // Log successful login
                        Security::logSecurityEvent('login_success', null, $user['id']);
                        
                        // Redirect to welcome page
                        header("Location: welcome.php");
                        exit();
                    } else {
                        // Invalid password
                        $failed_attempts = $user['failed_login_attempts'] + 1;
                        $locked_until = null;
                        
                        // Lock account if too many failed attempts
                        if ($failed_attempts >= MAX_LOGIN_ATTEMPTS) {
                            $locked_until = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
                            $errors[] = "Too many failed login attempts. Account locked for " . (LOCKOUT_DURATION / 60) . " minutes.";
                        } else {
                            $remaining_attempts = MAX_LOGIN_ATTEMPTS - $failed_attempts;
                            $errors[] = "Invalid student ID or password. $remaining_attempts attempts remaining.";
                        }
                        
                        // Update failed login attempts
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET failed_login_attempts = ?, locked_until = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$failed_attempts, $locked_until, $user['id']]);
                        
                        Security::logSecurityEvent('login_failed', [
                            'username' => $username,
                            'failed_attempts' => $failed_attempts,
                            'locked' => !empty($locked_until)
                        ]);
                    }
                } else {
                    // User not found
                    $errors[] = "Invalid student ID or password.";
                    Security::logSecurityEvent('login_attempt_unknown_user', ['username' => $username]);
                }
                
                // Log login attempt for rate limiting
                Security::logSecurityEvent('login_attempt', ['username' => $username]);
                
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
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
    <title><?php echo SITE_NAME; ?> - Login</title>
    <meta name="description" content="Login to the University Council Election System">
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
            color: #4CAF50;
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
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
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
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1rem;
            background: #fff;
            padding: 10px;
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
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .form-group input::placeholder {
            color: #999;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #45a049, #4CAF50);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
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
        
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #444;
        }
        
        .register-link a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .register-link a:hover {
            color: #66bb6a;
            text-decoration: underline;
        }
        
        .security-note {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: #ffc107;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            margin-top: 1rem;
            text-align: center;
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
            <a href="index.html" class="logo"><?php echo SITE_NAME; ?></a>
            <nav>
                <ul>
                    <li><a href="index.html">Home</a></li>
                    <li><a href="about.html">About</a></li>
                    <li><a href="nomination.php">Nominations</a></li>
                    <li><a href="results.html">Results</a></li>
                    <li><a href="faqs.html">FAQs</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="login-container">
            <div class="login-header">
                <img src="logo.png" alt="University Logo" onerror="this.style.display='none'">
                <h1>Student Login</h1>
                <p>Access your election account</p>
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

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="username">Student ID</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           placeholder="Enter your student ID"
                           value="<?php echo htmlspecialchars($username); ?>"
                           required
                           autocomplete="username"
                           maxlength="20">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required
                           autocomplete="current-password"
                           minlength="6">
                </div>

                <button type="submit" class="btn" id="loginBtn">
                    Sign In
                    <span class="loading" id="loading"></span>
                </button>
            </form>

            <div class="register-link">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
            </div>

            <div class="security-note">
                <i>🔒</i> Your connection is secure and encrypted
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Built with security and privacy in mind.</p>
    </footer>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const loading = document.getElementById('loading');
            
            btn.disabled = true;
            loading.style.display = 'inline-block';
            btn.innerHTML = 'Signing In... <span class="loading"></span>';
            
            // Re-enable button after 10 seconds as fallback
            setTimeout(function() {
                btn.disabled = false;
                loading.style.display = 'none';
                btn.innerHTML = 'Sign In <span class="loading" id="loading"></span>';
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

        // Input validation
        document.getElementById('username').addEventListener('input', function(e) {
            // Remove non-numeric characters for student ID
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>