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
                    'success' => false,
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
            n.created_at
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
            COUNT(v.id) DESC, n.candidate_name ASC
    ");
    
    $results_stmt->execute();
    $results = $results_stmt->fetchAll();
    
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
    
    // Process results to add additional information
    $processed_results = [];
    $position_stats = [];
    
    foreach ($results as $result) {
        $position = $result['position'];
        $votes = (int)$result['votes'];
        
        // Initialize position stats if not exists
        if (!isset($position_stats[$position])) {
            $position_stats[$position] = [
                'total_votes' => 0,
                'total_candidates' => 0,
                'candidates' => []
            ];
        }
        
        // Update position statistics
        $position_stats[$position]['total_votes'] += $votes;
        $position_stats[$position]['total_candidates']++;
        $position_stats[$position]['candidates'][] = [
            'name' => $result['candidate_name'],
            'votes' => $votes
        ];
        
        // Calculate percentage for this position
        $position_total = 0;
        foreach ($results as $r) {
            if ($r['position'] === $position) {
                $position_total += (int)$r['votes'];
            }
        }
        
        $percentage = $position_total > 0 ? round(($votes / $position_total) * 100, 2) : 0;
        
        // Add processed result
        $processed_result = [
            'id' => (int)$result['id'],
            'candidate_name' => $result['candidate_name'],
            'position' => $result['position'],
            'position_display' => ucfirst(str_replace('_', ' ', $result['position'])),
            'manifesto' => $result['manifesto'],
            'votes' => $votes,
            'percentage' => $percentage,
            'position_total_votes' => $position_total,
            'created_at' => $result['created_at']
        ];
        
        // Include photo if available
        if ($result['photo']) {
            $processed_result['photo'] = base64_encode($result['photo']);
        }
        
        $processed_results[] = $processed_result;
    }
    
    // Determine winners for each position
    foreach ($position_stats as $position => &$stats) {
        if (!empty($stats['candidates'])) {
            usort($stats['candidates'], function($a, $b) {
                return $b['votes'] - $a['votes'];
            });
            
            $highest_votes = $stats['candidates'][0]['votes'];
            $stats['winner'] = $highest_votes > 0 ? $stats['candidates'][0]['name'] : null;
            
            // Check for ties
            $tied_candidates = array_filter($stats['candidates'], function($c) use ($highest_votes) {
                return $c['votes'] === $highest_votes && $highest_votes > 0;
            });
            $stats['is_tie'] = count($tied_candidates) > 1;
        }
    }
    
    // Mark winners in processed results
    foreach ($processed_results as &$result) {
        $position = $result['position'];
        $result['is_winner'] = isset($position_stats[$position]['winner']) && 
                               $position_stats[$position]['winner'] === $result['candidate_name'] &&
                               !$position_stats[$position]['is_tie'];
        $result['is_tie'] = isset($position_stats[$position]['is_tie']) ? 
                           $position_stats[$position]['is_tie'] : false;
    }
    
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