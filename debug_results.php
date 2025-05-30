<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// This is a debug script to help test the results system
// Remove this file in production

echo "<h1>UCES Results Debug</h1>";
echo "<style>body{font-family:Arial;margin:20px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background-color:#f2f2f2;}</style>";

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>Database Connection: ✅ Success</h2>";
    
    // Check nominations table
    echo "<h2>Nominations Table</h2>";
    $stmt = $db->prepare("SELECT id, candidate_name, position, is_approved, created_at FROM nominations ORDER BY position, candidate_name");
    $stmt->execute();
    $nominations = $stmt->fetchAll();
    
    if (empty($nominations)) {
        echo "<p style='color:red;'>❌ No nominations found in database</p>";
        echo "<p>You need to add some candidates through the admin dashboard first.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Position</th><th>Approved</th><th>Created</th></tr>";
        foreach ($nominations as $nom) {
            $approved = $nom['is_approved'] ? '✅' : '❌';
            echo "<tr>";
            echo "<td>{$nom['id']}</td>";
            echo "<td>{$nom['candidate_name']}</td>";
            echo "<td>{$nom['position']}</td>";
            echo "<td>$approved</td>";
            echo "<td>{$nom['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check votes table
    echo "<h2>Votes Table</h2>";
    $stmt = $db->prepare("SELECT v.id, v.user_id, v.candidate_id, v.position, n.candidate_name, v.created_at FROM votes v LEFT JOIN nominations n ON v.candidate_id = n.id ORDER BY v.created_at DESC LIMIT 10");
    $stmt->execute();
    $votes = $stmt->fetchAll();
    
    if (empty($votes)) {
        echo "<p style='color:orange;'>⚠️ No votes found in database</p>";
        echo "<p>Votes will appear here after users start voting.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Vote ID</th><th>User ID</th><th>Candidate</th><th>Position</th><th>Date</th></tr>";
        foreach ($votes as $vote) {
            echo "<tr>";
            echo "<td>{$vote['id']}</td>";
            echo "<td>{$vote['user_id']}</td>";
            echo "<td>{$vote['candidate_name']}</td>";
            echo "<td>{$vote['position']}</td>";
            echo "<td>{$vote['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test the fetch_results.php endpoint
    echo "<h2>Testing fetch_results.php</h2>";
    $results_url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/fetch_results.php';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'UCES Debug Script'
        ]
    ]);
    
    $results_json = @file_get_contents($results_url, false, $context);
    
    if ($results_json === false) {
        echo "<p style='color:red;'>❌ Could not fetch results from: $results_url</p>";
        echo "<p>Check if the file exists and is accessible.</p>";
    } else {
        $results_data = json_decode($results_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "<p style='color:red;'>❌ Invalid JSON response from fetch_results.php</p>";
            echo "<pre>" . htmlspecialchars($results_json) . "</pre>";
        } else {
            echo "<p style='color:green;'>✅ fetch_results.php is working</p>";
            echo "<h3>Response Data:</h3>";
            echo "<pre>" . htmlspecialchars(json_encode($results_data, JSON_PRETTY_PRINT)) . "</pre>";
        }
    }
    
    // Check election settings
    echo "<h2>Election Settings</h2>";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM election_settings");
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    if (empty($settings)) {
        echo "<p style='color:orange;'>⚠️ No election settings found</p>";
        echo "<p>You may need to run the database setup again.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Setting</th><th>Value</th></tr>";
        foreach ($settings as $setting) {
            echo "<tr>";
            echo "<td>{$setting['setting_key']}</td>";
            echo "<td>{$setting['setting_value']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test results page access
    echo "<h2>Testing results.html</h2>";
    $results_page_url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/results.html';
    echo "<p><a href='$results_page_url' target='_blank'>Open Results Page</a></p>";
    
    echo "<h2>Recommendations</h2>";
    echo "<ul>";
    
    if (empty($nominations)) {
        echo "<li>❌ Add candidates through admin dashboard first</li>";
    } else {
        $approved_count = array_sum(array_column($nominations, 'is_approved'));
        if ($approved_count == 0) {
            echo "<li>❌ Approve some candidates in admin dashboard</li>";
        } else {
            echo "<li>✅ You have $approved_count approved candidates</li>";
        }
    }
    
    if (empty($votes)) {
        echo "<li>⚠️ Test voting by logging in as a student and casting votes</li>";
    } else {
        echo "<li>✅ You have " . count($votes) . " votes in the system</li>";
    }
    
    echo "<li>🔄 Make sure results.html auto-refresh is working (check browser console)</li>";
    echo "<li>🐛 Check browser console for JavaScript errors on results.html</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Quick Links:</strong></p>";
echo "<ul>";
echo "<li><a href='admin_login.php'>Admin Login</a></li>";
echo "<li><a href='login.php'>Student Login</a></li>";
echo "<li><a href='results.html'>Results Page</a></li>";
echo "<li><a href='nomination.php'>Candidates Page</a></li>";
echo "</ul>";

echo "<p><em>Delete this debug file (debug_results.php) before going to production!</em></p>";
?>