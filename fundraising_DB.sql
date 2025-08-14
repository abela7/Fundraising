-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 14, 2025 at 06:59 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fundraising`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `action` varchar(50) NOT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `ip_address` varbinary(16) DEFAULT NULL,
  `source` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `counters`
--

CREATE TABLE `counters` (
  `id` tinyint(4) NOT NULL,
  `paid_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pledged_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `recalc_needed` tinyint(1) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `counters`
--

INSERT INTO `counters` (`id`, `paid_total`, `pledged_total`, `grand_total`, `version`, `recalc_needed`, `last_updated`) VALUES
(1, 0.00, 0.00, 0.00, 1, 0, '2025-08-14 16:41:42');

-- --------------------------------------------------------

--
-- Table structure for table `donation_packages`
--

CREATE TABLE `donation_packages` (
  `id` int(11) NOT NULL,
  `label` varchar(50) NOT NULL,
  `sqm_meters` decimal(8,2) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `donation_packages`
--

INSERT INTO `donation_packages` (`id`, `label`, `sqm_meters`, `price`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, '1 m²', 1.00, 400.00, 1, 1, '2025-08-13 05:31:09', '2025-08-13 21:22:39'),
(2, '1/2 m²', 0.50, 200.00, 1, 2, '2025-08-13 05:31:09', '2025-08-13 05:31:09'),
(3, '1/4 m²', 0.25, 100.00, 1, 3, '2025-08-13 05:31:09', '2025-08-13 05:31:09'),
(4, 'Custom', 0.00, 0.00, 1, 4, '2025-08-13 05:31:09', '2025-08-13 05:31:09');

-- --------------------------------------------------------

--
-- Table structure for table `message_attachments`
--

CREATE TABLE `message_attachments` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size_bytes` int(10) UNSIGNED NOT NULL,
  `width` int(10) UNSIGNED DEFAULT NULL,
  `height` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `donor_name` varchar(255) DEFAULT NULL,
  `donor_phone` varchar(30) DEFAULT NULL,
  `donor_email` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('cash','card','bank','other') NOT NULL DEFAULT 'cash',
  `package_id` int(11) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','voided') NOT NULL DEFAULT 'pending',
  `received_by_user_id` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pledges`
--

CREATE TABLE `pledges` (
  `id` int(11) NOT NULL,
  `donor_name` varchar(255) DEFAULT NULL,
  `donor_phone` varchar(30) DEFAULT NULL,
  `donor_email` varchar(255) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `source` enum('self','volunteer') NOT NULL DEFAULT 'volunteer',
  `anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('pledge','paid') NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `client_uuid` char(36) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `status_changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `pledges`
--
DELIMITER $$
CREATE TRIGGER `trg_pledges_status_changed` BEFORE UPDATE ON `pledges` FOR EACH ROW BEGIN
  IF NEW.status <> OLD.status THEN
    SET NEW.status_changed_at = CURRENT_TIMESTAMP;
    IF NEW.status = 'approved' AND OLD.status <> 'approved' THEN
      SET NEW.approved_at = IFNULL(NEW.approved_at, CURRENT_TIMESTAMP);
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `projector_commands`
--

CREATE TABLE `projector_commands` (
  `id` int(11) NOT NULL,
  `command_type` enum('announcement','footer_message','effect','setting') NOT NULL,
  `command_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`command_data`)),
  `created_by_user_id` int(11) DEFAULT NULL,
  `executed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projector_commands`
--

INSERT INTO `projector_commands` (`id`, `command_type`, `command_data`, `created_by_user_id`, `executed`, `created_at`) VALUES
(1, 'setting', '{\"command\":\"updateSettings\",\"data\":{\"refreshRate\":10,\"displayTheme\":\"celebration\",\"showTicker\":true,\"showProgress\":true,\"showQR\":true,\"showClock\":true},\"timestamp\":1755141337221}', 1, 1, '2025-08-14 03:15:37'),
(2, 'setting', '{\"command\":\"updateSettings\",\"data\":{\"refreshRate\":10,\"displayTheme\":\"celebration\",\"showTicker\":true,\"showProgress\":false,\"showQR\":true,\"showClock\":true},\"timestamp\":1755141369145}', 1, 1, '2025-08-14 03:16:09');

-- --------------------------------------------------------

--
-- Table structure for table `projector_footer`
--

CREATE TABLE `projector_footer` (
  `id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_visible` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projector_footer`
--

INSERT INTO `projector_footer` (`id`, `message`, `is_visible`, `created_at`, `updated_at`) VALUES
(1, 'ለመስጠት እጃቹህ የተዘረጋ በሙሉ ጻድቁ አባታችን አቡነ ተክለሃይማኖት በበረከት ይጎብኟቹህ!', 1, '2025-08-14 03:24:51', '2025-08-14 15:46:14');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` tinyint(4) NOT NULL,
  `target_amount` decimal(10,2) NOT NULL DEFAULT 100000.00,
  `currency_code` char(3) NOT NULL DEFAULT 'GBP',
  `display_token` char(64) NOT NULL,
  `display_token_expires_at` datetime DEFAULT NULL,
  `projector_names_mode` enum('full','first_initial','off') NOT NULL DEFAULT 'full',
  `refresh_seconds` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `projector_display_mode` varchar(10) DEFAULT 'amount'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `target_amount`, `currency_code`, `display_token`, `display_token_expires_at`, `projector_names_mode`, `refresh_seconds`, `version`, `created_at`, `updated_at`, `projector_display_mode`) VALUES
(1, 30000.00, 'GBP', '7856996902e5612296dd487a8b8a85564407222cbfd8c032b06eb641249505c3', NULL, 'full', 4, 1, '2025-08-11 21:40:50', '2025-08-14 11:02:41', 'sqm');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` enum('admin','registrar') NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `login_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `phone`, `email`, `role`, `password_hash`, `active`, `login_attempts`, `locked_until`, `created_at`, `last_login_at`) VALUES
(1, 'Abel Demssiee', '07360436171', 'abelgoytom77@gmail.com', 'admin', '$2y$10$BoI2Vo56X9.NRbZ0RylNW.iR7wf.t60fNBfYz0jDgHWFxrCtCc45m', 1, 0, NULL, '2025-08-12 00:42:00', '2025-08-13 22:26:17'),
(2, 'Maeruf Nasir', '07438 324115', 'marufnasirrrr@gmail.com', 'registrar', '$2y$10$Wz63j3l2uEZZC1P8sNalUuVfCjWHWm4dKGYAMkhj.I36vypvdbS06', 1, 0, NULL, '2025-08-12 18:08:21', '2025-08-13 23:49:07');

-- --------------------------------------------------------

--
-- Table structure for table `user_blocklist`
--

CREATE TABLE `user_blocklist` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `blocked_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_messages`
--

CREATE TABLE `user_messages` (
  `id` int(11) NOT NULL,
  `sender_user_id` int(11) NOT NULL,
  `recipient_user_id` int(11) NOT NULL,
  `pair_min_user_id` int(11) NOT NULL,
  `pair_max_user_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `attachment_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `client_uuid` char(36) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `sender_deleted_at` datetime DEFAULT NULL,
  `recipient_deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  ADD KEY `idx_audit_user` (`user_id`,`created_at`);

--
-- Indexes for table `counters`
--
ALTER TABLE `counters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `donation_packages`
--
ALTER TABLE `donation_packages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message` (`message_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payments_received_by` (`received_by_user_id`),
  ADD KEY `idx_payments_pledge_created` (`created_at`),
  ADD KEY `idx_payments_created_at` (`created_at`),
  ADD KEY `idx_payments_method_created` (`method`,`created_at`),
  ADD KEY `fk_payments_package` (`package_id`),
  ADD KEY `idx_status_received` (`status`,`received_at`),
  ADD KEY `idx_status_created` (`status`,`created_at`);

--
-- Indexes for table `pledges`
--
ALTER TABLE `pledges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pledges_client_uuid` (`client_uuid`),
  ADD KEY `fk_pledges_approved_by` (`approved_by_user_id`),
  ADD KEY `idx_pledges_status_created` (`status`,`created_at`),
  ADD KEY `idx_pledges_source_status_created` (`source`,`status`,`created_at`),
  ADD KEY `idx_pledges_approved_at` (`approved_at`),
  ADD KEY `idx_pledges_created_by` (`created_by_user_id`),
  ADD KEY `idx_pledges_anonymous` (`anonymous`),
  ADD KEY `idx_pledges_status_changed` (`status_changed_at`),
  ADD KEY `fk_pledges_package` (`package_id`),
  ADD KEY `idx_status_approved` (`status`,`approved_at`),
  ADD KEY `idx_status_created` (`status`,`created_at`),
  ADD KEY `idx_user_status` (`created_by_user_id`,`status`);

--
-- Indexes for table `projector_commands`
--
ALTER TABLE `projector_commands`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_executed_created` (`executed`,`created_at`);

--
-- Indexes for table `projector_footer`
--
ALTER TABLE `projector_footer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visibility` (`is_visible`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_settings_id` (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_phone` (`phone`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- Indexes for table `user_blocklist`
--
ALTER TABLE `user_blocklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_block_pair` (`user_id`,`blocked_user_id`),
  ADD KEY `fk_block_blocked` (`blocked_user_id`);

--
-- Indexes for table `user_messages`
--
ALTER TABLE `user_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dm_client_uuid` (`client_uuid`),
  ADD KEY `idx_dm_recipient_unread` (`recipient_user_id`,`read_at`),
  ADD KEY `idx_dm_sender_created` (`sender_user_id`,`created_at`),
  ADD KEY `idx_dm_pair_created` (`pair_min_user_id`,`pair_max_user_id`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `donation_packages`
--
ALTER TABLE `donation_packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pledges`
--
ALTER TABLE `pledges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projector_commands`
--
ALTER TABLE `projector_commands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `projector_footer`
--
ALTER TABLE `projector_footer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_blocklist`
--
ALTER TABLE `user_blocklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_messages`
--
ALTER TABLE `user_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `fk_attach_message` FOREIGN KEY (`message_id`) REFERENCES `user_messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_package` FOREIGN KEY (`package_id`) REFERENCES `donation_packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pledges`
--
ALTER TABLE `pledges`
  ADD CONSTRAINT `fk_pledges_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pledges_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pledges_package` FOREIGN KEY (`package_id`) REFERENCES `donation_packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `user_blocklist`
--
ALTER TABLE `user_blocklist`
  ADD CONSTRAINT `fk_block_blocked` FOREIGN KEY (`blocked_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_block_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_messages`
--
ALTER TABLE `user_messages`
  ADD CONSTRAINT `fk_dm_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dm_sender` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
