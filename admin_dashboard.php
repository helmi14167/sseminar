<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Require admin authentication
Auth::requireAdminLogin();

// Initialize variables
$errors = [];
$success_messages = [];

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please try again.";
        Security::logSecurityEvent('csrf_token_invalid', ['page' => 'admin_dashboard'], null, $_SESSION['admin_id']);
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Handle candidate operations
            if (isset($_POST['candidate_action'])) {
                $action = $_POST['candidate_action'];
                
                if ($action === 'add' || $action === 'edit') {
                    $candidate_name = Security::sanitizeInput($_POST['candidate_name'] ?? '');
                    $position = Security::sanitizeInput($_POST['position'] ?? '');
                    $manifesto = Security::sanitizeInput($_POST['manifesto'] ?? '');
                    $candidate_id = isset($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : null;
                    
                    // Validation
                    if (empty($candidate_name)) {
                        $errors[] = "Candidate name is required.";
                    }
                    if (empty($position)) {
                        $errors[] = "Position is required.";
                    }
                    if (empty($manifesto)) {
                        $errors[] = "Manifesto is required.";
                    }
                    
                    // Handle photo upload
                    $photo = null;
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        if (Validator::validateImageFile($_FILES['photo'])) {
                            $photo = file_get_contents($_FILES['photo']['tmp_name']);
                        } else {
                            $errors[] = "Invalid photo file. Please upload a JPG, PNG, or GIF image under 5MB.";
                        }
                    } elseif ($action === 'add') {
                        $errors[] = "Photo is required for new candidates.";
                    }
                    
                    if (empty($errors)) {
                        if ($action === 'add') {
                            $stmt = $db->prepare("
                                INSERT INTO nominations (candidate_name, position, manifesto, photo, is_approved, approved_by, approved_at) 
                                VALUES (?, ?, ?, ?, 1, ?, NOW())
                            ");
                            $stmt->execute([$candidate_name, $position, $manifesto, $photo, $_SESSION['admin_id']]);
                            $success_messages[] = "Candidate added successfully.";
                            
                            Security::logSecurityEvent('candidate_added', [
                                'candidate_name' => $candidate_name,
                                'position' => $position
                            ], null, $_SESSION['admin_id']);
                        } else {
                            if ($photo) {
                                $stmt = $db->prepare("
                                    UPDATE nominations 
                                    SET candidate_name = ?, position = ?, manifesto = ?, photo = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$candidate_name, $position, $manifesto, $photo, $candidate_id]);
                            } else {
                                $stmt = $db->prepare("
                                    UPDATE nominations 
                                    SET candidate_name = ?, position = ?, manifesto = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$candidate_name, $position, $manifesto, $candidate_id]);
                            }
                            $success_messages[] = "Candidate updated successfully.";
                            
                            Security::logSecurityEvent('candidate_updated', [
                                'candidate_id' => $candidate_id,
                                'candidate_name' => $candidate_name
                            ], null, $_SESSION['admin_id']);
                        }
                    }
                } elseif ($action === 'delete') {
                    $candidate_id = (int)$_POST['candidate_id'];
                    
                    // Get candidate info before deletion
                    $stmt = $db->prepare("SELECT candidate_name FROM nominations WHERE id = ?");
                    $stmt->execute([$candidate_id]);
                    $candidate = $stmt->fetch();
                    
                    if ($candidate) {
                        // Delete votes first
                        $stmt = $db->prepare("DELETE FROM votes WHERE candidate_id = ?");
                        $stmt->execute([$candidate_id]);
                        
                        // Delete candidate
                        $stmt = $db->prepare("DELETE FROM nominations WHERE id = ?");
                        $stmt->execute([$candidate_id]);
                        
                        $success_messages[] = "Candidate deleted successfully.";
                        
                        Security::logSecurityEvent('candidate_deleted', [
                            'candidate_id' => $candidate_id,
                            'candidate_name' => $candidate['candidate_name']
                        ], null, $_SESSION['admin_id']);
                    }
                }
            }
            
            // Handle user operations
            elseif (isset($_POST['user_action'])) {
                $action = $_POST['user_action'];
                
                if ($action === 'add' || $action === 'edit') {
                    $username = Security::sanitizeInput($_POST['username'] ?? '');
                    $email = Security::sanitizeInput($_POST['email'] ?? '');
                    $full_name = Security::sanitizeInput($_POST['full_name'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : null;
                    
                    // Validation
                    if (empty($username)) {
                        $errors[] = "Username is required.";
                    } elseif (!Validator::validateStudentId($username)) {
                        $errors[] = "Invalid student ID format.";
                    }
                    
                    if (empty($email)) {
                        $errors[] = "Email is required.";
                    } elseif (!Validator::validateEmail($email)) {
                        $errors[] = "Invalid email format.";
                    }
                    
                    if (empty($full_name)) {
                        $errors[] = "Full name is required.";
                    }
                    
                    if ($action === 'add' && empty($password)) {
                        $errors[] = "Password is required for new users.";
                    } elseif (!empty($password) && !Validator::validatePassword($password)) {
                        $errors[] = "Password must be at least 8 characters with uppercase, lowercase, and numbers.";
                    }
                    
                    if (empty($errors)) {
                        if ($action === 'add') {
                            // Check for duplicates
                            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                            $stmt->execute([$username, $email]);
                            if ($stmt->fetch()) {
                                $errors[] = "Username or email already exists.";
                            } else {
                                $hashedPassword = Security::hashPassword($password);
                                $stmt = $db->prepare("
                                    INSERT INTO users (username, password, email, full_name, created_at, is_active) 
                                    VALUES (?, ?, ?, ?, NOW(), 1)
                                ");
                                $stmt->execute([$username, $hashedPassword, $email, $full_name]);
                                $success_messages[] = "User added successfully.";
                                
                                Security::logSecurityEvent('user_added', [
                                    'username' => $username,
                                    'email' => $email
                                ], null, $_SESSION['admin_id']);
                            }
                        } else {
                            if (!empty($password)) {
                                $hashedPassword = Security::hashPassword($password);
                                $stmt = $db->prepare("
                                    UPDATE users 
                                    SET username = ?, password = ?, email = ?, full_name = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$username, $hashedPassword, $email, $full_name, $user_id]);
                            } else {
                                $stmt = $db->prepare("
                                    UPDATE users 
                                    SET username = ?, email = ?, full_name = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$username, $email, $full_name, $user_id]);
                            }
                            $success_messages[] = "User updated successfully.";
                            
                            Security::logSecurityEvent('user_updated', [
                                'user_id' => $user_id,
                                'username' => $username
                            ], null, $_SESSION['admin_id']);
                        }
                    }
                } elseif ($action === 'delete') {
                    $user_id = (int)$_POST['user_id'];
                    
                    // Get user info before deletion
                    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                    
                    if ($user) {
                        // Delete user votes first
                        $stmt = $db->prepare("DELETE FROM votes WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                        // Delete user
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        
                        $success_messages[] = "User deleted successfully.";
                        
                        Security::logSecurityEvent('user_deleted', [
                            'deleted_user_id' => $user_id,
                            'username' => $user['username']
                        ], null, $_SESSION['admin_id']);
                    }
                } elseif ($action === 'toggle_status') {
                    $user_id = (int)$_POST['user_id'];
                    $new_status = (int)$_POST['new_status'];
                    
                    $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                    $stmt->execute([$new_status, $user_id]);
                    
                    $status_text = $new_status ? 'activated' : 'deactivated';
                    $success_messages[] = "User $status_text successfully.";
                    
                    Security::logSecurityEvent('user_status_changed', [
                        'user_id' => $user_id,
                        'new_status' => $new_status
                    ], null, $_SESSION['admin_id']);
                }
            }
            
            // Handle logout
            elseif (isset($_POST['logout'])) {
                Auth::adminLogout();
                header("Location: admin_login.php");
                exit();
            }
            
        } catch (Exception $e) {
            error_log("Admin dashboard error: " . $e->getMessage());
            $errors[] = "An error occurred. Please try again.";
        }
    }
}

