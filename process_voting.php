<?php
define('UCES_SYSTEM', true);
require_once 'config.php';
require_once 'vote_integrity_system.php'; // Include our integrity system

// Require user authentication
Auth::requireLogin();

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$errors = [];
$success = false;
$verification_tokens = [];

// Only process POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: welcome.php");
    exit();
}

try {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please try again.";
        Security::logSecurityEvent('voting_csrf_invalid', ['user_id' => $user_id], $user_id);
        throw new Exception("CSRF token validation failed");
    }
    
    $db = Database::getInstance()->getConnection();
    $voteIntegrity = VoteIntegrity::getInstance();
    
    // Start transaction for data consistency
    $db->beginTransaction();
    
    // Check if user has already voted
    $voted_check = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ?");
    $voted_check->execute([$user_id]);
    $vote_result = $voted_check->fetch();
    
    if ($vote_result['vote_count'] > 0) {
        $errors[] = "You have already voted in this election.";
        Security::logSecurityEvent('voting_attempt_already_voted', ['user_id' => $user_id], $user_id);
        throw new Exception("User already voted");
    }
    
    // Check if voting is enabled and election is active
    $settings_stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM election_settings 
        WHERE setting_key IN ('voting_enabled', 'election_start_date', 'election_end_date')
    ");
    $settings_stmt->execute();
    $settings_data = $settings_stmt->fetchAll();
    
    $settings = [];
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    
    // Validate election is active
    $voting_enabled = isset($settings['voting_enabled']) && $settings['voting_enabled'] == '1';
    if (!$voting_enabled) {
        $errors[] = "Voting is currently disabled.";
        throw new Exception("Voting disabled");
    }
    
    $election_active = true;
    if (isset($settings['election_start_date']) && isset($settings['election_end_date'])) {
        $now = time();
        $start_time = strtotime($settings['election_start_date']);
        $end_time = strtotime($settings['election_end_date']);
        $election_active = ($now >= $start_time && $now <= $end_time);
    }
    
    if (!$election_active) {
        $errors[] = "Election is not currently active.";
        throw new Exception("Election not active");
    }
    
    // Get all available positions
    $positions_stmt = $db->prepare("
        SELECT DISTINCT position 
        FROM nominations 
        WHERE is_approved = 1
    ");
    $positions_stmt->execute();
    $available_positions = $positions_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($available_positions)) {
        $errors[] = "No candidates available for voting.";
        throw new Exception("No candidates available");
    }
    
    // Validate submitted votes
    $submitted_votes = [];
    $vote_data = [];
    
    foreach ($available_positions as $position) {
        if (isset($_POST[$position]) && !empty($_POST[$position])) {
            $candidate_id = (int)$_POST[$position];
            
            // Verify candidate exists and is approved for this position
            $candidate_check = $db->prepare("
                SELECT id, candidate_name 
                FROM nominations 
                WHERE id = ? AND position = ? AND is_approved = 1
            ");
            $candidate_check->execute([$candidate_id, $position]);
            $candidate = $candidate_check->fetch();
            
            if (!$candidate) {
                $errors[] = "Invalid candidate selection for position: " . htmlspecialchars($position);
                throw new Exception("Invalid candidate selection");
            }
            
            $submitted_votes[$position] = $candidate_id;
            $vote_data[] = [
                'position' => $position,
                'candidate_id' => $candidate_id,
                'candidate_name' => $candidate['candidate_name']
            ];
        }
    }
    
    // Check if user voted for all positions
    if (count($submitted_votes) !== count($available_positions)) {
        $missing_positions = array_diff($available_positions, array_keys($submitted_votes));
        $errors[] = "Please select a candidate for all positions. Missing: " . implode(', ', $missing_positions);
        throw new Exception("Incomplete vote submission");
    }
    
    // Rate limiting check - prevent rapid voting attempts
    $client_ip = Security::getClientIP();
    if (!Security::checkRateLimit($client_ip . '_vote', 1, 60)) { // 1 vote attempt per minute
        $errors[] = "Please wait before attempting to vote again.";
        Security::logSecurityEvent('voting_rate_limit_exceeded', ['user_id' => $user_id], $user_id);
        throw new Exception("Rate limit exceeded");
    }
    
    // Create secure vote records with blockchain-like integrity
    $ip_address = Security::getClientIP();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    foreach ($submitted_votes as $position => $candidate_id) {
        // Prepare vote data for integrity system
        $voteData = [
            'user_id' => $user_id,
            'candidate_id' => $candidate_id,
            'position' => $position,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ];
        
        // Create secure vote record with cryptographic protection
        $integrityResult = $voteIntegrity->createSecureVoteRecord($voteData);
        
        if (!$integrityResult['success']) {
            throw new Exception("Failed to create secure vote record");
        }
        
        // Store verification token for the voter
        $verification_tokens[$position] = [
            'vote_id' => $integrityResult['vote_id'],
            'hash' => $integrityResult['hash'],
            'token' => $integrityResult['verification_token']
        ];
    }
    
    // Log successful voting with integrity information
    Security::logSecurityEvent('vote_cast_success_with_integrity', [
        'vote_count' => count($submitted_votes),
        'positions' => array_keys($submitted_votes),
        'votes' => $vote_data,
        'integrity_hashes' => array_column($verification_tokens, 'hash')
    ], $user_id);
    
    // Commit transaction
    $db->commit();
    $success = true;
    
    // Clear any existing session data that might interfere
    if (isset($_SESSION['voting_data'])) {
        unset($_SESSION['voting_data']);
    }
    
    // Store verification tokens in session for display to user
    $_SESSION['vote_verification_tokens'] = $verification_tokens;
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Enhanced voting process error for user $username: " . $e->getMessage());
    
    if (empty($errors)) {
        $errors[] = "An error occurred while processing your vote. Please try again.";
    }
    
    // Log failed voting attempt
    Security::logSecurityEvent('vote_cast_failed_with_integrity', [
        'error' => $e->getMessage(),
        'submitted_data' => array_keys($_POST)
    ], $user_id);
}

// Redirect with appropriate message
if ($success) {
    // Set success message in session
    $_SESSION['vote_success'] = true;
    $_SESSION['vote_success_time'] = time();
    $_SESSION['vote_with_integrity'] = true; // Flag for enhanced success message
    header("Location: welcome.php?voted=1&integrity=1");
} else {
    // Set error messages in session
    $_SESSION['vote_errors'] = $errors;
    header("Location: welcome.php?vote_error=1");
}
exit();
?>