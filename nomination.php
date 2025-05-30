<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Get approved nominations from the database
    $stmt = $db->prepare("
        SELECT id, candidate_name, position, manifesto, photo, created_at
        FROM nominations 
        WHERE is_approved = 1
        ORDER BY 
            CASE position
                WHEN 'president' THEN 1
                WHEN 'vice_president' THEN 2
                WHEN 'secretary' THEN 3
                WHEN 'treasurer' THEN 4
                ELSE 5
            END,
            candidate_name ASC
    ");
    $stmt->execute();
    $nominations = $stmt->fetchAll();
    
    // Group nominations by position
    $grouped_nominations = [];
    foreach ($nominations as $nomination) {
        $grouped_nominations[$nomination['position']][] = $nomination;
    }
    
    // Get election statistics
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT position) as total_positions,
            COUNT(*) as total_candidates,
            (SELECT COUNT(DISTINCT user_id) FROM votes) as total_voters,
            (SELECT COUNT(*) FROM votes) as total_votes
        FROM nominations 
        WHERE is_approved = 1
    ");
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch();
    
    // Get election settings
    $settings_stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM election_settings 
        WHERE setting_key IN ('election_name', 'voting_enabled', 'election_start_date', 'election_end_date')
    ");
    $settings_stmt->execute();
    $settings_data = $settings_stmt->fetchAll();
    
    $settings = [];
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    
} catch (Exception $e) {
    error_log("Nomination page error: " . $e->getMessage());
    $grouped_nominations = [];
    $stats = ['total_positions' => 0, 'total_candidates' => 0, 'total_voters' => 0, 'total_votes' => 0];
    $settings = [];
}

