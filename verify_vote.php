<?php
define('UCES_SYSTEM', true);
require_once 'config.php';
require_once 'vote_integrity_system.php';

$verification_result = null;
$error_message = null;
$vote_id = null;
$verification_token = null;

// Handle verification request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid request. Please try again.";
    } else {
        $vote_id = (int)($_POST['vote_id'] ?? 0);
        $verification_token = Security::sanitizeInput($_POST['verification_token'] ?? '');
        
        if ($vote_id <= 0) {
            $error_message = "Please enter a valid vote ID.";
        } elseif (empty($verification_token)) {
            $error_message = "Please enter your verification token.";
        } else {
            try {
                $voteIntegrity = VoteIntegrity::getInstance();
                
                // First verify the token
                $tokenValid = $voteIntegrity->verifyVerificationToken($verification_token, $vote_id);
                
                if (!$tokenValid) {
                    $error_message = "Invalid verification token for this vote ID.";
                } else {
                    // Verify vote integrity
                    $verification_result = $voteIntegrity->verifyVoteIntegrity($vote_id);
                    
                    // Log verification attempt
                    Security::logSecurityEvent('vote_verification_attempted', [
                        'vote_id' => $vote_id,
                        'verification_result' => $verification_result['valid'] ? 'valid' : 'invalid'
                    ]);
                }
                
            } catch (Exception $e) {
                error_log("Vote verification error: " . $e->getMessage());
                $error_message = "An error occurred during verification. Please try again.";
            }
        }
    }
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Verification - <?php echo SITE_NAME; ?></title>
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
            font-size: 1.5rem;
            font-weight: bold;
            color: #4CAF50;
            text-decoration: none;
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
            max-width: 800px;
            margin: 0 auto;
            width: 100%;
        }
        
        .verify-container {
            background: rgba(51, 51, 51, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 12px;
            padding: 2.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 2rem;
        }
        
        .verify-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .verify-header h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #4CAF50;
        }
        
        .verify-header p {
            color: #ccc;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #fff;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #555;
            border-radius: 8px;
            background: rgba(34, 34, 34, 0.8);
            color: #fff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #45a049, #4CAF50);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
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
        
        .verification-result {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .result-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .result-icon {
            font-size: 3rem;
        }
        
        .result-title {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .result-valid {
            color: #4CAF50;
        }
        
        .result-invalid {
            color: #f44336;
        }
        
        .verification-details {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(34, 34, 34, 0.5);
            border-radius: 8px;
        }
        
        .detail-label {
            font-weight: 500;
        }
        
        .detail-status {
            font-weight: bold;
        }
        
        .status-valid {
            color: #4CAF50;
        }
        
        .status-invalid {
            color: #f44336;
        }
        
        .blockchain-info {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.3);
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .blockchain-info h3 {
            color: #2196F3;
            margin-bottom: 1rem;
        }
        
        .blockchain-info ul {
            list-style: none;
            padding: 0;
        }
        
        .blockchain-info li {
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(33, 150, 243, 0.2);
        }
        
        .blockchain-info li:last-child {
            border-bottom: none;
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
            .main-content {
                padding: 1rem;
            }
            
            .verify-container {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <a href="index.html" class="logo"><?php echo SITE_NAME; ?> - Vote Verification</a>
            <nav>
                <ul>
                    <li><a href="index.html">Home</a></li>
                    <li><a href="results.html">Results</a></li>
                    <li><a href="login.php">Login</a></li>
                </ul>
            </nav>
        </div>
    </div>

    <div class="main-content">
        <div class="verify-container">
            <div class="verify-header">
                <h1>🔒 Vote Verification</h1>
                <p>Verify the integrity and authenticity of your vote using blockchain-like cryptographic verification. Enter your vote ID and verification token below to confirm your vote was recorded correctly and hasn't been tampered with.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="vote_id">Vote ID</label>
                    <input type="number" 
                           id="vote_id" 
                           name="vote_id" 
                           placeholder="Enter your vote ID"
                           value="<?php echo htmlspecialchars($vote_id ?? ''); ?>"
                           required
                           min="1">
                </div>

                <div class="form-group">
                    <label for="verification_token">Verification Token</label>
                    <input type="text" 
                           id="verification_token" 
                           name="verification_token" 
                           placeholder="Enter your verification token"
                           value="<?php echo htmlspecialchars($verification_token ?? ''); ?>"
                           required>
                </div>

                <button type="submit" class="btn">🔍 Verify Vote</button>
            </form>
        </div>

        <?php if ($verification_result): ?>
        <div class="verification-result">
            <div class="result-header">
                <div class="result-icon">
                    <?php echo $verification_result['valid'] ? '✅' : '❌'; ?>
                </div>
                <div>
                    <div class="result-title <?php echo $verification_result['valid'] ? 'result-valid' : 'result-invalid'; ?>">
                        <?php echo $verification_result['valid'] ? 'Vote Verified Successfully' : 'Vote Verification Failed'; ?>
                    </div>
                    <div style="color: #ccc; font-size: 0.9rem;">
                        Vote ID: <?php echo htmlspecialchars($vote_id); ?>
                    </div>
                </div>
            </div>

            <div class="verification-details">
                <div class="detail-item">
                    <span class="detail-label">🔐 Digital Signature</span>
                    <span class="detail-status <?php echo $verification_result['signature_valid'] ? 'status-valid' : 'status-invalid'; ?>">
                        <?php echo $verification_result['signature_valid'] ? 'VALID' : 'INVALID'; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">🔗 Hash Chain</span>
                    <span class="detail-status <?php echo $verification_result['chain_valid'] ? 'status-valid' : 'status-invalid'; ?>">
                        <?php echo $verification_result['chain_valid'] ? 'INTACT' : 'BROKEN'; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">🛡️ Tampering Check</span>
                    <span class="detail-status <?php echo !$verification_result['tampering_detected'] ? 'status-valid' : 'status-invalid'; ?>">
                        <?php echo !$verification_result['tampering_detected'] ? 'NO TAMPERING' : 'TAMPERING DETECTED'; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">⭐ Overall Status</span>
                    <span class="detail-status <?php echo $verification_result['valid'] ? 'status-valid' : 'status-invalid'; ?>">
                        <?php echo $verification_result['valid'] ? 'VERIFIED' : 'FAILED'; ?>
                    </span>
                </div>
            </div>

            <?php if (isset($verification_result['verification_details']) && !empty($verification_result['verification_details'])): ?>
            <div class="alert alert-warning" style="margin-top: 1rem;">
                <strong>⚠️ Issues Detected:</strong>
                <ul style="margin-top: 0.5rem; padding-left: 1rem;">
                    <?php foreach ($verification_result['verification_details'] as $issue): ?>
                        <li><?php echo htmlspecialchars($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($verification_result['valid']): ?>
            <div class="alert alert-success" style="margin-top: 1rem;">
                <strong>✅ Your vote has been cryptographically verified!</strong> This confirms that:
                <ul style="margin-top: 0.5rem; padding-left: 1rem;">
                    <li>Your vote was recorded exactly as you cast it</li>
                    <li>The vote has not been altered or tampered with</li>
                    <li>The cryptographic chain of custody is intact</li>
                    <li>Your vote will be counted in the final results</li>
                </ul>
            </div>
            <?php else: ?>
            <div class="alert alert-error" style="margin-top: 1rem;">
                <strong>❌ Vote verification failed!</strong> This could indicate:
                <ul style="margin-top: 0.5rem; padding-left: 1rem;">
                    <li>The vote may have been tampered with</li>
                    <li>There was an error in the voting process</li>
                    <li>The verification token is incorrect</li>
                    <li>Please contact the election administrator immediately</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="blockchain-info">
            <h3>🔗 How Blockchain-like Verification Works</h3>
            <ul>
                <li><strong>Cryptographic Hashing:</strong> Each vote is protected by a unique cryptographic hash</li>
                <li><strong>Digital Signatures:</strong> Votes are digitally signed to prevent forgery</li>
                <li><strong>Chain of Custody:</strong> Votes are linked together in a tamper-evident chain</li>
                <li><strong>Encryption:</strong> Sensitive data is encrypted using military-grade encryption</li>
                <li><strong>Immutable Records:</strong> Once recorded, votes cannot be altered without detection</li>
                <li><strong>Independent Verification:</strong> Anyone can verify their vote using their token</li>
            </ul>
        </div>
    </div>

    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p>Your vote is protected by advanced cryptographic security measures.</p>
    </div>

    <script>
        // Auto-format verification token input
        document.getElementById('verification_token').addEventListener('input', function(e) {
            // Remove any non-alphanumeric characters for cleaner input
            this.value = this.value.replace(/[^a-zA-Z0-9]/g, '');
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const voteId = document.getElementById('vote_id').value;
            const token = document.getElementById('verification_token').value;
            
            if (!voteId || voteId <= 0) {
                e.preventDefault();
                alert('Please enter a valid vote ID.');
                return;
            }
            
            if (!token || token.length < 10) {
                e.preventDefault();
                alert('Please enter a valid verification token.');
                return;
            }
        });
    </script>
</body>
</html>