// Fetch data for display
try {
    $db = Database::getInstance()->getConnection();
    
    // Get nominations
    $nominations_stmt = $db->prepare("
        SELECT id, candidate_name, position, manifesto, is_approved, created_at
        FROM nominations 
        ORDER BY position, candidate_name
    ");
    $nominations_stmt->execute();
    $nominations = $nominations_stmt->fetchAll();
    
    // Get users (with search functionality)
    $search_query = '';
    $users = [];
    if (isset($_POST['search_users']) || isset($_GET['search'])) {
        $search_query = Security::sanitizeInput($_POST['search_username'] ?? $_GET['search'] ?? '');
        $stmt = $db->prepare("
            SELECT id, username, email, full_name, is_active, created_at, last_login
            FROM users 
            WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $searchTerm = "%$search_query%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $users = $stmt->fetchAll();
    }
    
    // Get election statistics
    $stats_stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE is_active = 1) as active_users,
            (SELECT COUNT(*) FROM users) as total_users,
            (SELECT COUNT(*) FROM nominations WHERE is_approved = 1) as approved_candidates,
            (SELECT COUNT(*) FROM nominations) as total_nominations,
            (SELECT COUNT(DISTINCT user_id) FROM votes) as users_voted,
            (SELECT COUNT(*) FROM votes) as total_votes
    ");
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch();
    
    // Get voting results for chart
    $results_stmt = $db->prepare("
        SELECT n.candidate_name, n.position, COUNT(v.id) AS vote_count
        FROM nominations n
        LEFT JOIN votes v ON n.id = v.candidate_id
        WHERE n.is_approved = 1
        GROUP BY n.id, n.candidate_name, n.position
        ORDER BY n.position, vote_count DESC
    ");
    $results_stmt->execute();
    $results = $results_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $errors[] = "Error loading dashboard data.";
    $nominations = [];
    $users = [];
    $stats = ['active_users' => 0, 'total_users' => 0, 'approved_candidates' => 0, 'total_nominations' => 0, 'users_voted' => 0, 'total_votes' => 0];
    $results = [];
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Admin Dashboard</title>
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
            color: #4CAF50;
            font-size: 1.5rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .admin-info {
            color: #ccc;
            font-size: 0.9rem;
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
        
        .btn-primary { background: #4CAF50; color: white; }
        .btn-primary:hover { background: #45a049; transform: translateY(-1px); }
        
        .btn-danger { background: #f44336; color: white; }
        .btn-danger:hover { background: #da190b; }
        
        .btn-secondary { background: #666; color: white; }
        .btn-secondary:hover { background: #777; }
        
        .btn-warning { background: #ff9800; color: white; }
        .btn-warning:hover { background: #e68900; }
        
        .main-content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #ccc;
            font-size: 0.9rem;
        }
        
        .section {
            background: rgba(51, 51, 51, 0.8);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .section h2 {
            color: #4CAF50;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
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
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #fff;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #555;
            border-radius: 6px;
            background: rgba(34, 34, 34, 0.8);
            color: #fff;
            font-size: 0.9rem;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(34, 34, 34, 0.5);
            border-radius: 8px;
            overflow: hidden;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #444;
        }
        
        th {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            font-weight: 600;
        }
        
        tr:hover {
            background: rgba(76, 175, 80, 0.05);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: end;
        }
        
        .chart-container {
            background: rgba(34, 34, 34, 0.5);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
        }
        
        .modal-content {
            background: #333;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 12px;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: #ccc;
        }
        
        @media (max-width: 768px) {
            .header { padding: 1rem; flex-direction: column; gap: 1rem; }
            .main-content { padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="header">
        <h1>Admin Dashboard</h1>
        <div class="header-actions">
            <div class="admin-info">
                Welcome, <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>
            </div>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <button type="submit" name="logout" class="btn btn-danger" 
                        onclick="return confirm('Are you sure you want to logout?')">
                    Logout
                </button>
            </form>
        </div>
    </div>

    <div class="main-content">
        <!-- Display Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_messages)): ?>
            <div class="alert alert-success">
                <?php foreach ($success_messages as $message): ?>
                    <div><?php echo htmlspecialchars($message); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Dashboard -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['approved_candidates']; ?></div>
                <div class="stat-label">Candidates</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['users_voted']; ?></div>
                <div class="stat-label">Users Voted</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_votes']; ?></div>
                <div class="stat-label">Total Votes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['users_voted'] > 0 ? round(($stats['users_voted'] / $stats['active_users']) * 100, 1) : 0; ?>%</div>
                <div class="stat-label">Turnout Rate</div>
            </div>
        </div>

        <!-- Election Results Chart -->
        <?php if (!empty($results)): ?>
        <div class="section">
            <h2>📊 Election Results</h2>
            <div class="chart-container">
                <canvas id="resultsChart" width="400" height="200"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Candidate Management -->
        <div class="section">
            <h2>👥 Candidate Management</h2>
            
            <div class="form-grid">
                <div>
                    <h3>Add/Edit Candidate</h3>
                    <form method="POST" enctype="multipart/form-data" id="candidateForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="candidate_action" value="add" id="candidateAction">
                        <input type="hidden" name="candidate_id" id="candidateId">
                        
                        <div class="form-group">
                            <label for="candidate_name">Candidate Name *</label>
                            <input type="text" id="candidate_name" name="candidate_name" required maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="position">Position *</label>
                            <select id="position" name="position" required>
                                <option value="">Select Position</option>
                                <option value="president">President</option>
                                <option value="vice_president">Vice President</option>
                                <option value="secretary">Secretary</option>
                                <option value="treasurer">Treasurer</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="manifesto">Manifesto *</label>
                            <textarea id="manifesto" name="manifesto" rows="4" required maxlength="1000"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="photo">Photo *</label>
                            <input type="file" id="photo" name="photo" accept="image/*">
                            <small style="color: #ccc;">JPG, PNG, or GIF. Max 5MB.</small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="candidateSubmitBtn">Add Candidate</button>
                        <button type="button" class="btn btn-secondary" onclick="resetCandidateForm()">Cancel</button>
                    </form>
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Manifesto</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nominations as $candidate): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($candidate['candidate_name']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $candidate['position']))); ?></td>
                            <td><?php echo htmlspecialchars(substr($candidate['manifesto'], 0, 100)) . (strlen($candidate['manifesto']) > 100 ? '...' : ''); ?></td>
                            <td>
                                <span class="<?php echo $candidate['is_approved'] ? 'text-success' : 'text-warning'; ?>">
                                    <?php echo $candidate['is_approved'] ? '✓ Approved' : '⚠ Pending'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($candidate['created_at'])); ?></td>
                            <td class="action-buttons">
                                <button class="btn btn-secondary" onclick="editCandidate(<?php echo $candidate['id']; ?>)">Edit</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this candidate?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="candidate_action" value="delete">
                                    <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($nominations)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #ccc;">No candidates found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Management -->
        <div class="section">
            <h2>👤 User Management</h2>
            
            <div class="form-grid">
                <div>
                    <h3>Add/Edit User</h3>
                    <form method="POST" id="userForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="user_action" value="add" id="userAction">
                        <input type="hidden" name="user_id" id="userId">
                        
                        <div class="form-group">
                            <label for="username">Student ID *</label>
                            <input type="text" id="username" name="username" required maxlength="20">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" minlength="8">
                            <small style="color: #ccc;">Leave blank to keep current password (for edits)</small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="userSubmitBtn">Add User</button>
                        <button type="button" class="btn btn-secondary" onclick="resetUserForm()">Cancel</button>
                    </form>
                </div>
                
                <div>
                    <h3>Search Users</h3>
                    <form method="POST" class="search-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <input type="text" name="search_username" placeholder="Search by ID, email, or name..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <button type="submit" name="search_users" class="btn btn-primary">Search</button>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($users)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td>
                                <span class="<?php echo $user['is_active'] ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $user['is_active'] ? '✓ Active' : '✗ Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $user['last_login'] ? date('M j, Y H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                            <td class="action-buttons">
                                <button class="btn btn-secondary" onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $user['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-warning">
                                        <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user? This will also delete their votes.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif (isset($_POST['search_users'])): ?>
            <p style="text-align: center; color: #ccc; margin-top: 1rem;">No users found matching your search.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Chart.js setup
        <?php if (!empty($results)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('resultsChart').getContext('2d');
            const chartData = <?php echo json_encode($results); ?>;
            
            const labels = chartData.map(item => item.candidate_name);
            const votes = chartData.map(item => parseInt(item.vote_count));
            const positions = chartData.map(item => item.position);
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Votes',
                        data: votes,
                        backgroundColor: 'rgba(76, 175, 80, 0.6)',
                        borderColor: 'rgba(76, 175, 80, 1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            labels: { color: '#fff' }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#444' },
                            ticks: { color: '#fff' }
                        },
                        x: {
                            grid: { color: '#444' },
                            ticks: { color: '#fff' }
                        }
                    }
                }
            });
        });
        <?php endif; ?>

        // Candidate management functions
        function editCandidate(id) {
            fetch(`get_nomination.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.candidate_name) {
                        document.getElementById('candidateId').value = data.id;
                        document.getElementById('candidate_name').value = data.candidate_name;
                        document.getElementById('position').value = data.position;
                        document.getElementById('manifesto').value = data.manifesto;
                        document.getElementById('candidateAction').value = 'edit';
                        document.getElementById('candidateSubmitBtn').textContent = 'Update Candidate';
                        document.getElementById('photo').required = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading candidate data');
                });
        }

        function resetCandidateForm() {
            document.getElementById('candidateForm').reset();
            document.getElementById('candidateId').value = '';
            document.getElementById('candidateAction').value = 'add';
            document.getElementById('candidateSubmitBtn').textContent = 'Add Candidate';
            document.getElementById('photo').required = true;
        }

        // User management functions
        function editUser(id) {
            fetch(`get_user.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.username) {
                        document.getElementById('userId').value = data.id;
                        document.getElementById('username').value = data.username;
                        document.getElementById('email').value = data.email || '';
                        document.getElementById('full_name').value = data.full_name || '';
                        document.getElementById('userAction').value = 'edit';
                        document.getElementById('userSubmitBtn').textContent = 'Update User';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user data');
                });
        }

        function resetUserForm() {
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('userAction').value = 'add';
            document.getElementById('userSubmitBtn').textContent = 'Add User';
        }

        // Form validation
        document.getElementById('candidateForm').addEventListener('submit', function(e) {
            const name = document.getElementById('candidate_name').value.trim();
            const position = document.getElementById('position').value;
            const manifesto = document.getElementById('manifesto').value.trim();
            
            if (!name || !position || !manifesto) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return;
            }
            
            if (manifesto.length > 1000) {
                e.preventDefault();
                alert('Manifesto must be 1000 characters or less');
                return;
            }
        });

        document.getElementById('userForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const fullName = document.getElementById('full_name').value.trim();
            const password = document.getElementById('password').value;
            const isAdd = document.getElementById('userAction').value === 'add';
            
            if (!username || !email || !fullName) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return;
            }
            
            if (isAdd && !password) {
                e.preventDefault();
                alert('Password is required for new users');
                return;
            }
            
            if (password && password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return;
            }
            
            if (!/^[0-9]+$/.test(username)) {
                e.preventDefault();
                alert('Student ID must contain only numbers');
                return;
            }
        });

        // Auto-refresh stats every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>