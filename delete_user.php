<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Require admin authentication
Auth::requireAdminLogin();

// Only process POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: admin_dashboard.php");
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
    $_SESSION['error_message'] = "Invalid request. Please try again.";
    Security::logSecurityEvent('csrf_token_invalid', ['page' => 'delete_user'], null, $_SESSION['admin_id']);
    header("Location: admin_dashboard.php");
    exit();
}

$user_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($user_id <= 0) {
    $_SESSION['error_message'] = "Invalid user ID.";
    header("Location: admin_dashboard.php");
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Start transaction for data consistency
    $db->beginTransaction();
    
    // Get user details before deletion for logging
    $user_stmt = $db->prepare("
        SELECT username, email, full_name, created_at, last_login
        FROM users 
        WHERE id = ?
    ");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
    // Prevent deletion of currently logged-in admin (if they somehow have a user account)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        throw new Exception("Cannot delete your own account");
    }
    
    // Check if user has cast any votes
    $votes_stmt = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ?");
    $votes_stmt->execute([$user_id]);
    $vote_count = $votes_stmt->fetch()['vote_count'];
    
    // Check if user has any nominations
    $nominations_stmt = $db->prepare("SELECT COUNT(*) as nomination_count FROM nominations WHERE user_id = ?");
    $nominations_stmt->execute([$user_id]);
    $nomination_count = $nominations_stmt->fetch()['nomination_count'];
    
    // Delete user's votes first (if any)
    if ($vote_count > 0) {
        $delete_votes_stmt = $db->prepare("DELETE FROM votes WHERE user_id = ?");
        $delete_votes_stmt->execute([$user_id]);
    }
    
    // Update nominations to remove user association (don't delete nominations, just unlink)
    if ($nomination_count > 0) {
        $update_nominations_stmt = $db->prepare("UPDATE nominations SET user_id = NULL WHERE user_id = ?");
        $update_nominations_stmt->execute([$user_id]);
    }
    
    // Delete user sessions
    $delete_sessions_stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND user_type = 'user'");
    $delete_sessions_stmt->execute([$user_id]);
    
    // Delete the user
    $delete_user_stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $delete_user_stmt->execute([$user_id]);
    
    if ($delete_user_stmt->rowCount() === 0) {
        throw new Exception("Failed to delete user");
    }
    
    // Commit transaction
    $db->commit();
    
    // Log successful deletion
    Security::logSecurityEvent('user_deleted', [
        'deleted_user_id' => $user_id,
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'had_votes' => $vote_count > 0,
        'votes_deleted' => $vote_count,
        'had_nominations' => $nomination_count > 0,
        'nominations_unlinked' => $nomination_count,
        'account_age_days' => $user['created_at'] ? floor((time() - strtotime($user['created_at'])) / 86400) : 0
    ], null, $_SESSION['admin_id']);
    
    $_SESSION['success_message'] = "User " . htmlspecialchars($user['username']) . " has been successfully deleted. " . 
                                   ($vote_count > 0 ? "$vote_count votes were also removed. " : "") . 
                                   ($nomination_count > 0 ? "$nomination_count nominations were unlinked. " : "");
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Delete user error: " . $e->getMessage());
    
    $_SESSION['error_message'] = "Failed to delete user: " . $e->getMessage();
    
    // Log failed deletion attempt
    Security::logSecurityEvent('user_deletion_failed', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ], null, $_SESSION['admin_id']);
}

// Redirect back to admin dashboard
header("Location: admin_dashboard.php");
exit();
?>