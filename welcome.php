<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Require user authentication
Auth::requireLogin();

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get user info
    $user_stmt = $db->prepare("
        SELECT username, email, full_name, created_at, last_login
        FROM users 
        WHERE id = ?
    ");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();
    
    // Check if user has already voted
    $voted_stmt = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ?");
    $voted_stmt->execute([$user_id]);
    $vote_result = $voted_stmt->fetch();
    $has_voted = $vote_result['vote_count'] > 0;
    
    // Get user's votes if they have voted
    $user_votes = [];
    if ($has_voted) {
        $votes_stmt = $db->prepare("
            SELECT v.position, n.candidate_name, v.created_at
            FROM votes v
            JOIN nominations n ON v.candidate_id = n.id
            WHERE v.user_id = ?
            ORDER BY v.created_at
        ");
        $votes_stmt->execute([$user_id]);
        $user_votes = $votes_stmt->fetchAll();
    }
    
    // Get approved candidates grouped by position
    $candidates_stmt = $db->prepare("
        SELECT id, candidate_name, position, manifesto, photo
        FROM nominations 
        WHERE is_approved = 1
        ORDER BY position, candidate_name
    ");
    $candidates_stmt->execute();
    $candidates_data = $candidates_stmt->fetchAll();
    
    // Group candidates by position
    $candidates = [];
    foreach ($candidates_data as $candidate) {
        $candidates[$candidate['position']][] = $candidate;
    }
    
    // Check if elections are active
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
    
    $voting_enabled = isset($settings['voting_enabled']) && $settings['voting_enabled'] == '1';
    $election_active = true;
    
    if (isset($settings['election_start_date']) && isset($settings['election_end_date'])) {
        $now = time();
        $start_time = strtotime($settings['election_start_date']);
        $end_time = strtotime($settings['election_end_date']);
        $election_active = ($now >= $start_time && $now <= $end_time);
    }
    
    $can_vote = $voting_enabled && $election_active && !$has_voted && !empty($candidates);
    
} catch (Exception $e) {
    error_log("Welcome page error: " . $e->getMessage());
    $error_message = "An error occurred loading the page.";
    $candidates = [];
    $has_voted = false;
    $can_vote = false;
    $user = ['username' => $username, 'email' => '', 'full_name' => ''];
    $user_votes = [];
}

// Generate CSRF token for voting
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            background: rgba(17, 17, 17, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid #333;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header h1 {
            color: #4CAF50;
            font-size: 1.5rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .user-info {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        nav ul {
            list-style: none;
            display: flex;
            gap: 1rem;
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
        
        .main-content {
            flex: 1;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .welcome-section {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .status-card {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .status-voted {
            border-left: 4px solid #4CAF50;
            background: rgba(76, 175, 80, 0.1);
        }
        
        .status-can-vote {
            border-left: 4px solid #2196F3;
            background: rgba(33, 150, 243, 0.1);
        }
        
        .status-cannot-vote {
            border-left: 4px solid #ff9800;
            background: rgba(255, 152, 0, 0.1);
        }
        
        .voting-section {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .position-group {
            margin-bottom: 2rem;
            padding: 1.5rem;
            border: 1px solid #444;
            border-radius: 8px;
            background: rgba(34, 34, 34, 0.5);
        }
        
        .position-title {
            font-size: 1.2rem;
            color: #4CAF50;
            margin-bottom: 1rem;
            text-transform: capitalize;
        }
        
        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .candidate-card {
            background: rgba(68, 68, 68, 0.8);
            border-radius: 8px;
            padding: 1.5rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .candidate-card:hover {
            border-color: #4CAF50;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        
        .candidate-card.selected {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, 0.2);
        }
        
        .candidate-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 1rem;
            display: block;
            border: 3px solid #4CAF50;
        }
        
        .candidate-name {
            font-size: 1.1rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        
        .candidate-manifesto {
            font-size: 0.9rem;
            color: #ccc;
            line-height: 1.4;
            text-align: center;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
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
        }
        
        .btn-danger {
            background: #f44336;
            color: white;
        }
        
        .btn-danger:hover {
            background: #da190b;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .vote-summary {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .vote-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(76, 175, 80, 0.2);
        }
        
        .vote-item:last-child {
            border-bottom: none;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #4CAF50;
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }
        
        .alert-info {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.3);
            color: #2196F3;
        }
        
        .selection-summary {
            position: sticky;
            top: 100px;
            background: rgba(51, 51, 51, 0.95);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid #4CAF50;
        }
        
        .footer {
            background: rgba(17, 17, 17, 0.95);
            text-align: center;
            padding: 1rem;
            border-top: 1px solid #333;
            color: #ccc;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .candidates-grid {
                grid-template-columns: 1fr;
            }
            
            .selection-summary {
                position: static;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo SITE_NAME; ?></h1>
        <div class="header-actions">
            <div class="user-info">
                Welcome, <?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?>!
            </div>
            <nav>
                <ul>
                    <li><a href="nomination.php">View Candidates</a></li>
                    <li><a href="results.html">Results</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </div>

    <div class="main-content">
        <div class="welcome-section">
            <h1>Student Election Dashboard</h1>
            <p>Welcome to the University Council Election System. Make your voice heard!</p>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-warning">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Voting Status -->
        <?php if ($has_voted): ?>
            <div class="status-card status-voted">
                <h2>✅ Thank You for Voting!</h2>
                <p>You have successfully cast your vote. Your participation helps shape our university's future.</p>
                <p><strong>Voted on:</strong> <?php echo !empty($user_votes) ? date('F j, Y \a\t g:i A', strtotime($user_votes[0]['created_at'])) : 'Unknown'; ?></p>
                
                <?php if (!empty($user_votes)): ?>
                    <div class="vote-summary">
                        <h3>Your Votes:</h3>
                        <?php foreach ($user_votes as $vote): ?>
                            <div class="vote-item">
                                <span><strong><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $vote['position']))); ?>:</strong></span>
                                <span><?php echo htmlspecialchars($vote['candidate_name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif (!$voting_enabled): ?>
            <div class="status-card status-cannot-vote">
                <h2>⚠️ Voting Currently Disabled</h2>
                <p>The voting system is currently disabled by administrators. Please check back later.</p>
            </div>
        <?php elseif (!$election_active): ?>
            <div class="status-card status-cannot-vote">
                <h2>⏰ Election Not Active</h2>
                <p>The election period is not currently active. Please wait for the election to begin.</p>
                <?php if (isset($settings['election_start_date'])): ?>
                    <p><strong>Election starts:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($settings['election_start_date'])); ?></p>
                <?php endif; ?>
                <?php if (isset($settings['election_end_date'])): ?>
                    <p><strong>Election ends:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($settings['election_end_date'])); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif (empty($candidates)): ?>
            <div class="status-card status-cannot-vote">
                <h2>📋 No Candidates Available</h2>
                <p>There are currently no approved candidates for the election.</p>
            </div>
        <?php else: ?>
            <div class="status-card status-can-vote">
                <h2>🗳️ Ready to Vote</h2>
                <p>The election is active and you can cast your vote. Please select one candidate for each position below.</p>
                <div class="alert alert-info">
                    <strong>Important:</strong> You can only vote once. Please review your selections carefully before submitting.
                </div>
            </div>

            <!-- Voting Form -->
            <div class="voting-section">
                <h2>Cast Your Vote</h2>
                <form method="POST" action="process_voting.php" id="votingForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <?php foreach ($candidates as $position => $position_candidates): ?>
                        <div class="position-group">
                            <div class="position-title">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $position))); ?>
                                <span style="color: #ccc; font-size: 0.9rem;">(Select one candidate)</span>
                            </div>
                            
                            <div class="candidates-grid">
                                <?php foreach ($position_candidates as $candidate): ?>
                                    <div class="candidate-card" 
                                         onclick="selectCandidate('<?php echo $position; ?>', <?php echo $candidate['id']; ?>, this)"
                                         data-position="<?php echo $position; ?>"
                                         data-candidate-id="<?php echo $candidate['id']; ?>"
                                         data-candidate-name="<?php echo htmlspecialchars($candidate['candidate_name']); ?>">
                                        
                                        <?php if ($candidate['photo']): ?>
                                            <img src="data:image/jpeg;base64,<?php echo base64_encode($candidate['photo']); ?>" 
                                                 alt="<?php echo htmlspecialchars($candidate['candidate_name']); ?>" 
                                                 class="candidate-photo">
                                        <?php else: ?>
                                            <div class="candidate-photo" style="display: flex; align-items: center; justify-content: center; background: #4CAF50; color: white; font-size: 2rem;">
                                                <?php echo strtoupper(substr($candidate['candidate_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="candidate-name">
                                            <?php echo htmlspecialchars($candidate['candidate_name']); ?>
                                        </div>
                                        
                                        <div class="candidate-manifesto">
                                            <?php echo htmlspecialchars($candidate['manifesto']); ?>
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" name="<?php echo $position; ?>" id="vote_<?php echo $position; ?>" value="">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="selection-summary" id="selectionSummary" style="display: none;">
                        <h3>Your Selections:</h3>
                        <div id="summaryContent"></div>
                        <button type="submit" class="btn btn-primary" id="submitVoteBtn" disabled>
                            🗳️ Submit My Vote
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearAllSelections()">
                            Clear All
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Your vote is secure and anonymous.</p>
    </div>

    <script>
        const selections = {};
        const totalPositions = <?php echo count($candidates); ?>;
        
        function selectCandidate(position, candidateId, cardElement) {
            // Remove previous selection for this position
            const previousSelected = document.querySelector(`[data-position="${position}"].selected`);
            if (previousSelected) {
                previousSelected.classList.remove('selected');
            }
            
            // Select new candidate
            cardElement.classList.add('selected');
            selections[position] = {
                id: candidateId,
                name: cardElement.dataset.candidateName
            };
            
            // Update hidden input
            document.getElementById(`vote_${position}`).value = candidateId;
            
            updateSelectionSummary();
        }
        
        function updateSelectionSummary() {
            const summaryDiv = document.getElementById('selectionSummary');
            const summaryContent = document.getElementById('summaryContent');
            const submitBtn = document.getElementById('submitVoteBtn');
            
            if (Object.keys(selections).length > 0) {
                summaryDiv.style.display = 'block';
                
                let summaryHtml = '';
                for (const [position, candidate] of Object.entries(selections)) {
                    summaryHtml += `
                        <div class="vote-item">
                            <span><strong>${position.replace('_', ' ').toUpperCase()}:</strong></span>
                            <span>${candidate.name}</span>
                        </div>
                    `;
                }
                summaryContent.innerHTML = summaryHtml;
                
                // Enable submit button if all positions are filled
                submitBtn.disabled = Object.keys(selections).length !== totalPositions;
                
                if (Object.keys(selections).length === totalPositions) {
                    submitBtn.innerHTML = '🗳️ Submit My Vote';
                    submitBtn.style.background = 'linear-gradient(135deg, #4CAF50, #45a049)';
                } else {
                    submitBtn.innerHTML = `🗳️ Select ${totalPositions - Object.keys(selections).length} more candidate(s)`;
                    submitBtn.style.background = '#666';
                }
            } else {
                summaryDiv.style.display = 'none';
            }
        }
        
        function clearAllSelections() {
            // Clear visual selections
            document.querySelectorAll('.candidate-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Clear hidden inputs
            document.querySelectorAll('input[type="hidden"][name]').forEach(input => {
                if (input.name !== 'csrf_token') {
                    input.value = '';
                }
            });
            
            // Clear selections object
            for (const key in selections) {
                delete selections[key];
            }
            
            updateSelectionSummary();
        }
        
        // Form submission with confirmation
        document.getElementById('votingForm')?.addEventListener('submit', function(e) {
            if (Object.keys(selections).length !== totalPositions) {
                e.preventDefault();
                alert('Please select a candidate for each position before submitting.');
                return;
            }
            
            let confirmMessage = 'Are you sure you want to submit your vote?\n\nYour selections:\n';
            for (const [position, candidate] of Object.entries(selections)) {
                confirmMessage += `• ${position.replace('_', ' ').toUpperCase()}: ${candidate.name}\n`;
            }
            confirmMessage += '\nYou cannot change your vote after submission.';
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return;
            }
            
            // Disable submit button to prevent double submission
            const submitBtn = document.getElementById('submitVoteBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '🔄 Submitting...';
        });
        
        // Prevent accidental page refresh
        <?php if ($can_vote): ?>
        window.addEventListener('beforeunload', function(e) {
            if (Object.keys(selections).length > 0) {
                e.preventDefault();
                e.returnValue = 'You have unsaved selections. Are you sure you want to leave?';
                return e.returnValue;
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>