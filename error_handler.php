<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

/**
 * Custom Error Handler for UCES
 * Provides user-friendly error pages and security
 */

// Get error code from URL or default to 404
$error_code = isset($_GET['code']) ? (int)$_GET['code'] : 404;
$error_message = isset($_GET['message']) ? Security::sanitizeInput($_GET['message']) : '';

// Define error messages
$errors = [
    400 => [
        'title' => 'Bad Request',
        'message' => 'The request could not be understood by the server.',
        'icon' => '⚠️'
    ],
    401 => [
        'title' => 'Unauthorized',
        'message' => 'You need to log in to access this resource.',
        'icon' => '🔒'
    ],
    403 => [
        'title' => 'Forbidden',
        'message' => 'You don\'t have permission to access this resource.',
        'icon' => '🚫'
    ],
    404 => [
        'title' => 'Page Not Found',
        'message' => 'The page you\'re looking for doesn\'t exist.',
        'icon' => '🔍'
    ],
    500 => [
        'title' => 'Internal Server Error',
        'message' => 'Something went wrong on our end. Please try again later.',
        'icon' => '⚙️'
    ],
    503 => [
        'title' => 'Service Unavailable',
        'message' => 'The service is temporarily unavailable. Please try again later.',
        'icon' => '🛠️'
    ]
];

$error_info = $errors[$error_code] ?? $errors[404];

// Log error for debugging
Security::logSecurityEvent('error_page_accessed', [
    'error_code' => $error_code,
    'error_message' => $error_message,
    'referrer' => $_SERVER['HTTP_REFERER'] ?? 'Unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
]);

// Set appropriate HTTP status code
http_response_code($error_code);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $error_info['title']; ?> - <?php echo SITE_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }
        
        .error-container {
            max-width: 600px;
            background: rgba(51, 51, 51, 0.8);
            border-radius: 15px;
            padding: 3rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .error-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }
        
        .error-code {
            font-size: 6rem;
            font-weight: bold;
            color: #ff6b6b;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .error-title {
            font-size: 2rem;
            color: #4CAF50;
            margin-bottom: 1rem;
        }
        
        .error-message {
            font-size: 1.1rem;
            color: #ccc;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .error-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #45a049, #4CAF50);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        
        .btn-secondary {
            background: #666;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #777;
            transform: translateY(-2px);
        }
        
        .error-details {
            margin-top: 2rem;
            padding: 1rem;
            background: rgba(34, 34, 34, 0.5);
            border-radius: 8px;
            font-size: 0.9rem;
            color: #999;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        @media (max-width: 768px) {
            .error-container {
                padding: 2rem;
                margin: 1rem;
            }
            
            .error-code {
                font-size: 4rem;
            }
            
            .error-title {
                font-size: 1.5rem;
            }
            
            .error-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon"><?php echo $error_info['icon']; ?></div>
        <div class="error-code"><?php echo $error_code; ?></div>
        <h1 class="error-title"><?php echo $error_info['title']; ?></h1>
        <p class="error-message">
            <?php echo $error_message ?: $error_info['message']; ?>
        </p>
        
        <div class="error-actions">
            <a href="index.html" class="btn btn-primary">
                🏠 Go Home
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                ← Go Back
            </a>
            <?php if ($error_code === 401): ?>
                <a href="login.php" class="btn btn-primary">
                    🔓 Login
                </a>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
        <div class="error-details">
            <strong>Debug Information:</strong><br>
            Time: <?php echo date('Y-m-d H:i:s'); ?><br>
            IP: <?php echo Security::getClientIP(); ?><br>
            User Agent: <?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?><br>
            Referrer: <?php echo htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'None'); ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-redirect after certain errors
        <?php if ($error_code === 401): ?>
        setTimeout(function() {
            if (confirm('Would you like to be redirected to the login page?')) {
                window.location.href = 'login.php';
            }
        }, 5000);
        <?php endif; ?>
        
        // Report error to console for debugging
        console.warn('Error <?php echo $error_code; ?>: <?php echo addslashes($error_info['title']); ?>');
    </script>
</body>
</html>