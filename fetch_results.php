<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if results should be public
    $settings_stmt = $db->prepare("
        SELECT setting_value 
        FROM election_settings 
        WHERE setting_key = 'results_public'
    ");
    $settings_stmt->execute();
    $results_public = $settings_stmt->fetchColumn();
    
    // If results are not public, only show if user is admin or if voting has ended
    if (!$results_public) {
        // Check if voting period has ended
        $voting_end_stmt = $db->prepare("
            SELECT setting_value 
            FROM election_settings 
            WHERE setting_key = 'election_end_date'
        ");
        $voting_end_stmt->execute();
        $end_date = $voting_end_stmt->fetchColumn();
        
        if ($end_date && strtotime($end_date) > time()) {
            // Voting hasn't ended and results aren't public
            if (!Auth::isAdminLoggedIn()) {
                echo json_encode([
                    'error' => 'Results not yet available',
                    'message' => 'Results will be available after voting ends'
                ]);
                exit();
            }
        }
    }
    
    // Fetch comprehensive election results
    $results_stmt = $db->prepare("
        SELECT 
            n.id,
            n.candidate_name, 
            n.position, 
            n.manifesto,
            n.photo, 
            COUNT(v.id) as votes,
            n.created_at,
            (SELECT COUNT(DISTINCT user_id) 
             FROM votes v2 
             WHERE v2.candidate_id IN 
                (SELECT id FROM nominations n2 WHERE n2.position = n.position)
            ) as position_total_voters
        FROM 
            nominations n
        LEFT JOIN 
            votes v ON n.id = v.candidate_id
        WHERE 
            n.is_approved = 1
        GROUP BY 
            n.id, n.candidate_name, n.position, n.manifesto, n.photo, n.created_at
        ORDER BY 
            CASE n.position
                WHEN 'president' THEN 1
                WHEN 'vice_president' THEN 2
                WHEN 'secretary' THEN 3
                WHEN 'treasurer' THEN 4
                ELSE 5
            END,
            votes DESC, n.candidate_name ASC
    ");
    
    $results_stmt->execute();
    $results = $results_stmt->fetchAll();
    
    // Process results to add additional information
    $processed_results = [];
    $position_stats = [];
    
    foreach ($results as $result) {
        $position = $result['position'];
        
        // Initialize position stats if not exists
        if (!isset($position_stats[$position])) {
            $position_stats[$position] = [
                'total_votes' => 0,
                'total_candidates' => 0,
                'leading_candidate' => null,
                'is_tie' => false
            ];
        }
        
        // Update position statistics
        $position_stats[$position]['total_votes'] += (int)$result['votes'];
        $position_stats[$position]['total_candidates']++;
        
        // Determine leading candidate
        if ($position_stats[$position]['leading_candidate'] === null || 
            (int)$result['votes'] > $position_stats[$position]['leading_candidate']['votes']) {
            $position_stats[$position]['leading_candidate'] = [
                'name' => $result['candidate_name'],
                'votes' => (int)$result['votes']
            ];
            $position_stats[$position]['is_tie'] = false;
        } elseif ((int)$result['votes'] === $position_stats[$position]['leading_candidate']['votes'] && 
                  (int)$result['votes'] > 0) {
            $position_stats[$position]['is_tie'] = true;
        }
        
        // Calculate percentage
        $total_position_votes = $position_stats[$position]['total_votes'];
        $percentage = $total_position_votes > 0 ? 
            round(((int)$result['votes'] / $total_position_votes) * 100, 2) : 0;
        
        // Determine if this candidate is winning
        $is_winner = !$position_stats[$position]['is_tie'] && 
                    $position_stats[$position]['leading_candidate']['name'] === $result['candidate_name'] &&
                    (int)$result['votes'] > 0;
        
        // Add processed result
        $processed_result = [
            'id' => $result['id'],
            'candidate_name' => $result['candidate_name'],
            'position' => $result['position'],
            'position_display' => ucfirst(str_replace('_', ' ', $result['position'])),
            'manifesto' => $result['manifesto'],
            'votes' => (int)$result['votes'],
            'percentage' => $percentage,
            'is_winner' => $is_winner,
            'is_tie' => $position_stats[$position]['is_tie'],
            'position_total_votes' => $total_position_votes,
            'position_total_voters' => (int)$result['position_total_voters'],
            'created_at' => $result['created_at']
        ];
        
        // Include photo if available
        if ($result['photo']) {
            $processed_result['photo'] = base64_encode($result['photo']);
        }
        
        $processed_results[] = $processed_result;
    }
    
    // Get overall election statistics
    $overall_stats_stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_registered_users,
            (SELECT COUNT(DISTINCT user_id) FROM votes) as total_voters,
            (SELECT COUNT(*) FROM nominations WHERE is_approved = 1) as total_candidates,
            (SELECT COUNT(*) FROM votes) as total_votes_cast,
            (SELECT COUNT(DISTINCT position) FROM nominations WHERE is_approved = 1) as total_positions
    ");
    $overall_stats_stmt->execute();
    $overall_stats = $overall_stats_stmt->fetch();
    
    // Calculate turnout rate
    $turnout_rate = $overall_stats['total_registered_users'] > 0 ? 
        round(($overall_stats['total_voters'] / $overall_stats['total_registered_users']) * 100, 2) : 0;
    
    // Prepare final response
    $response = [
        'success' => true,
        'results' => $processed_results,
        'statistics' => [
            'total_registered_users' => (int)$overall_stats['total_registered_users'],
            'total_voters' => (int)$overall_stats['total_voters'],
            'total_candidates' => (int)$overall_stats['total_candidates'],
            'total_votes_cast' => (int)$overall_stats['total_votes_cast'],
            'total_positions' => (int)$overall_stats['total_positions'],
            'turnout_rate' => $turnout_rate
        ],
        'position_statistics' => $position_stats,
        'last_updated' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ];
    
    // Log results access (but don't log for frequent automated requests)
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        Security::logSecurityEvent('results_accessed', [
            'results_count' => count($processed_results),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Fetch results error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to fetch results',
        'message' => 'An error occurred while retrieving election results. Please try again later.',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>