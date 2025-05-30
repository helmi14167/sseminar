<?php
/**
 * UCES Vote Integrity System
 * Implements blockchain-like security features for vote verification
 */

define('UCES_SYSTEM', true);
require_once 'config.php';

class VoteIntegrity {
    private $db;
    private static $instance = null;
    
    // Encryption settings
    const ENCRYPTION_METHOD = 'AES-256-CBC';
    const HASH_ALGORITHM = 'sha256';
    const SIGNATURE_ALGORITHM = 'sha256';
    
    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create a cryptographic hash of vote data (like a blockchain block)
     */
    public function createVoteHash($voteData, $previousHash = null) {
        $timestamp = time();
        $nonce = random_int(100000, 999999);
        
        // Create vote fingerprint without revealing actual vote
        $voteFingerprint = [
            'timestamp' => $timestamp,
            'user_hash' => hash(self::HASH_ALGORITHM, $voteData['user_id']),
            'position' => $voteData['position'],
            'candidate_hash' => hash(self::HASH_ALGORITHM, $voteData['candidate_id']),
            'previous_hash' => $previousHash,
            'nonce' => $nonce
        ];
        
        // Create merkle-like hash
        $dataString = json_encode($voteFingerprint, JSON_SORT_KEYS);
        $hash = hash(self::HASH_ALGORITHM, $dataString);
        
        return [
            'hash' => $hash,
            'fingerprint' => $voteFingerprint,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Generate a digital signature for the vote
     */
    public function signVote($voteData, $privateKey = null) {
        if ($privateKey === null) {
            $privateKey = $this->getSystemPrivateKey();
        }
        
        $dataToSign = json_encode($voteData, JSON_SORT_KEYS);
        $signature = hash_hmac(self::SIGNATURE_ALGORITHM, $dataToSign, $privateKey);
        
        return $signature;
    }
    
    /**
     * Verify a vote signature
     */
    public function verifyVoteSignature($voteData, $signature, $publicKey = null) {
        if ($publicKey === null) {
            $publicKey = $this->getSystemPrivateKey(); // In HMAC, same key for verify
        }
        
        $dataToSign = json_encode($voteData, JSON_SORT_KEYS);
        $expectedSignature = hash_hmac(self::SIGNATURE_ALGORITHM, $dataToSign, $publicKey);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Encrypt sensitive vote data
     */
    public function encryptVoteData($data) {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt(
            json_encode($data), 
            self::ENCRYPTION_METHOD, 
            $key, 
            OPENSSL_RAW_DATA, 
            $iv
        );
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt vote data
     */
    public function decryptVoteData($encryptedData) {
        $key = $this->getEncryptionKey();
        $data = base64_decode($encryptedData);
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt(
            $encrypted, 
            self::ENCRYPTION_METHOD, 
            $key, 
            OPENSSL_RAW_DATA, 
            $iv
        );
        
        return json_decode($decrypted, true);
    }
    
    /**
     * Create a tamper-evident vote record
     */
    public function createSecureVoteRecord($voteData) {
        try {
            $this->db->beginTransaction();
            
            // Get the last vote hash to chain votes together
            $lastHash = $this->getLastVoteHash();
            
            // Create vote hash and signature
            $voteHash = $this->createVoteHash($voteData, $lastHash);
            $signature = $this->signVote($voteData);
            
            // Encrypt sensitive data
            $encryptedData = $this->encryptVoteData([
                'user_id' => $voteData['user_id'],
                'candidate_id' => $voteData['candidate_id'],
                'ip_address' => $voteData['ip_address'],
                'user_agent' => $voteData['user_agent']
            ]);
            
            // Create verification record
            $verificationData = [
                'hash' => $voteHash['hash'],
                'signature' => $signature,
                'fingerprint' => json_encode($voteHash['fingerprint']),
                'encrypted_data' => $encryptedData,
                'timestamp' => $voteHash['timestamp']
            ];
            
            // Insert the actual vote
            $voteStmt = $this->db->prepare("
                INSERT INTO votes (user_id, candidate_id, position, created_at, ip_address, user_agent, vote_hash) 
                VALUES (?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?)
            ");
            $voteStmt->execute([
                $voteData['user_id'],
                $voteData['candidate_id'],
                $voteData['position'],
                $voteHash['timestamp'],
                $voteData['ip_address'],
                $voteData['user_agent'],
                $voteHash['hash']
            ]);
            
            $voteId = $this->db->lastInsertId();
            
            // Insert verification record
            $verifyStmt = $this->db->prepare("
                INSERT INTO vote_integrity (vote_id, hash_value, signature_value, fingerprint_data, encrypted_data, created_at) 
                VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))
            ");
            $verifyStmt->execute([
                $voteId,
                $voteHash['hash'],
                $signature,
                $verificationData['fingerprint'],
                $encryptedData,
                $voteHash['timestamp']
            ]);
            
            // Log the integrity event
            Security::logSecurityEvent('vote_integrity_created', [
                'vote_id' => $voteId,
                'hash' => $voteHash['hash'],
                'position' => $voteData['position']
            ], $voteData['user_id']);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'vote_id' => $voteId,
                'hash' => $voteHash['hash'],
                'verification_token' => $this->generateVerificationToken($voteId, $voteHash['hash'])
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Vote integrity error: " . $e->getMessage());
            throw new Exception("Failed to create secure vote record");
        }
    }
    
    /**
     * Verify the integrity of a vote
     */
    public function verifyVoteIntegrity($voteId) {
        try {
            // Get vote and integrity data
            $stmt = $this->db->prepare("
                SELECT v.*, vi.hash_value, vi.signature_value, vi.fingerprint_data, vi.encrypted_data
                FROM votes v
                JOIN vote_integrity vi ON v.id = vi.vote_id
                WHERE v.id = ?
            ");
            $stmt->execute([$voteId]);
            $record = $stmt->fetch();
            
            if (!$record) {
                return ['valid' => false, 'error' => 'Vote record not found'];
            }
            
            // Decrypt and verify data
            $decryptedData = $this->decryptVoteData($record['encrypted_data']);
            
            // Reconstruct vote data for verification
            $voteData = [
                'user_id' => $decryptedData['user_id'],
                'candidate_id' => $decryptedData['candidate_id'],
                'position' => $record['position'],
                'ip_address' => $decryptedData['ip_address'],
                'user_agent' => $decryptedData['user_agent']
            ];
            
            // Verify signature
            $signatureValid = $this->verifyVoteSignature($voteData, $record['signature_value']);
            
            // Verify hash chain
            $chainValid = $this->verifyHashChain($record['hash_value'], $record['fingerprint_data']);
            
            // Check for tampering
            $tamperCheck = $this->checkForTampering($voteId);
            
            return [
                'valid' => $signatureValid && $chainValid && !$tamperCheck['tampered'],
                'signature_valid' => $signatureValid,
                'chain_valid' => $chainValid,
                'tampering_detected' => $tamperCheck['tampered'],
                'verification_details' => $tamperCheck['details']
            ];
            
        } catch (Exception $e) {
            error_log("Vote verification error: " . $e->getMessage());
            return ['valid' => false, 'error' => 'Verification failed'];
        }
    }
    
    /**
     * Verify the hash chain integrity
     */
    private function verifyHashChain($currentHash, $fingerprintData) {
        $fingerprint = json_decode($fingerprintData, true);
        
        // Recreate hash from fingerprint
        $dataString = json_encode($fingerprint, JSON_SORT_KEYS);
        $recreatedHash = hash(self::HASH_ALGORITHM, $dataString);
        
        return hash_equals($currentHash, $recreatedHash);
    }
    
    /**
     * Check for evidence of tampering
     */
    private function checkForTampering($voteId) {
        $issues = [];
        
        // Check if vote was modified after creation
        $stmt = $this->db->prepare("
            SELECT v.created_at, vi.created_at as integrity_created,
                   v.vote_hash, vi.hash_value
            FROM votes v
            JOIN vote_integrity vi ON v.id = vi.vote_id
            WHERE v.id = ?
        ");
        $stmt->execute([$voteId]);
        $record = $stmt->fetch();
        
        if ($record) {
            // Check timestamp consistency
            if (abs(strtotime($record['created_at']) - strtotime($record['integrity_created'])) > 5) {
                $issues[] = 'Timestamp mismatch detected';
            }
            
            // Check hash consistency
            if (!hash_equals($record['vote_hash'], $record['hash_value'])) {
                $issues[] = 'Hash value mismatch';
            }
        }
        
        // Check for duplicate votes (double voting attempts)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM votes 
            WHERE user_id = (SELECT user_id FROM votes WHERE id = ?) 
            AND position = (SELECT position FROM votes WHERE id = ?)
        ");
        $stmt->execute([$voteId, $voteId]);
        $duplicateCount = $stmt->fetch()['count'];
        
        if ($duplicateCount > 1) {
            $issues[] = 'Multiple votes detected for same position';
        }
        
        return [
            'tampered' => !empty($issues),
            'details' => $issues
        ];
    }
    
    /**
     * Generate a verification token for voters
     */
    public function generateVerificationToken($voteId, $hash) {
        $data = $voteId . '|' . $hash . '|' . time();
        return base64_encode(hash_hmac('sha256', $data, $this->getSystemPrivateKey()));
    }
    
    /**
     * Verify a voter's verification token
     */
    public function verifyVerificationToken($token, $voteId) {
        try {
            $decoded = base64_decode($token);
            $stmt = $this->db->prepare("SELECT hash_value FROM vote_integrity WHERE vote_id = ?");
            $stmt->execute([$voteId]);
            $record = $stmt->fetch();
            
            if ($record) {
                // Reconstruct possible tokens (allow some time variance)
                for ($i = 0; $i < 300; $i++) { // 5 minutes variance
                    $testTime = time() - $i;
                    $testData = $voteId . '|' . $record['hash_value'] . '|' . $testTime;
                    $testToken = hash_hmac('sha256', $testData, $this->getSystemPrivateKey());
                    
                    if (hash_equals($decoded, $testToken)) {
                        return true;
                    }
                }
            }
            
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get comprehensive election integrity report
     */
    public function generateIntegrityReport() {
        try {
            $report = [
                'timestamp' => date('Y-m-d H:i:s'),
                'total_votes' => 0,
                'verified_votes' => 0,
                'tampered_votes' => 0,
                'chain_breaks' => 0,
                'position_summaries' => []
            ];
            
            // Get all votes
            $stmt = $this->db->prepare("
                SELECT v.id, v.position, v.created_at, vi.hash_value 
                FROM votes v 
                JOIN vote_integrity vi ON v.id = vi.vote_id 
                ORDER BY v.created_at
            ");
            $stmt->execute();
            $votes = $stmt->fetchAll();
            
            $report['total_votes'] = count($votes);
            $previousHash = null;
            
            foreach ($votes as $vote) {
                $verification = $this->verifyVoteIntegrity($vote['id']);
                
                if ($verification['valid']) {
                    $report['verified_votes']++;
                } else {
                    $report['tampered_votes']++;
                }
                
                // Track by position
                if (!isset($report['position_summaries'][$vote['position']])) {
                    $report['position_summaries'][$vote['position']] = [
                        'total' => 0,
                        'verified' => 0,
                        'tampered' => 0
                    ];
                }
                
                $report['position_summaries'][$vote['position']]['total']++;
                if ($verification['valid']) {
                    $report['position_summaries'][$vote['position']]['verified']++;
                } else {
                    $report['position_summaries'][$vote['position']]['tampered']++;
                }
                
                $previousHash = $vote['hash_value'];
            }
            
            $report['integrity_percentage'] = $report['total_votes'] > 0 ? 
                round(($report['verified_votes'] / $report['total_votes']) * 100, 2) : 0;
            
            return $report;
            
        } catch (Exception $e) {
            error_log("Integrity report error: " . $e->getMessage());
            return ['error' => 'Failed to generate integrity report'];
        }
    }
    
    /**
     * Get system private key (in production, store securely)
     */
    private function getSystemPrivateKey() {
        // In production, store this in environment variables or secure key management
        return hash('sha256', 'UCES_SYSTEM_KEY_' . DB_HOST . '_' . DB_NAME);
    }
    
    /**
     * Get encryption key
     */
    private function getEncryptionKey() {
        return hash('sha256', 'UCES_ENCRYPTION_' . $this->getSystemPrivateKey(), true);
    }
    
    /**
     * Get the last vote hash for chaining
     */
    private function getLastVoteHash() {
        $stmt = $this->db->prepare("
            SELECT hash_value 
            FROM vote_integrity 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['hash_value'] : null;
    }
}

// Database migration for vote integrity tables
function createVoteIntegrityTables() {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Add vote_hash column to existing votes table
        $db->exec("
            ALTER TABLE votes 
            ADD COLUMN vote_hash VARCHAR(64) NULL 
            AFTER user_agent
        ");
        
        // Create vote integrity table
        $db->exec("
            CREATE TABLE IF NOT EXISTS vote_integrity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vote_id INT NOT NULL,
                hash_value VARCHAR(64) NOT NULL,
                signature_value VARCHAR(64) NOT NULL,
                fingerprint_data TEXT NOT NULL,
                encrypted_data TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_vote_id (vote_id),
                INDEX idx_hash (hash_value),
                FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        // Create verification tokens table
        $db->exec("
            CREATE TABLE IF NOT EXISTS verification_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vote_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                INDEX idx_vote_id (vote_id),
                INDEX idx_token (token_hash),
                FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        echo "Vote integrity tables created successfully!\n";
        
    } catch (Exception $e) {
        echo "Error creating integrity tables: " . $e->getMessage() . "\n";
        
        // If column already exists, that's okay
        if (strpos($e->getMessage(), "Duplicate column name 'vote_hash'") !== false) {
            echo "Tables already exist - that's fine!\n";
        }
    }
}

// Run migration if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "<h1>UCES Vote Integrity System Setup</h1>";
    echo "<pre>";
    createVoteIntegrityTables();
    echo "</pre>";
    echo "<p>Setup complete! You can now use the enhanced vote integrity features.</p>";
}
?>