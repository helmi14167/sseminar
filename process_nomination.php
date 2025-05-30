<?php
define('UCES_SYSTEM', true);
require_once 'config.php';

// Check if user is logged in (optional - nominations can be submitted by non-logged in users)
$user_id = Auth::isLoggedIn() ? $_SESSION['user_id'] : null;

// Initialize variables
$errors = [];
$success = false;

// Only process POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: nomination.php");
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
    $errors[] = "Invalid request. Please try again.";
    Security::logSecurityEvent('csrf_token_invalid', ['page' => 'process_nomination'], $user_id);
} else {
    try {
        // Sanitize input data
        $candidate_name = Security::sanitizeInput($_POST['candidate_name'] ?? '');
        $position = Security::sanitizeInput($_POST['position'] ?? '');
        $manifesto = Security::sanitizeInput($_POST['manifesto'] ?? '');
        $nominator_email = Security::sanitizeInput($_POST['nominator_email'] ?? '');
        $nominator_name = Security::sanitizeInput($_POST['nominator_name'] ?? '');
        
        // Validation
        if (empty($candidate_name)) {
            $errors[] = "Candidate name is required.";
        } elseif (strlen($candidate_name) < 2) {
            $errors[] = "Candidate name must be at least 2 characters long.";
        } elseif (strlen($candidate_name) > 100) {
            $errors[] = "Candidate name must be less than 100 characters.";
        }
        
        if (empty($position)) {
            $errors[] = "Position is required.";
        } elseif (!in_array($position, ['president', 'vice_president', 'secretary', 'treasurer'])) {
            $errors[] = "Invalid position selected.";
        }
        
        if (empty($manifesto)) {
            $errors[] = "Manifesto is required.";
        } elseif (strlen($manifesto) < 50) {
            $errors[] = "Manifesto must be at least 50 characters long.";
        } elseif (strlen($manifesto) > 2000) {
            $errors[] = "Manifesto must be less than 2000 characters.";
        }
        
        if (!empty($nominator_email) && !Validator::validateEmail($nominator_email)) {
            $errors[] = "Please enter a valid email address.";
        }
        
        if (!empty($nominator_name) && strlen($nominator_name) < 2) {
            $errors[] = "Nominator name must be at least 2 characters long.";
        }
        
        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            if (!Validator::validateImageFile($_FILES['photo'])) {
                $errors[] = "Invalid photo file. Please upload a JPG, PNG, or GIF image under 5MB.";
            } else {
                $photo = file_get_contents($_FILES['photo']['tmp_name']);
                
                // Additional image validation
                $image_info = getimagesizefromstring($photo);
                if (!$image_info) {
                    $errors[] = "Invalid image file.";
                } elseif ($image_info[0] > 2000 || $image_info[1] > 2000) {
                    $errors[] = "Image dimensions too large. Maximum 2000x2000 pixels.";
                } elseif ($image_info[0] < 100 || $image_info[1] < 100) {
                    $errors[] = "Image too small. Minimum 100x100 pixels.";
                }
            }
        } else {
            $errors[] = "Photo is required for nomination.";
        }
        
        // Rate limiting check
        $client_ip = Security::getClientIP();
        if (!Security::checkRateLimit($client_ip . '_nomination', 3, 3600)) { // 3 nominations per hour
            $errors[] = "Too many nomination attempts. Please try again later.";
            Security::logSecurityEvent('nomination_rate_limit_exceeded', ['ip' => $client_ip], $user_id);
        }
        
        if (empty($errors)) {
            $db = Database::getInstance()->getConnection();
            
            // Check for duplicate nominations
            $duplicate_stmt = $db->prepare("
                SELECT id FROM nominations 
                WHERE candidate_name = ? AND position = ?
            ");
            $duplicate_stmt->execute([$candidate_name, $position]);
            
            if ($duplicate_stmt->fetch()) {
                $errors[] = "A nomination for this candidate in this position already exists.";
            } else {
                // Check nomination limits per position (optional)
                $position_count_stmt = $db->prepare("
                    SELECT COUNT(*) as count 
                    FROM nominations 
                    WHERE position = ?
                ");
                $position_count_stmt->execute([$position]);
                $position_count = $position_count_stmt->fetch()['count'];
                
                // Set reasonable limits (can be configured)
                $position_limits = [
                    'president' => 10,
                    'vice_president' => 10,
                    'secretary' => 10,
                    'treasurer' => 10
                ];
                
                if ($position_count >= ($position_limits[$position] ?? 10)) {
                    $errors[] = "Maximum number of candidates reached for this position.";
                } else {
                    // Insert nomination
                    $insert_stmt = $db->prepare("
                        INSERT INTO nominations 
                        (candidate_name, position, manifesto, photo, user_id, nominator_email, nominator_name, created_at, is_approved) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)
                    ");
                    
                    if ($insert_stmt->execute([
                        $candidate_name, 
                        $position, 
                        $manifesto, 
                        $photo, 
                        $user_id,
                        $nominator_email,
                        $nominator_name
                    ])) {
                        $nomination_id = $db->lastInsertId();
                        $success = true;
                        
                        // Log successful nomination
                        Security::logSecurityEvent('nomination_submitted', [
                            'nomination_id' => $nomination_id,
                            'candidate_name' => $candidate_name,
                            'position' => $position,
                            'manifesto_length' => strlen($manifesto),
                            'has_photo' => !empty($photo),
                            'nominator_provided' => !empty($nominator_name)
                        ], $user_id);
                        
                        // Set success message
                        $_SESSION['nomination_success'] = "Nomination submitted successfully! " . 
                            htmlspecialchars($candidate_name) . " has been nominated for " . 
                            ucfirst(str_replace('_', ' ', $position)) . ". " .
                            "The nomination is pending approval by administrators.";
                        
                    } else {
                        $errors[] = "Failed to submit nomination. Please try again.";
                        error_log("Nomination insertion failed for: $candidate_name");
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Process nomination error: " . $e->getMessage());
        $errors[] = "An error occurred while processing your nomination. Please try again.";
        
        // Log error
        Security::logSecurityEvent('nomination_processing_error', [
            'error' => $e->getMessage(),
            'candidate_name' => $candidate_name ?? 'Unknown'
        ], $user_id);
    }
}

// Handle redirect based on success or failure
if ($success) {
    header("Location: nomination.php?success=1");
} else {
    // Store errors in session for display
    $_SESSION['nomination_errors'] = $errors;
    header("Location: nomination.php?error=1");
}

exit();
?>