// Position display names
$position_names = [
    'president' => 'President',
    'vice_president' => 'Vice President',
    'secretary' => 'Secretary',
    'treasurer' => 'Treasurer'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Candidates</title>
    <meta name="description" content="Meet the candidates running for student council positions">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Animated background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 80%, rgba(76, 175, 80, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(33, 150, 243, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(156, 39, 176, 0.1) 0%, transparent 50%);
            filter: blur(40px);
            animation: float 20s ease-in-out infinite;
            z-index: -1;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) scale(1); }
            33% { transform: translateY(-30px) scale(1.1); }
            66% { transform: translateY(-20px) scale(0.9); }
        }
        
        .header {
            background: rgba(17, 17, 17, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid #333;
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: #4CAF50;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .logo::before {
            content: '👥';
            font-size: 1.5rem;
        }
        
        nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        nav a {
            color: #fff;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        nav a:hover, nav a.active {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            transform: translateY(-2px);
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .page-header h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #4CAF50, #66bb6a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-header p {
            font-size: 1.2rem;
            color: #ccc;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 3rem;
            padding: 1.5rem;
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #4CAF50;
            display: block;
        }
        
        .stat-label {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .election-info {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .position-section {
            margin-bottom: 4rem;
        }
        
        .position-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #4CAF50;
        }
        
        .position-icon {
            font-size: 2rem;
            margin-right: 1rem;
        }
        
        .position-title {
            font-size: 2rem;
            color: #4CAF50;
        }
        
        .position-count {
            margin-left: auto;
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }
        
        .candidate-card {
            background: rgba(51, 51, 51, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .candidate-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #4CAF50, #66bb6a);
        }
        
        .candidate-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 30px rgba(76, 175, 80, 0.2);
            border-color: rgba(76, 175, 80, 0.3);
        }
        
        .candidate-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 1.5rem;
            display: block;
            border: 4px solid #4CAF50;
            transition: all 0.3s ease;
        }
        
        .candidate-card:hover .candidate-photo {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(76, 175, 80, 0.4);
        }
        
        .candidate-name {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 0.5rem;
            color: #fff;
        }
        
        .candidate-position {
            text-align: center;
            color: #4CAF50;
            font-weight: 600;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        
        .candidate-manifesto {
            color: #ccc;
            line-height: 1.6;
            text-align: justify;
            margin-bottom: 1.5rem;
        }
        
        .candidate-date {
            text-align: center;
            color: #999;
            font-size: 0.8rem;
            font-style: italic;
        }
        
        .no-candidates {
            text-align: center;
            padding: 3rem;
            color: #ccc;
        }
        
        .no-candidates-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .cta-section {
            text-align: center;
            margin-top: 4rem;
            padding: 3rem;
            background: rgba(76, 175, 80, 0.1);
            border-radius: 15px;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }
        
        .cta-section h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #4CAF50;
        }
        
        .cta-section p {
            font-size: 1.1rem;
            color: #ccc;
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 12px 24px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            margin: 0 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #45a049, #4CAF50);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .btn-outline {
            background: transparent;
            color: #4CAF50;
            border: 2px solid #4CAF50;
        }
        
        .btn-outline:hover {
            background: rgba(76, 175, 80, 0.1);
            transform: translateY(-3px);
        }
        
        .footer {
            background: rgba(17, 17, 17, 0.95);
            text-align: center;
            padding: 2rem;
            margin-top: 3rem;
            border-top: 1px solid #333;
        }
        
        .footer p {
            color: #ccc;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            nav ul {
                gap: 1rem;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-header h1 {
                font-size: 2.5rem;
            }
            
            .candidates-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .position-header {
                flex-direction: column;
                text-align: center;
            }
            
            .position-count {
                margin: 1rem 0 0 0;
            }
        }
        
        .loading {
            text-align: center;
            padding: 3rem;
            color: #ccc;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #333;
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="index.html" class="logo">UCES Candidates</a>
            <nav>
                <ul>
                    <li><a href="about.html">About</a></li>
                    <li><a href="nomination.php" class="active">Candidates</a></li>
                    <li><a href="results.html">Results</a></li>
                    <li><a href="faqs.html">FAQs</a></li>
                    <li><a href="login.php">Vote</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="main-content">
        <div class="page-header">
            <h1>Meet Your Candidates</h1>
            <p>Get to know the students running for council positions and their vision for our university's future.</p>
        </div>

        <?php if (!empty($stats)): ?>
        <div class="stats-bar">
            <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_positions']; ?></span>
                <span class="stat-label">Positions</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_candidates']; ?></span>
                <span class="stat-label">Candidates</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_voters']; ?></span>
                <span class="stat-label">Voters</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_votes']; ?></span>
                <span class="stat-label">Votes Cast</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($settings['election_name'])): ?>
        <div class="election-info">
            <h3><?php echo htmlspecialchars($settings['election_name']); ?></h3>
            <?php if (isset($settings['election_start_date']) && isset($settings['election_end_date'])): ?>
            <p>
                <strong>Voting Period:</strong> 
                <?php echo date('F j, Y \a\t g:i A', strtotime($settings['election_start_date'])); ?> - 
                <?php echo date('F j, Y \a\t g:i A', strtotime($settings['election_end_date'])); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($grouped_nominations)): ?>
            <?php foreach ($grouped_nominations as $position => $candidates): ?>
            <div class="position-section">
                <div class="position-header">
                    <div class="position-icon">
                        <?php 
                        $icons = [
                            'president' => '👑',
                            'vice_president' => '🎖️',
                            'secretary' => '📝',
                            'treasurer' => '💰'
                        ];
                        echo $icons[$position] ?? '🏛️';
                        ?>
                    </div>
                    <div class="position-title">
                        <?php echo $position_names[$position] ?? ucfirst(str_replace('_', ' ', $position)); ?>
                    </div>
                    <div class="position-count">
                        <?php echo count($candidates); ?> Candidate<?php echo count($candidates) !== 1 ? 's' : ''; ?>
                    </div>
                </div>

                <div class="candidates-grid">
                    <?php foreach ($candidates as $candidate): ?>
                    <div class="candidate-card">
                        <?php if ($candidate['photo']): ?>
                            <img src="data:image/jpeg;base64,<?php echo base64_encode($candidate['photo']); ?>" 
                                 alt="<?php echo htmlspecialchars($candidate['candidate_name']); ?>" 
                                 class="candidate-photo">
                        <?php else: ?>
                            <div class="candidate-photo" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #4CAF50, #66bb6a); color: white; font-size: 3rem; font-weight: bold;">
                                <?php echo strtoupper(substr($candidate['candidate_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="candidate-name">
                            <?php echo htmlspecialchars($candidate['candidate_name']); ?>
                        </div>
                        
                        <div class="candidate-position">
                            Running for <?php echo $position_names[$candidate['position']] ?? ucfirst(str_replace('_', ' ', $candidate['position'])); ?>
                        </div>
                        
                        <div class="candidate-manifesto">
                            <?php echo nl2br(htmlspecialchars($candidate['manifesto'])); ?>
                        </div>
                        
                        <div class="candidate-date">
                            Nominated on <?php echo date('F j, Y', strtotime($candidate['created_at'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="no-candidates">
            <div class="no-candidates-icon">🗳️</div>
            <h2>No Candidates Yet</h2>
            <p>Candidate nominations are still being processed. Check back soon to see who's running!</p>
        </div>
        <?php endif; ?>

        <div class="cta-section">
            <h2>Ready to Vote?</h2>
            <p>Make your voice heard in shaping the future of our university. Every vote counts in creating positive change.</p>
            
            <?php if (!empty($grouped_nominations)): ?>
            <a href="register.php" class="btn btn-primary">
                📝 Register to Vote
            </a>
            <a href="login.php" class="btn btn-outline">
                🗳️ Cast Your Vote
            </a>
            <?php else: ?>
            <a href="register.php" class="btn btn-primary">
                📝 Register Now
            </a>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Empowering democratic participation in higher education.</p>
    </footer>

    <script>
        // Add smooth scrolling for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scroll to position sections
            const positionLinks = document.querySelectorAll('a[href^="#"]');
            positionLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

            // Add loading animation for images
            const images = document.querySelectorAll('.candidate-photo');
            images.forEach(img => {
                if (img.tagName === 'IMG') {
                    img.addEventListener('load', function() {
                        this.style.opacity = '1';
                    });
                    img.style.opacity = '0';
                    img.style.transition = 'opacity 0.3s ease';
                }
            });

            // Add hover effect for candidate cards
            const cards = document.querySelectorAll('.candidate-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });

        // Auto-refresh page every 5 minutes to get latest candidates
        setTimeout(function() {
            location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>