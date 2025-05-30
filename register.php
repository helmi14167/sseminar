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
$email = '';
$full_name = '';
$password = '';
$repeat_password = '';

// Function to check if student ID exists in university system
function checkStudentId($studentId) {
    $url = "https://student.alquds.edu/assets/image/profile/$studentId.jpg";
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; UCES/1.0)'
        ]
    ]);
    $headers = @get_headers($url, 1, $context);
    return $headers && strpos($headers[0], "200") !== false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please try again.";
        Security::logSecurityEvent('csrf_token_invalid', ['page' => 'register']);
    } else {
        // Sanitize input
        $username = Security::sanitizeInput($_POST['username'] ?? '');
        $email = Security::sanitizeInput($_POST['email'] ?? '');
        $full_name = Security::sanitizeInput($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $repeat_password = $_POST['repeat_password'] ?? '';
        
        // Validation
        if (empty($username)) {
            $errors[] = "Please enter your student ID.";
        } elseif (!Validator::validateStudentId($username)) {
            $errors[] = "Invalid student ID format.";
        }
        
        if (empty($email)) {
            $errors[] = "Please enter your email address.";
        } elseif (!Validator::validateEmail($email)) {
            $errors[] = "Please enter a valid email address.";
        }
        
        if (empty($full_name)) {
            $errors[] = "Please enter your full name.";
        } elseif (strlen($full_name) < 2) {
            $errors[] = "Full name must be at least 2 characters long.";
        }
        
        if (empty($password)) {
            $errors[] = "Please enter a password.";
        } elseif (!Validator::validatePassword($password)) {
            $errors[] = "Password must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, and one number.";
        }
        
        if ($password !== $repeat_password) {
            $errors[] = "Passwords do not match.";
        }
        
        // Check rate limiting
        $client_ip = Security::getClientIP();
        if (!Security::checkRateLimit($client_ip, 3, 300)) { // 3 registrations per 5 minutes
            $errors[] = "Too many registration attempts. Please try again later.";
            Security::logSecurityEvent('registration_rate_limit_exceeded', ['ip' => $client_ip]);
        }
        
        if (empty($errors)) {
            try {
                // Verify student ID exists in university system
                if (!checkStudentId($username)) {
                    $errors[] = "Invalid student ID. Please verify your student ID is correct.";
                    Security::logSecurityEvent('registration_invalid_student_id', ['username' => $username]);
                } else {
                    $db = Database::getInstance()->getConnection();
                    
                    // Check if username already exists
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $errors[] = "This student ID is already registered.";
                        Security::logSecurityEvent('registration_duplicate_username', ['username' => $username]);
                    } else {
                        // Check if email already exists
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $errors[] = "This email address is already registered.";
                            Security::logSecurityEvent('registration_duplicate_email', ['email' => $email]);
                        } else {
                            // Hash password
                            $hashedPassword = Security::hashPassword($password);
                            
                            // Insert new user
                            $stmt = $db->prepare("
                                INSERT INTO users (username, password, email, full_name, created_at, is_active) 
                                VALUES (?, ?, ?, ?, NOW(), 1)
                            ");
                            
                            if ($stmt->execute([$username, $hashedPassword, $email, $full_name])) {
                                $user_id = $db->lastInsertId();
                                
                                // Log successful registration
                                Security::logSecurityEvent('registration_success', [
                                    'username' => $username,
                                    'email' => $email
                                ], $user_id);
                                
                                // Redirect to login with success message
                                header("Location: login.php?registered=1");
                                exit();
                            } else {
                                $errors[] = "Registration failed. Please try again.";
                                error_log("Registration failed for user: $username");
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = "An error occurred during registration. Please try again.";
            }
        }
        
        // Log failed registration attempt
        if (!empty($errors)) {
            Security::logSecurityEvent('registration_failed', [
                'username' => $username,
                'email' => $email,
                'errors' => $errors
            ]);
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
    <title><?php echo SITE_NAME; ?> - Register</title>
    <meta name="description" content="Register for the University Council Election System">
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
        
        .register-container {
            background: rgba(51, 51, 51, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            padding: 2.5rem;
            width: 100%;
            max-width: 500px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .register-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1rem;
            background: #fff;
            padding: 10px;
        }
        
        .register-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: #fff;
        }
        
        .register-header p {
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
        
        .form-group .help-text {
            font-size: 0.8rem;
            color: #ccc;
            margin-top: 0.25rem;
        }
        
        .password-strength {
            margin-top: 0.5rem;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: none;
        }
        
        .password-strength.weak {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid rgba(244, 67, 54, 0.3);
            color: #ff6b6b;
        }
        
        .password-strength.medium {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }
        
        .password-strength.strong {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #4CAF50;
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
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #444;
        }
        
        .login-link a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
            color: #66bb6a;
            text-decoration: underline;
        }
        
        .terms {
            font-size: 0.8rem;
            color: #ccc;
            margin-top: 1rem;
            text-align: center;
        }
        
        .terms a {
            color: #4CAF50;
            text-decoration: none;
        }
        
        .terms a:hover {
            text-decoration: underline;
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
            
            .register-container {
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
        <div class="register-container">
            <div class="register-header">
                <img src="logo.png" alt="University Logo" onerror="this.style.display='none'">
                <h1>Student Registration</h1>
                <p>Create your election account</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="username">Student ID *</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           placeholder="Enter your student ID"
                           value="<?php echo htmlspecialchars($username); ?>"
                           required
                           autocomplete="username"
                           maxlength="20">
                    <div class="help-text">Enter your official university student ID</div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           placeholder="Enter your email address"
                           value="<?php echo htmlspecialchars($email); ?>"
                           required
                           autocomplete="email"
                           maxlength="100">
                    <div class="help-text">We'll use this to send you important election updates</div>
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" 
                           id="full_name" 
                           name="full_name" 
                           placeholder="Enter your full name"
                           value="<?php echo htmlspecialchars($full_name); ?>"
                           required
                           autocomplete="name"
                           maxlength="100">
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Create a strong password"
                           required
                           autocomplete="new-password"
                           minlength="8">
                    <div class="help-text">At least 8 characters with uppercase, lowercase, and numbers</div>
                    <div class="password-strength" id="passwordStrength"></div>
                </div>

                <div class="form-group">
                    <label for="repeat_password">Confirm Password *</label>
                    <input type="password" 
                           id="repeat_password" 
                           name="repeat_password" 
                           placeholder="Confirm your password"
                           required
                           autocomplete="new-password"
                           minlength="8">
                    <div class="help-text" id="passwordMatch"></div>
                </div>

                <button type="submit" class="btn" id="registerBtn">
                    Create Account
                    <span class="loading" id="loading"></span>
                </button>
            </form>

            <div class="terms">
                By registering, you agree to our <a href="#" onclick="alert('Terms of Service - Coming Soon')">Terms of Service</a> 
                and <a href="#" onclick="alert('Privacy Policy - Coming Soon')">Privacy Policy</a>
            </div>

            <div class="login-link">
                <p>Already have an account? <a href="login.php">Sign in here</a></p>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Secure registration with data protection.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registerForm');
            const passwordInput = document.getElementById('password');
            const repeatPasswordInput = document.getElementById('repeat_password');
            const passwordStrength = document.getElementById('passwordStrength');
            const passwordMatch = document.getElementById('passwordMatch');
            const registerBtn = document.getElementById('registerBtn');
            const loading = document.getElementById('loading');

            // Student ID validation
            document.getElementById('username').addEventListener('input', function(e) {
                // Allow only numbers for student ID
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            // Password strength checker
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                
                if (password.length > 0) {
                    passwordStrength.style.display = 'block';
                    passwordStrength.className = 'password-strength ' + strength.class;
                    passwordStrength.textContent = strength.message;
                } else {
                    passwordStrength.style.display = 'none';
                }
                
                checkPasswordMatch();
            });

            // Password match checker
            repeatPasswordInput.addEventListener('input', checkPasswordMatch);

            function checkPasswordStrength(password) {
                let score = 0;
                let message = '';
                
                if (password.length >= 8) score++;
                if (/[A-Z]/.test(password)) score++;
                if (/[a-z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;
                
                if (score < 3) {
                    return { class: 'weak', message: '⚠️ Weak password - Add more characters, numbers, and mixed case' };
                } else if (score < 4) {
                    return { class: 'medium', message: '⚡ Medium password - Consider adding special characters' };
                } else {
                    return { class: 'strong', message: '✓ Strong password' };
                }
            }

            function checkPasswordMatch() {
                const password = passwordInput.value;
                const repeatPassword = repeatPasswordInput.value;
                
                if (repeatPassword.length > 0) {
                    if (password === repeatPassword) {
                        passwordMatch.textContent = '✓ Passwords match';
                        passwordMatch.style.color = '#4CAF50';
                    } else {
                        passwordMatch.textContent = '✗ Passwords do not match';
                        passwordMatch.style.color = '#ff6b6b';
                    }
                } else {
                    passwordMatch.textContent = '';
                }
            }

            // Form submission
            form.addEventListener('submit', function(e) {
                const password = passwordInput.value;
                const repeatPassword = repeatPasswordInput.value;
                
                // Final validation
                if (password !== repeatPassword) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long!');
                    return;
                }
                
                // Show loading state
                registerBtn.disabled = true;
                loading.style.display = 'inline-block';
                registerBtn.innerHTML = 'Creating Account... <span class="loading"></span>';
                
                // Re-enable button after 15 seconds as fallback
                setTimeout(function() {
                    registerBtn.disabled = false;
                    loading.style.display = 'none';
                    registerBtn.innerHTML = 'Create Account <span class="loading" id="loading"></span>';
                }, 15000);
            });

            // Auto-focus first empty field
            const username = document.getElementById('username');
            if (!username.value) {
                username.focus();
            }
        });
    </script>
</body>
</html>