<?php
/**
 * UCES System Diagnostic and Fix Tool
 * Use this to diagnose and fix common system issues
 */

define('UCES_SYSTEM', true);

// First, let's check if config.php exists and is accessible
if (!file_exists('config.php')) {
    die('<h1>❌ Error: config.php not found!</h1><p>Please ensure config.php is in the same directory as this file.</p>');
}

require_once 'config.php';

echo "<h1>🔧 UCES System Diagnostic Tool</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
    .success { color: #4CAF50; font-weight: bold; }
    .error { color: #f44336; font-weight: bold; }
    .warning { color: #ff9800; font-weight: bold; }
    .info { color: #2196F3; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .fix-button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
    .fix-button:hover { background: #45a049; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
</style>";

echo "<div class='container'>";

// Handle fix actions
if (isset($_POST['fix_action'])) {
    handleFixAction($_POST['fix_action']);
}

$issues = [];
$fixes = [];

echo "<h2>🔍 System Diagnosis</h2>";

// 1. Check Database Connection
echo "<h3>1. Database Connection</h3>";
try {
    $db = Database::getInstance()->getConnection();
    echo "<p class='success'>✅ Database connection successful</p>";
    
    // Test database operations
    $stmt = $db->query("SELECT 1");
    if ($stmt) {
        echo "<p class='success'>✅ Database operations working</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    $issues[] = "database_connection";
    $fixes[] = "Fix database connection";
}

// 2. Check Required Tables
echo "<h3>2. Database Tables</h3>";
try {
    $required_tables = ['users', 'admins', 'nominations', 'votes', 'election_settings', 'audit_logs'];
    $existing_tables = [];
    
    $stmt = $db->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $existing_tables[] = $row[0];
    }
    
    echo "<table>";
    echo "<tr><th>Table Name</th><th>Status</th><th>Action</th></tr>";
    
    foreach ($required_tables as $table) {
        if (in_array($table, $existing_tables)) {
            echo "<tr><td>$table</td><td class='success'>✅ Exists</td><td>-</td></tr>";
        } else {
            echo "<tr><td>$table</td><td class='error'>❌ Missing</td><td>Needs Creation</td></tr>";
            $issues[] = "missing_table_$table";
        }
    }
    echo "</table>";
    
    // Check for integrity tables
    $integrity_tables = ['vote_integrity', 'verification_tokens'];
    echo "<h4>Blockchain Security Tables:</h4>";
    echo "<table>";
    echo "<tr><th>Table Name</th><th>Status</th><th>Action</th></tr>";
    
    foreach ($integrity_tables as $table) {
        if (in_array($table, $existing_tables)) {
            echo "<tr><td>$table</td><td class='success'>✅ Exists</td><td>-</td></tr>";
        } else {
            echo "<tr><td>$table</td><td class='warning'>⚠️ Missing</td><td>Optional - Enhances Security</td></tr>";
            $fixes[] = "create_integrity_tables";
        }
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Cannot check tables: " . htmlspecialchars($e->getMessage()) . "</p>";
    $issues[] = "table_check_failed";
}

// 3. Check Election Settings
echo "<h3>3. Election Settings</h3>";
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM election_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (empty($settings)) {
        echo "<p class='error'>❌ No election settings found - this is likely the main issue!</p>";
        $issues[] = "missing_election_settings";
        $fixes[] = "create_default_settings";
    } else {
        echo "<table>";
        echo "<tr><th>Setting</th><th>Current Value</th><th>Status</th></tr>";
        
        $important_settings = [
            'voting_enabled' => '1',
            'election_name' => 'University Council Elections',
            'results_public' => '1'
        ];
        
        foreach ($important_settings as $key => $recommended) {
            $current = $settings[$key] ?? 'NOT SET';
            $status = ($current === 'NOT SET') ? 'error' : 'success';
            $status_text = ($current === 'NOT SET') ? '❌ Missing' : '✅ Set';
            
            echo "<tr><td>$key</td><td>$current</td><td class='$status'>$status_text</td></tr>";
            
            if ($current === 'NOT SET') {
                $issues[] = "missing_setting_$key";
            }
        }
        echo "</table>";
        
        // Check voting status specifically
        $voting_enabled = $settings['voting_enabled'] ?? '0';
        if ($voting_enabled !== '1') {
            echo "<p class='warning'>⚠️ Voting is currently disabled (voting_enabled = '$voting_enabled')</p>";
            $fixes[] = "enable_voting";
        } else {
            echo "<p class='success'>✅ Voting is enabled</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Cannot check election settings: " . htmlspecialchars($e->getMessage()) . "</p>";
    $issues[] = "settings_check_failed";
}

// 4. Check Admin Account
echo "<h3>4. Admin Account</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM admins WHERE is_active = 1");
    $admin_count = $stmt->fetch()['count'];
    
    if ($admin_count == 0) {
        echo "<p class='error'>❌ No active admin accounts found</p>";
        $issues[] = "no_admin_accounts";
        $fixes[] = "create_admin_account";
    } else {
        echo "<p class='success'>✅ Found $admin_count active admin account(s)</p>";
        
        // Show admin accounts
        $stmt = $db->query("SELECT username, email, role, created_at FROM admins WHERE is_active = 1");
        $admins = $stmt->fetchAll();
        
        echo "<table>";
        echo "<tr><th>Username</th><th>Email</th><th>Role</th><th>Created</th></tr>";
        foreach ($admins as $admin) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['role']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Cannot check admin accounts: " . htmlspecialchars($e->getMessage()) . "</p>";
    $issues[] = "admin_check_failed";
}

// 5. Check File Permissions
echo "<h3>5. File Permissions</h3>";
$critical_files = [
    'config.php' => 'readable',
    'uploads/' => 'writable',
    'logs/' => 'writable'
];

foreach ($critical_files as $file => $requirement) {
    if (file_exists($file)) {
        if ($requirement === 'readable' && is_readable($file)) {
            echo "<p class='success'>✅ $file is readable</p>";
        } elseif ($requirement === 'writable' && is_writable($file)) {
            echo "<p class='success'>✅ $file is writable</p>";
        } else {
            echo "<p class='error'>❌ $file is not $requirement</p>";
            $issues[] = "permission_$file";
        }
    } else {
        if ($file === 'uploads/' || $file === 'logs/') {
            echo "<p class='warning'>⚠️ $file directory doesn't exist</p>";
            $fixes[] = "create_directory_$file";
        } else {
            echo "<p class='error'>❌ $file doesn't exist</p>";
            $issues[] = "missing_file_$file";
        }
    }
}

// 6. Summary and Fixes
echo "<h2>📋 Summary</h2>";

if (empty($issues)) {
    echo "<p class='success'>🎉 All checks passed! Your system should be working correctly.</p>";
    echo "<p>If you're still seeing issues, try:</p>";
    echo "<ul>";
    echo "<li>Clear your browser cache</li>";
    echo "<li>Check if voting is enabled in admin dashboard</li>";
    echo "<li>Verify election dates are current</li>";
    echo "</ul>";
} else {
    echo "<p class='error'>❌ Found " . count($issues) . " issue(s) that need to be fixed:</p>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li>" . htmlspecialchars($issue) . "</li>";
    }
    echo "</ul>";
    
    echo "<h3>🔧 Quick Fixes</h3>";
    echo "<form method='POST'>";
    
    if (in_array('missing_election_settings', $issues) || in_array('missing_setting_voting_enabled', $issues)) {
        echo "<button type='submit' name='fix_action' value='create_settings' class='fix-button'>🔧 Create Default Election Settings</button>";
    }
    
    if (in_array('no_admin_accounts', $issues)) {
        echo "<button type='submit' name='fix_action' value='create_admin' class='fix-button'>👨‍💼 Create Admin Account</button>";
    }
    
    if (in_array('create_integrity_tables', $fixes)) {
        echo "<button type='submit' name='fix_action' value='create_integrity' class='fix-button'>🔐 Create Security Tables</button>";
    }
    
    if (strpos(implode('', $fixes), 'enable_voting') !== false) {
        echo "<button type='submit' name='fix_action' value='enable_voting' class='fix-button'>✅ Enable Voting</button>";
    }
    
    echo "<button type='submit' name='fix_action' value='create_directories' class='fix-button'>📁 Create Missing Directories</button>";
    echo "<button type='submit' name='fix_action' value='fix_all' class='fix-button' style='background: #ff9800;'>🚀 Fix All Issues</button>";
    echo "</form>";
}

echo "<h3>📖 Manual Fixes</h3>";
echo "<details>";
echo "<summary>Click to see manual fix instructions</summary>";
echo "<h4>1. Enable Voting Manually:</h4>";
echo "<pre>UPDATE election_settings SET setting_value = '1' WHERE setting_key = 'voting_enabled';</pre>";
echo "<h4>2. Create Admin Account Manually:</h4>";
echo "<pre>INSERT INTO admins (username, password, email, role, is_active) 
VALUES ('admin', '\$2y\$12\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@university.edu', 'super_admin', 1);</pre>";
echo "<p><strong>Default Password:</strong> admin123 (change immediately!)</p>";
echo "</details>";

echo "</div>";

function handleFixAction($action) {
    global $db;
    
    try {
        switch ($action) {
            case 'create_settings':
                createDefaultSettings($db);
                break;
            case 'create_admin':
                createAdminAccount($db);
                break;
            case 'create_integrity':
                createIntegrityTables($db);
                break;
            case 'enable_voting':
                enableVoting($db);
                break;
            case 'create_directories':
                createDirectories();
                break;
            case 'fix_all':
                createDefaultSettings($db);
                createAdminAccount($db);
                createIntegrityTables($db);
                enableVoting($db);
                createDirectories();
                break;
        }
        echo "<div style='background: #4CAF50; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;'>✅ Fix applied successfully! Refresh the page to see changes.</div>";
    } catch (Exception $e) {
        echo "<div style='background: #f44336; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;'>❌ Fix failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function createDefaultSettings($db) {
    $default_settings = [
        'election_name' => 'University Council Elections 2024',
        'election_start_date' => date('Y-m-d 09:00:00', strtotime('-1 day')),
        'election_end_date' => date('Y-m-d 20:00:00', strtotime('+7 days')),
        'voting_enabled' => '1',
        'registration_enabled' => '1',
        'nomination_enabled' => '1',
        'results_public' => '1',
        'max_failed_login_attempts' => '5',
        'account_lockout_duration' => '30',
        'session_timeout' => '3600',
        'require_email_verification' => '0'
    ];
    
    foreach ($default_settings as $key => $value) {
        $stmt = $db->prepare("INSERT INTO election_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
    
    echo "<p class='success'>✅ Default election settings created</p>";
}

function createAdminAccount($db) {
    // Check if admin already exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = 'admin'");
    $stmt->execute();
    
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admins (username, password, email, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', $hashedPassword, 'admin@university.edu', 'System Administrator', 'super_admin', 1]);
        echo "<p class='success'>✅ Admin account created (username: admin, password: admin123)</p>";
        echo "<p class='warning'>⚠️ Change the default password immediately!</p>";
    } else {
        echo "<p class='info'>ℹ️ Admin account already exists</p>";
    }
}

function createIntegrityTables($db) {
    try {
        // Add vote_hash column if not exists
        $db->exec("ALTER TABLE votes ADD COLUMN vote_hash VARCHAR(64) NULL AFTER user_agent");
    } catch (Exception $e) {
        // Column might already exist
    }
    
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
    
    echo "<p class='success'>✅ Blockchain security tables created</p>";
}

function enableVoting($db) {
    $stmt = $db->prepare("UPDATE election_settings SET setting_value = '1' WHERE setting_key = 'voting_enabled'");
    $stmt->execute();
    echo "<p class='success'>✅ Voting enabled</p>";
}

function createDirectories() {
    $dirs = ['uploads', 'logs'];
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
            echo "<p class='success'>✅ Created directory: $dir</p>";
        }
    }
}
?>