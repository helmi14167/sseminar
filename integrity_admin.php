<?php
define('UCES_SYSTEM', true);
require_once 'config.php';
require_once 'vote_integrity_system.php';

// Require admin authentication
Auth::requireAdminLogin();

$integrity_report = null;
$error_message = null;

try {
    $voteIntegrity = VoteIntegrity::getInstance();
    $integrity_report = $voteIntegrity->generateIntegrityReport();
} catch (Exception $e) {
    $error_message = "Failed to generate integrity report: " . $e->getMessage();
    error_log("Integrity admin error: " . $e->getMessage());
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Integrity Dashboard - <?php echo SITE_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
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
            color: #ff9800;
            font-size: 1.5rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary { background: #ff9800; color: white; }
        .btn-primary:hover { background: #f57c00; transform: translateY(-1px); }
        
        .btn-secondary { background: #666; color: white; }
        .btn-secondary:hover { background: #777; }
        
        .main-content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .overview-card {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .overview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ff9800, #f57c00);
        }
        
        .card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .card-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ff9800;
            margin-bottom: 0.5rem;
        }
        
        .card-label {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .card-percentage {
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 0.5rem;
        }
        
        .percentage-good { color: #4CAF50; }
        .percentage-warning { color: #ff9800; }
        .percentage-bad { color: #f44336; }
        
        .section {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .section h2 {
            color: #ff9800;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .position-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .position-card {
            background: rgba(68, 68, 68, 0.6);
            border-radius: 10px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .position-title {
            font-size: 1.2rem;
            color: #ff9800;
            margin-bottom: 1rem;
            text-transform: capitalize;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .position-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            text-align: center;
        }
        
        .stat-item {
            padding: 0.75rem;
            border-radius: 8px;
            background: rgba(34, 34, 34, 0.8);
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #ff9800;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #ccc;
            margin-top: 0.25rem;
        }
        
        .integrity-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .status-excellent {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }
        
        .status-good {
            background: rgba(255, 152, 0, 0.2);
            color: #ff9800;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }
        
        .status-poor {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid rgba(244, 67, 54, 0.3);
            color: #ff6b6b;
        }
        
        .blockchain-features {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.3);
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .blockchain-features h3 {
            color: #2196F3;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(33, 150, 243, 0.1);
            border-radius: 8px;
        }
        
        .feature-icon {
            font-size: 1.5rem;
            color: #2196F3;
        }
        
        .feature-text {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .timestamp-info {
            text-align: center;
            color: #999;
            font-size: 0.9rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #444;
        }
        
        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .overview-grid { grid-template-columns: repeat(2, 1fr); }
            .position-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="header">
        <h1>🔒 Vote Integrity Dashboard</h1>
        <div class="header-actions">
            <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Admin</a>
            <a href="verify_vote.php" class="btn btn-primary">Verify Vote</a>
        </div>
    </div>

    <div class="main-content">
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($integrity_report && !isset($integrity_report['error'])): ?>
        
        <!-- Overall Integrity Overview -->
        <div class="overview-grid">
            <div class="overview-card">
                <div class="card-icon">🗳️</div>
                <div class="card-number"><?php echo number_format($integrity_report['total_votes']); ?></div>
                <div class="card-label">Total Votes</div>
            </div>
            
            <div class="overview-card">
                <div class="card-icon">✅</div>
                <div class="card-number"><?php echo number_format($integrity_report['verified_votes']); ?></div>
                <div class="card-label">Verified Votes</div>
            </div>
            
            <div class="overview-card">
                <div class="card-icon">⚠️</div>
                <div class="card-number"><?php echo number_format($integrity_report['tampered_votes']); ?></div>
                <div class="card-label">Tampered Votes</div>
            </div>
            
            <div class="overview-card">
                <div class="card-icon">🛡️</div>
                <div class="card-number"><?php echo $integrity_report['integrity_percentage']; ?>%</div>
                <div class="card-label">Integrity Score</div>
                <div class="card-percentage <?php 
                    if ($integrity_report['integrity_percentage'] >= 95) echo 'percentage-good';
                    elseif ($integrity_report['integrity_percentage'] >= 90) echo 'percentage-warning';
                    else echo 'percentage-bad';
                ?>">
                    <?php 
                    if ($integrity_report['integrity_percentage'] >= 95) echo 'EXCELLENT';
                    elseif ($integrity_report['integrity_percentage'] >= 90) echo 'GOOD';
                    else echo 'NEEDS ATTENTION';
                    ?>
                </div>
            </div>
        </div>

        <!-- Overall Status -->
        <div class="section">
            <h2>🎯 Overall Election Integrity Status</h2>
            <div style="text-align: center; margin: 2rem 0;">
                <?php 
                $integrity_percentage = $integrity_report['integrity_percentage'];
                if ($integrity_percentage >= 95) {
                    echo '<div class="integrity-status status-excellent">🏆 EXCELLENT - Election integrity is outstanding</div>';
                } elseif ($integrity_percentage >= 90) {
                    echo '<div class="integrity-status status-good">✅ GOOD - Election integrity is satisfactory</div>';
                } else {
                    echo '<div class="integrity-status status-poor">⚠️ NEEDS ATTENTION - Integrity issues detected</div>';
                }
                ?>
            </div>
            
            <?php if ($integrity_report['tampered_votes'] > 0): ?>
            <div class="alert alert-error">
                <strong>⚠️ Security Alert:</strong> <?php echo $integrity_report['tampered_votes']; ?> vote(s) have failed integrity verification. 
                This could indicate tampering attempts or technical issues that require immediate investigation.
            </div>
            <?php endif; ?>
        </div>

        <!-- Position-wise Breakdown -->
        <div class="section">
            <h2>📊 Position-wise Integrity Analysis</h2>
            <div class="position-grid">
                <?php foreach ($integrity_report['position_summaries'] as $position => $stats): ?>
                <div class="position-card">
                    <div class="position-title">
                        <?php 
                        $icons = ['president' => '👑', 'vice_president' => '🎖️', 'secretary' => '📝', 'treasurer' => '💰'];
                        echo ($icons[$position] ?? '🏛️') . ' ' . ucfirst(str_replace('_', ' ', $position));
                        ?>
                    </div>
                    <div class="position-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Votes</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['verified']; ?></div>
                            <div class="stat-label">Verified</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['tampered']; ?></div>
                            <div class="stat-label">Issues</div>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 1rem;">
                        <?php 
                        $position_percentage = $stats['total'] > 0 ? round(($stats['verified'] / $stats['total']) * 100, 1) : 0;
                        if ($position_percentage >= 95) {
                            echo '<span class="integrity-status status-excellent">🏆 ' . $position_percentage . '%</span>';
                        } elseif ($position_percentage >= 90) {
                            echo '<span class="integrity-status status-good">✅ ' . $position_percentage . '%</span>';
                        } else {
                            echo '<span class="integrity-status status-poor">⚠️ ' . $position_percentage . '%</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Blockchain-like Security Features -->
        <div class="blockchain-features">
            <h3>🔗 Active Security Features</h3>
            <div class="features-grid">
                <div class="feature-item">
                    <div class="feature-icon">🔐</div>
                    <div class="feature-text">
                        <strong>Cryptographic Hashing</strong><br>
                        SHA-256 hashing for vote integrity
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">✍️</div>
                    <div class="feature-text">
                        <strong>Digital Signatures</strong><br>
                        HMAC signatures prevent forgery
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🔗</div>
                    <div class="feature-text">
                        <strong>Hash Chaining</strong><br>
                        Blockchain-like vote linking
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🔒</div>
                    <div class="feature-text">
                        <strong>AES-256 Encryption</strong><br>
                        Military-grade vote encryption
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🕵️</div>
                    <div class="feature-text">
                        <strong>Tamper Detection</strong><br>
                        Real-time integrity monitoring
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🎫</div>
                    <div class="feature-text">
                        <strong>Verification Tokens</strong><br>
                        Voter-verifiable receipts
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Recommendations -->
        <div class="section">
            <h2>🛡️ Security Recommendations</h2>
            <div style="display: grid; gap: 1rem;">
                <?php if ($integrity_report['integrity_percentage'] >= 98): ?>
                    <div class="alert" style="background: rgba(76, 175, 80, 0.1); border: 1px solid rgba(76, 175, 80, 0.3); color: #4CAF50;">
                        <strong>🎉 Outstanding Security!</strong> Your election system is operating at peak security levels. All votes are properly verified and the integrity chain is intact.
                    </div>
                <?php elseif ($integrity_report['integrity_percentage'] >= 95): ?>
                    <div class="alert" style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); color: #ffc107;">
                        <strong>✅ Good Security</strong> Your system is secure with minor areas for improvement. Continue monitoring for optimal integrity.
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        <strong>⚠️ Action Required</strong> Integrity issues detected. Please investigate immediately:
                        <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                            <li>Review tampered votes for patterns</li>
                            <li>Check system logs for suspicious activity</li>
                            <li>Verify server security and access controls</li>
                            <li>Consider pausing voting if critical issues found</li>
                        </ul>
                    </div>
                <?php endif; ?>

                <div style="background: rgba(33, 150, 243, 0.1); border: 1px solid rgba(33, 150, 243, 0.3); padding: 1rem; border-radius: 8px; color: #2196F3;">
                    <strong>💡 Best Practices:</strong>
                    <ul style="margin-top: 0.5rem; padding-left: 1.5rem; color: #ccc;">
                        <li>Regularly monitor this integrity dashboard</li>
                        <li>Investigate any failed verifications immediately</li>
                        <li>Keep verification tokens secure and private</li>
                        <li>Maintain regular backups of integrity data</li>
                        <li>Consider blockchain auditing for high-stakes elections</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="timestamp-info">
            <p><strong>Report Generated:</strong> <?php echo $integrity_report['timestamp']; ?></p>
            <p><em>This integrity report uses blockchain-inspired cryptographic verification methods</em></p>
        </div>

        <?php elseif ($integrity_report && isset($integrity_report['error'])): ?>
        <div class="alert alert-error">
            <strong>Error:</strong> <?php echo htmlspecialchars($integrity_report['error']); ?>
        </div>
        <?php else: ?>
        <div class="section">
            <h2>⚠️ No Data Available</h2>
            <p>No votes have been cast yet, or the integrity system needs to be initialized.</p>
            <p style="margin-top: 1rem;">
                <a href="vote_integrity_system.php" class="btn btn-primary">Initialize Integrity System</a>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);

        // Add some visual enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Animate the integrity percentage
            const percentageElements = document.querySelectorAll('.card-number');
            percentageElements.forEach(el => {
                const finalValue = parseInt(el.textContent);
                if (!isNaN(finalValue)) {
                    let currentValue = 0;
                    const increment = finalValue / 50;
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            clearInterval(timer);
                            currentValue = finalValue;
                        }
                        el.textContent = Math.floor(currentValue).toLocaleString();
                    }, 20);
                }
            });

            // Add hover effects to cards
            document.querySelectorAll('.overview-card, .position-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 10px 25px rgba(255, 152, 0, 0.2)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
        });
    </script>
</body>
</html>