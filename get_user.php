<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check admin authentication
Auth::requireAdminLogin();

// Set JSON header
header('Content-Type: application/json');

try {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid user ID');
    }
    
    $id = (int)$_GET['id'];
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        SELECT id, username, email, full_name, is_active, created_at, last_login
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Remove sensitive data and log the access
        unset($user['password']);
        
        Security::logSecurityEvent('user_viewed', [
            'viewed_user_id' => $id,
            'username' => $user['username']
        ], null, $_SESSION['admin_id']);
        
        echo json_encode($user);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
    
} catch (Exception $e) {
    error_log("Get user error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to retrieve user']);
}
?>