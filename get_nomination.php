<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check admin authentication
Auth::requireAdminLogin();

// Set JSON header
header('Content-Type: application/json');

try {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid nomination ID');
    }
    
    $id = (int)$_GET['id'];
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        SELECT id, candidate_name, position, manifesto, is_approved, created_at
        FROM nominations 
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $nomination = $stmt->fetch();
    
    if ($nomination) {
        // Log the access
        Security::logSecurityEvent('nomination_viewed', [
            'nomination_id' => $id,
            'candidate_name' => $nomination['candidate_name']
        ], null, $_SESSION['admin_id']);
        
        echo json_encode($nomination);
    } else {
        echo json_encode(['error' => 'Nomination not found']);
    }
    
} catch (Exception $e) {
    error_log("Get nomination error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to retrieve nomination']);
}
?>