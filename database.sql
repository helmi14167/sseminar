-- University Council Election System Database Schema
-- Create database
CREATE DATABASE IF NOT EXISTS `database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `database`;

-- Users table for student accounts
CREATE TABLE IF NOT EXISTS `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL UNIQUE,
    `password` varchar(255) NOT NULL,
    `email` varchar(100) DEFAULT NULL,
    `full_name` varchar(100) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `is_active` tinyint(1) DEFAULT 1,
    `last_login` timestamp NULL DEFAULT NULL,
    `failed_login_attempts` int(11) DEFAULT 0,
    `locked_until` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins table for system administrators
CREATE TABLE IF NOT EXISTS `admins` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL UNIQUE,
    `password` varchar(255) NOT NULL,
    `email` varchar(100) DEFAULT NULL,
    `full_name` varchar(100) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `is_active` tinyint(1) DEFAULT 1,
    `last_login` timestamp NULL DEFAULT NULL,
    `role` enum('super_admin', 'admin') DEFAULT 'admin',
    PRIMARY KEY (`id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nominations table for candidates
CREATE TABLE IF NOT EXISTS `nominations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `candidate_name` varchar(100) NOT NULL,
    `position` varchar(50) NOT NULL,
    `manifesto` text NOT NULL,
    `photo` longblob,
    `user_id` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `is_approved` tinyint(1) DEFAULT 0,
    `approved_by` int(11) DEFAULT NULL,
    `approved_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_nominations_user` (`user_id`),
    KEY `fk_nominations_approved_by` (`approved_by`),
    INDEX `idx_position` (`position`),
    INDEX `idx_approved` (`is_approved`),
    CONSTRAINT `fk_nominations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_nominations_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Votes table for storing votes
CREATE TABLE IF NOT EXISTS `votes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `candidate_id` int(11) NOT NULL,
    `position` varchar(50) NOT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_user_position` (`user_id`, `position`),
    KEY `fk_votes_user` (`user_id`),
    KEY `fk_votes_candidate` (`candidate_id`),
    INDEX `idx_position` (`position`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_votes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `nominations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Election settings table
CREATE TABLE IF NOT EXISTS `election_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(100) NOT NULL UNIQUE,
    `setting_value` text NOT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit logs table for security tracking
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) DEFAULT NULL,
    `admin_id` int(11) DEFAULT NULL,
    `action` varchar(100) NOT NULL,
    `table_name` varchar(50) DEFAULT NULL,
    `record_id` int(11) DEFAULT NULL,
    `old_values` json DEFAULT NULL,
    `new_values` json DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_audit_user` (`user_id`),
    KEY `fk_audit_admin` (`admin_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table for secure session management
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` varchar(128) NOT NULL,
    `user_id` int(11) NOT NULL,
    `user_type` enum('user', 'admin') NOT NULL DEFAULT 'user',
    `ip_address` varchar(45) NOT NULL,
    `user_agent` text NOT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `expires_at` timestamp NOT NULL,
    `is_active` tinyint(1) DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `fk_sessions_user_id` (`user_id`),
    INDEX `idx_expires_at` (`expires_at`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin account (password: admin123)
INSERT INTO `admins` (`username`, `password`, `email`, `full_name`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@university.edu', 'System Administrator', 'super_admin');

-- Insert default election settings
INSERT INTO `election_settings` (`setting_key`, `setting_value`) VALUES
('election_name', 'University Council Elections 2024'),
('election_start_date', '2024-03-01 09:00:00'),
('election_end_date', '2024-03-01 20:00:00'),
('voting_enabled', '1'),
('registration_enabled', '1'),
('nomination_enabled', '1'),
('results_public', '0'),
('max_failed_login_attempts', '5'),
('account_lockout_duration', '30'),
('session_timeout', '3600'),
('require_email_verification', '0');

-- Create indexes for better performance
CREATE INDEX idx_votes_user_position ON votes(user_id, position);
CREATE INDEX idx_nominations_position_approved ON nominations(position, is_approved);
CREATE INDEX idx_users_username_active ON users(username, is_active);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);

-- Create views for reporting
CREATE VIEW vote_summary AS
SELECT 
    n.position,
    n.candidate_name,
    COUNT(v.id) as vote_count,
    n.id as candidate_id
FROM nominations n
LEFT JOIN votes v ON n.id = v.candidate_id
WHERE n.is_approved = 1
GROUP BY n.id, n.position, n.candidate_name
ORDER BY n.position, vote_count DESC;

CREATE VIEW election_statistics AS
SELECT 
    (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_registered_users,
    (SELECT COUNT(DISTINCT user_id) FROM votes) as total_voters,
    (SELECT COUNT(*) FROM nominations WHERE is_approved = 1) as total_candidates,
    (SELECT COUNT(*) FROM votes) as total_votes_cast,
    (SELECT COUNT(DISTINCT position) FROM nominations WHERE is_approved = 1) as total_positions;