<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check if user is logged in before logging out
$was_logged_in = Auth::isLoggedIn();
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : null;

// Log the logout event if user was logged in
if ($was_logged_in && $user_id) {
    Security::logSecurityEvent('user_logout', [
        'username' => $username,
        'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : 0
    ], $user_id);
}

// Perform logout
Auth::logout();

// Redirect to login page with logout confirmation
header("Location: login.php?logout=1");
exit();
?>