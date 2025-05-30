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
    Security::logSecurityEvent('csrf_token_invalid', ['page' => 'delete_nomination'], null, $_SESSION['admin_id']);
    header("Location: admin_dashboard.php");
    exit();
}

$nomination_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($nomination_id <= 0) {
    $_SESSION['error_message'] = "Invalid nomination ID.";
    header("Location: admin_dashboard.php");
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Start transaction for data consistency
    $db->beginTransaction();
    
    // Get nomination details before deletion for logging
    $nomination_stmt = $db->prepare("
        SELECT candidate_name, position, created_at
        FROM nominations 
        WHERE id = ?
    ");
    $nomination_stmt->execute([$nomination_id]);
    $nomination = $nomination_stmt->fetch();
    
    if (!$nomination) {
        throw new Exception("Nomination not found");
    }
    
    // Check if there are any votes for this candidate
    $votes_stmt = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE candidate_id = ?");
    $votes_stmt->execute([$nomination_id]);
    $vote_count = $votes_stmt->fetch()['vote_count'];
    
    if ($vote_count > 0) {
        // Delete associated votes first
        $delete_votes_stmt = $db->prepare("DELETE FROM votes WHERE candidate_id = ?");
        $delete_votes_stmt->execute([$nomination_id]);
        
        Security::logSecurityEvent('votes_deleted_with_nomination', [
            'nomination_id' => $nomination_id,
            'candidate_name' => $nomination['candidate_name'],
            'deleted_votes_count' => $vote_count
        ], null, $_SESSION['admin_id']);
    }
    
    // Delete the nomination
    $delete_nomination_stmt = $db->prepare("DELETE FROM nominations WHERE id = ?");
    $delete_nomination_stmt->execute([$nomination_id]);
    
    if ($delete_nomination_stmt->rowCount() === 0) {
        throw new Exception("Failed to delete nomination");
    }
    
    // Commit transaction
    $db->commit();
    
    // Log successful deletion
    Security::logSecurityEvent('nomination_deleted', [
        'nomination_id' => $nomination_id,
        'candidate_name' => $nomination['candidate_name'],
        'position' => $nomination['position'],
        'had_votes' => $vote_count > 0,
        'votes_deleted' => $vote_count
    ], null, $_SESSION['admin_id']);
    
    $_SESSION['success_message'] = "Nomination for " . htmlspecialchars($nomination['candidate_name']) . " has been successfully deleted.";
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Delete nomination error: " . $e->getMessage());
    
    $_SESSION['error_message'] = "Failed to delete nomination: " . $e->getMessage();
    
    // Log failed deletion attempt
    Security::logSecurityEvent('nomination_deletion_failed', [
        'nomination_id' => $nomination_id,
        'error' => $e->getMessage()
    ], null, $_SESSION['admin_id']);
}

// Redirect back to admin dashboard
header("Location: admin_dashboard.php");
exit();
?>