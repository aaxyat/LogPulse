-- LogPulse Database Schema for MySQL / MariaDB (PHP 8.1+ on cPanel)
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `endpoints` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL UNIQUE,
    `ingest_token` VARCHAR(64) NOT NULL UNIQUE,
    `retention_days` INT UNSIGNED NOT NULL DEFAULT 14,
    `rate_limit_per_minute` INT UNSIGNED NOT NULL DEFAULT 600,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `endpoint_id` INT UNSIGNED NOT NULL,
    `level` VARCHAR(20) NOT NULL DEFAULT 'INFO',
    `message` TEXT NOT NULL,
    `context` JSON NULL,
    `payload` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_endpoint_created` (`endpoint_id`, `created_at` DESC),
    INDEX `idx_endpoint_level_created` (`endpoint_id`, `level`, `created_at` DESC),
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `endpoint_id` INT UNSIGNED NULL, -- NULL means all endpoints for this user
    `name` VARCHAR(120) NOT NULL,
    `level_threshold` VARCHAR(20) NOT NULL DEFAULT 'ERROR',
    `count_threshold` INT UNSIGNED NOT NULL DEFAULT 5,
    `time_window_minutes` INT UNSIGNED NOT NULL DEFAULT 5,
    `channel_type` ENUM('webhook', 'slack', 'discord', 'email') NOT NULL DEFAULT 'webhook',
    `target_destination` VARCHAR(500) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_triggered_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rule_id` INT UNSIGNED NOT NULL,
    `endpoint_id` INT UNSIGNED NULL,
    `trigger_count` INT UNSIGNED NOT NULL,
    `details` TEXT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`rule_id`) REFERENCES `alert_rules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uptime_monitors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `target_url` VARCHAR(500) NOT NULL,
    `method` ENUM('GET', 'POST', 'HEAD') NOT NULL DEFAULT 'GET',
    `expected_status` INT UNSIGNED NOT NULL DEFAULT 200,
    `check_interval_minutes` INT UNSIGNED NOT NULL DEFAULT 5,
    `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 10,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_checked_at` DATETIME NULL,
    `last_status_code` INT NULL,
    `last_response_time_ms` INT UNSIGNED NULL,
    `last_is_up` TINYINT(1) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `check_runs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `monitor_id` INT UNSIGNED NOT NULL,
    `status_code` INT NULL,
    `response_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_up` TINYINT(1) NOT NULL DEFAULT 0,
    `error_message` VARCHAR(500) NULL,
    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_monitor_checked` (`monitor_id`, `checked_at` DESC),
    FOREIGN KEY (`monitor_id`) REFERENCES `uptime_monitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
