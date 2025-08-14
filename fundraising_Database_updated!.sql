-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 14, 2025 at 06:41 PM
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

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `entity_type`, `entity_id`, `action`, `before_json`, `after_json`, `ip_address`, `source`, `created_at`) VALUES
(1, 2, 'pledge', 9, 'Submitted pledge #9 for approval.', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 19:57:04'),
(2, 2, 'pledge', 10, 'Submitted pledge #10 for approval.', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 19:57:59'),
(3, 2, 'pledge', 11, 'Submitted pledge #11 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 19:58:30'),
(4, 2, 'pledge', 12, 'Submitted pledge #12 for approval.', NULL, '{\"amount\":25,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 19:58:51'),
(5, 2, 'pledge', 13, 'Submitted pledge #13 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:00:31'),
(6, 2, 'pledge', 14, 'Submitted pledge #14 for approval.', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:04:25'),
(7, 2, 'pledge', 15, 'Submitted pledge #15 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:04:45'),
(8, 2, 'pledge', 16, 'Submitted pledge #16 for approval.', NULL, '{\"amount\":30,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:07:28'),
(9, 2, 'pledge', 17, 'Submitted pledge #17 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:12:13'),
(10, 2, 'pledge', 18, 'Submitted pledge #18 for approval.', NULL, '{\"amount\":120,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:16:35'),
(11, 2, 'pledge', 19, 'Submitted paid #19 for approval.', NULL, '{\"amount\":67,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:18:13'),
(12, 2, 'pledge', 20, 'Submitted paid #20 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:53:58'),
(13, 2, 'pledge', 21, 'Submitted paid #21 for approval.', NULL, '{\"amount\":200,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:57:34'),
(14, 2, 'pledge', 22, 'Submitted paid #22 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 20:59:20'),
(15, 2, 'pledge', 23, 'Submitted paid #23 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 21:02:37'),
(16, 2, 'pledge', 24, 'Submitted paid #24 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 21:03:46'),
(17, 2, 'pledge', 25, 'Submitted pledge #25 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Werku Alemneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 21:04:33'),
(18, 2, 'pledge', 1, 'Submitted pledge #1 for approval.', NULL, '{\"amount\":400,\"type\":\"pledge\",\"public_type\":\"public\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 21:55:23'),
(19, 2, 'pledge', 2, 'Submitted pledge #2 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"public_type\":\"anonymous\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-12 21:55:50'),
(20, 1, 'pledge', 2, 'update', '{\"status\":\"pending\"}', '{\"donor_name\":\"Michael werkenehh\",\"amount\":200,\"updated\":true}', NULL, 'admin', '2025-08-13 00:35:42'),
(21, 2, 'pledge', 3, 'Submitted paid #3 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"public_type\":\"public\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 00:36:25'),
(22, 2, 'pledge', 4, 'Submitted pledge #4 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"public_type\":\"anonymous\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 00:46:12'),
(23, 2, 'pledge', 5, 'Submitted pledge #5 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"public_type\":\"anonymous\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 00:46:49'),
(24, 1, 'pledge', 6, 'Submitted paid #6 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"public_type\":\"anonymous\",\"donor\":null,\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 01:15:30'),
(25, 1, 'pledge', 7, 'Submitted pledge #7 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"public_type\":\"anonymous\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 02:25:34'),
(26, 1, 'pledge', 8, 'Submitted pledge #8 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"public_type\":\"anonymous\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 02:29:07'),
(27, 1, 'pledge', 8, 'update', '{\"status\":\"pending\"}', '{\"donor_name\":\"Michael werkenehh\",\"amount\":100,\"updated\":true}', NULL, 'admin', '2025-08-13 03:02:45'),
(28, 1, 'pledge', 1, 'Submitted pledge #1 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 03:11:33'),
(29, 1, 'pledge', 2, 'Submitted pledge #2 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 03:28:26'),
(30, 1, 'pledge', 3, 'Submitted paid #3 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 03:29:14'),
(31, 1, 'pledge', 4, 'Submitted paid #4 for approval.', NULL, '{\"amount\":100,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 03:29:42'),
(32, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"paid\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 03:50:15'),
(33, 1, 'pledge', 3, 'approve', '{\"status\":\"pending\",\"type\":\"paid\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 03:50:32'),
(34, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 03:50:39'),
(35, 1, 'pledge', 1, 'reject', '{\"status\":\"pending\"}', '{\"status\":\"rejected\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 03:50:42'),
(36, 1, 'pledge', 1, 'Submitted paid #1 for approval.', NULL, '{\"amount\":400,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:14:14'),
(37, 1, 'pledge', 2, 'Submitted pledge #2 for approval.', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:15:02'),
(38, 1, 'pledge', 3, 'Submitted paid #3 for approval.', NULL, '{\"amount\":200,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:15:25'),
(39, 1, 'pledge', 4, 'Submitted pledge #4 for approval.', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:16:15'),
(40, 1, 'pledge', 5, 'Submitted pledge #5 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:16:49'),
(41, 1, 'pledge', 5, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 04:32:40'),
(42, 1, 'pledge', 1, 'Submitted paid #1 for approval.', NULL, '{\"amount\":400,\"type\":\"paid\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:34:58'),
(43, 1, 'pledge', 2, 'Submitted paid #2 for approval.', NULL, '{\"amount\":400,\"type\":\"paid\",\"anonymous\":1,\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:36:21'),
(44, 1, 'pledge', 3, 'Submitted pledge #3 for approval.', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:36:56'),
(45, 1, 'payment', 1, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:53:45'),
(46, 1, 'payment', 2, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 04:54:02'),
(47, 1, 'pledge', 1, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 05:01:16'),
(48, 1, 'pledge', 2, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 05:03:38'),
(49, 1, 'payment', 1, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 05:14:55'),
(50, 1, 'pledge', 1, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 05:16:42'),
(51, 1, 'payment', 1, 'create_pending', NULL, '{\"amount\":200,\"method\":\"bank\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 05:19:58'),
(52, 1, 'payment', 2, 'create_pending', NULL, '{\"amount\":400,\"method\":\"cash\",\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:10:43'),
(53, 1, 'pledge', 1, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:42:26'),
(54, 1, 'payment', 3, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:43:15'),
(55, 1, 'payment', 4, 'create_pending', NULL, '{\"amount\":400,\"method\":\"card\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:44:30'),
(56, 1, 'pledge', 2, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:44:42'),
(57, 1, 'pledge', 3, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 15:46:17'),
(58, 1, 'payment', 1, 'create_pending', NULL, '{\"amount\":400,\"method\":\"card\",\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 16:05:16'),
(59, 1, 'pledge', 1, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 16:52:24'),
(60, 1, 'pledge', 1, 'update', '{\"status\":\"pending\"}', '{\"donor_name\":\"Michael werkenehh\",\"amount\":200,\"package_id\":2,\"updated\":true}', NULL, 'admin', '2025-08-13 16:56:52'),
(61, 1, 'pledge', 1, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 17:01:31'),
(62, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-13 17:01:34'),
(63, 1, 'pledge', 1, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 17:05:41'),
(64, 1, 'payment', 1, 'create_pending', NULL, '{\"amount\":400,\"method\":\"card\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 17:05:49'),
(65, 1, 'pledge', 2, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 17:06:03'),
(66, 1, 'payment', 2, 'create_pending', NULL, '{\"amount\":100,\"method\":\"card\",\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 17:06:13'),
(67, 1, 'pledge', 1, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 17:06:34'),
(68, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-13 17:06:36'),
(69, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 17:06:38'),
(70, 1, 'payment', 2, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-13 17:06:40'),
(71, 1, 'auth', 0, 'Logged out', NULL, NULL, NULL, NULL, '2025-08-13 17:34:40'),
(72, 2, 'pledge', 3, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"mekau\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 19:33:13'),
(73, 1, 'pledge', 3, 'reject', '{\"status\":\"pending\"}', '{\"status\":\"rejected\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 19:34:31'),
(74, 2, 'pledge', 4, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"alem geremew\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-13 19:35:49'),
(75, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 19:36:11'),
(76, 1, 'pledge', 4, 'update_approved', '{\"amount\":100}', '{\"amount\":100,\"updated\":true}', NULL, 'admin', '2025-08-13 19:39:52'),
(77, 1, 'pledge', 4, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 19:40:24'),
(78, 1, 'payment', 2, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 19:40:57'),
(79, 1, 'payment', 2, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-13 19:41:12'),
(80, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 19:41:21'),
(81, 1, 'pledge', 2, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 19:41:32'),
(82, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 19:41:50'),
(83, 1, 'pledge', 2, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 19:47:18'),
(84, 1, 'pledge', 4, 'update_to_pending', '{\"status\":\"approved\",\"amount\":100}', '{\"status\":\"pending\",\"amount\":200,\"updated\":true}', NULL, 'admin', '2025-08-13 19:54:36'),
(85, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 19:54:51'),
(86, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 21:29:15'),
(87, 1, 'pledge', 2, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 21:29:21'),
(88, 1, 'pledge', 4, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-13 21:29:25'),
(89, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 21:30:48'),
(90, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-13 21:30:49'),
(91, 2, 'pledge', 5, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 00:05:48'),
(92, 2, 'pledge', 6, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 00:06:54'),
(93, 2, 'payment', 3, 'create_pending', NULL, '{\"amount\":100,\"method\":\"bank\",\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 00:15:29'),
(94, 1, 'payment', 3, 'reject', '{\"status\":\"pending\"}', '{\"status\":\"voided\"}', NULL, 'admin', '2025-08-14 00:17:31'),
(95, 1, 'pledge', 6, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 00:17:33'),
(96, 1, 'pledge', 5, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 00:17:34'),
(97, 1, 'pledge', 6, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-14 00:45:17'),
(98, 1, 'pledge', 6, 'update', '{\"status\":\"pending\"}', '{\"donor_name\":\"Abel Demssie\",\"amount\":400,\"package_id\":1,\"updated\":true}', NULL, 'admin', '2025-08-14 00:45:34'),
(99, 1, 'pledge', 6, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 00:45:37'),
(100, 1, 'payment', 1, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-14 00:46:01'),
(101, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 00:46:15'),
(102, 1, 'payment', 1, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-14 00:46:48'),
(103, 1, 'payment', 1, 'update', '{\"status\":\"pending\"}', '{\"amount\":200,\"method\":\"card\",\"package_id\":2,\"updated\":true}', NULL, 'admin', '2025-08-14 00:52:34'),
(104, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 00:52:40'),
(105, 2, 'pledge', 7, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 02:16:57'),
(106, 1, 'pledge', 7, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 02:17:14'),
(107, 2, 'pledge', 8, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 02:42:24'),
(108, 1, 'pledge', 8, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 02:42:48'),
(109, 2, 'pledge', 1, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 02:50:00'),
(110, 1, 'pledge', 1, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 02:50:14'),
(111, 2, 'pledge', 2, 'create_pending', NULL, '{\"amount\":20000,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 02:50:54'),
(112, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":20000}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 02:54:15'),
(113, 2, 'payment', 1, 'create_pending', NULL, '{\"amount\":5000,\"method\":\"card\",\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 02:55:19'),
(114, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 02:55:25'),
(115, 1, 'pledge', 1, 'undo_approve', '{\"status\":\"approved\"}', '{\"status\":\"pending\"}', NULL, 'admin', '2025-08-14 02:56:19'),
(116, 1, 'pledge', 1, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 02:57:03'),
(117, 2, 'payment', 2, 'create_pending', NULL, '{\"amount\":200,\"method\":\"bank\",\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 03:04:17'),
(118, 1, 'payment', 2, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 03:04:29'),
(119, 2, 'pledge', 3, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 03:04:56'),
(120, 1, 'pledge', 3, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 03:05:02'),
(121, 2, 'payment', 3, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 03:43:50'),
(122, 1, 'payment', 3, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 03:44:13'),
(123, 2, 'pledge', 4, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 04:15:22'),
(124, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 04:17:11'),
(125, 2, 'pledge', 5, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 04:31:57'),
(126, 2, 'pledge', 6, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 04:32:40'),
(127, 2, 'pledge', 7, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 04:33:35'),
(128, 1, 'pledge', 6, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 04:34:19'),
(129, 1, 'pledge', 5, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 04:34:28'),
(130, 1, 'pledge', 7, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 04:34:32'),
(131, 2, 'payment', 4, 'create_pending', NULL, '{\"amount\":400,\"method\":\"bank\",\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 04:52:35'),
(132, 1, 'payment', 4, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 04:52:45'),
(133, 2, 'pledge', 1, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:27:27'),
(134, 2, 'payment', 1, 'create_pending', NULL, '{\"amount\":400,\"method\":\"card\",\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:27:42'),
(135, 2, 'pledge', 2, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:27:59'),
(136, 2, 'payment', 2, 'create_pending', NULL, '{\"amount\":200,\"method\":\"card\",\"donor\":\"Anonymous\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:28:23'),
(137, 2, 'pledge', 3, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:28:53'),
(138, 1, 'pledge', 1, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 10:29:13'),
(139, 1, 'payment', 1, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 10:29:36'),
(140, 1, 'pledge', 2, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 10:30:37'),
(141, 2, 'pledge', 4, 'create_pending', NULL, '{\"amount\":100,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael werkeneh\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 10:44:00'),
(142, 1, 'pledge', 4, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 10:44:15'),
(143, 1, 'payment', 2, 'approve', '{\"status\":\"pending\"}', '{\"status\":\"approved\"}', NULL, 'admin', '2025-08-14 10:45:31'),
(144, 1, 'pledge', 3, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":100}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 10:53:13'),
(145, 2, 'pledge', 5, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 16:06:49'),
(146, 1, 'pledge', 5, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 16:07:00'),
(147, 2, 'pledge', 6, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Abel Goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 16:19:20'),
(148, 2, 'pledge', 7, 'create_pending', NULL, '{\"amount\":400,\"type\":\"pledge\",\"anonymous\":0,\"donor\":\"Michael goytom\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 16:19:56'),
(149, 1, 'pledge', 7, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 16:20:31'),
(150, 1, 'pledge', 6, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":400}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 16:20:41'),
(151, 2, 'pledge', 8, 'create_pending', NULL, '{\"amount\":200,\"type\":\"pledge\",\"anonymous\":1,\"donor\":\"Abel Demssie\",\"status\":\"pending\"}', NULL, 'registrar', '2025-08-14 16:21:26'),
(152, 1, 'pledge', 8, 'approve', '{\"status\":\"pending\",\"type\":\"pledge\",\"amount\":200}', '{\"status\":\"approved\"}', 0x00000000000000000000000000000001, 'admin', '2025-08-14 16:21:32');

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
(1, 600.00, 2200.00, 16400.00, 11, 0, '2025-08-14 16:21:32');

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

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `donor_name`, `donor_phone`, `donor_email`, `amount`, `method`, `package_id`, `reference`, `status`, `received_by_user_id`, `received_at`, `created_at`) VALUES
(1, 'Michael werkeneh', '07415329333', '', 400.00, 'card', 1, 'Paid via Card.', 'approved', 2, '2025-08-14 10:27:42', '2025-08-14 10:27:42'),
(2, 'Anonymous', NULL, NULL, 200.00, 'card', 2, 'Paid via Card.', 'approved', 2, '2025-08-14 10:28:23', '2025-08-14 10:28:23');

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
-- Dumping data for table `pledges`
--

INSERT INTO `pledges` (`id`, `donor_name`, `donor_phone`, `donor_email`, `package_id`, `source`, `anonymous`, `amount`, `type`, `status`, `notes`, `client_uuid`, `ip_address`, `user_agent`, `proof_path`, `created_by_user_id`, `approved_by_user_id`, `created_at`, `approved_at`, `status_changed_at`) VALUES
(1, 'Abel Goytom', '07360436171', 'abelgoytom77@gmail.com', 1, 'volunteer', 0, 400.00, 'pledge', 'approved', '', '9dabcbd3-2a2b-4f0b-9a57-d4a90835fe98', NULL, NULL, NULL, 2, 1, '2025-08-14 10:27:27', '2025-08-14 10:29:13', '2025-08-14 10:29:13'),
(2, 'Abel Demssie', '07415329333', '', 2, 'volunteer', 1, 200.00, 'pledge', 'approved', '', '79cba977-4b52-4b2d-8868-d7ac0483f396', NULL, NULL, NULL, 2, 1, '2025-08-14 10:27:59', '2025-08-14 10:30:37', '2025-08-14 10:30:37'),
(3, 'Abel Demssie', '07415329333', 'abelgoytom77@gmail.com', 3, 'volunteer', 0, 100.00, 'pledge', 'approved', '', 'cf1604f1-7845-466a-896e-09924d8b2e24', NULL, NULL, NULL, 2, 1, '2025-08-14 10:28:53', '2025-08-14 10:53:13', '2025-08-14 10:53:13'),
(4, 'Michael werkeneh', '07415329333', '', 3, 'volunteer', 0, 100.00, 'pledge', 'approved', '', 'a0a57246-ef47-42e1-9174-cef301e8450c', NULL, NULL, NULL, 2, 1, '2025-08-14 10:44:00', '2025-08-14 10:44:15', '2025-08-14 10:44:15'),
(5, 'Abel Goytom', '07360436171', 'abelgoytom77@gmail.com', 1, 'volunteer', 0, 400.00, 'pledge', 'approved', '', 'b9208ea4-e409-43c4-909f-51340e2c9df8', NULL, NULL, NULL, 2, 1, '2025-08-14 16:06:49', '2025-08-14 16:07:00', '2025-08-14 16:07:00'),
(6, 'Abel Goytom', '07360436170', 'abelgoytom77@gmail.com', 1, 'volunteer', 0, 400.00, 'pledge', 'approved', '', '975acc4a-254a-4264-b0e8-f2fbb0772808', NULL, NULL, NULL, 2, 1, '2025-08-14 16:19:20', '2025-08-14 16:20:41', '2025-08-14 16:20:41'),
(7, 'Michael goytom', '07415329339', '', 1, 'volunteer', 0, 400.00, 'pledge', 'approved', '', '05ff70fa-ee48-4746-9899-05526f552a9a', NULL, NULL, NULL, 2, 1, '2025-08-14 16:19:56', '2025-08-14 16:20:31', '2025-08-14 16:20:31'),
(8, 'Abel Demssie', '07415329330', 'abelgoytom77@gmail.com', 2, 'volunteer', 1, 200.00, 'pledge', 'approved', '', 'c5a90355-9aaf-4466-b966-be52f374730e', NULL, NULL, NULL, 2, 1, '2025-08-14 16:21:26', '2025-08-14 16:21:32', '2025-08-14 16:21:32');

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
-- Dumping data for table `user_messages`
--

INSERT INTO `user_messages` (`id`, `sender_user_id`, `recipient_user_id`, `pair_min_user_id`, `pair_max_user_id`, `body`, `attachment_count`, `client_uuid`, `read_at`, `sender_deleted_at`, `recipient_deleted_at`, `created_at`) VALUES
(1, 1, 2, 1, 2, 'Hey Man!', 0, '9dc551c0-d22a-4efb-bbf7-4280929580d9', '2025-08-13 18:42:32', NULL, NULL, '2025-08-13 17:35:17'),
(2, 2, 1, 1, 2, 'Elias has paid!', 0, 'c28e2d60-7443-4624-85ab-42ba056b3627', '2025-08-13 18:42:44', NULL, NULL, '2025-08-13 17:42:42'),
(3, 1, 2, 1, 2, 'Thanks brother!', 0, '8eb714cf-eea5-4392-864e-b802baa2a0fc', '2025-08-13 18:42:57', NULL, NULL, '2025-08-13 17:42:53'),
(4, 2, 1, 1, 2, 'does it work now?', 0, 'f48e7b3b-b6e7-496c-ba41-9781fbd53ac0', '2025-08-13 18:57:16', NULL, NULL, '2025-08-13 17:57:11'),
(5, 1, 2, 1, 2, 'yes', 0, 'b3f8dd48-7489-49f1-8050-82b7f4e971c7', '2025-08-13 18:57:25', NULL, NULL, '2025-08-13 17:57:25'),
(6, 2, 1, 1, 2, 'Abel', 0, '8bd6dac0-40ce-45e8-b9da-d4de22b3940d', '2025-08-13 19:51:56', NULL, NULL, '2025-08-13 18:51:54'),
(7, 1, 2, 1, 2, 'Yes man', 0, '6ae8b12c-f17b-4e2d-9f8f-3de73c0c0aab', '2025-08-13 19:55:10', NULL, NULL, '2025-08-13 18:55:07'),
(8, 2, 1, 1, 2, 'does it work now?', 0, 'e129dae6-e40e-4ace-9a4f-7132caa1cdb5', '2025-08-13 19:55:20', NULL, NULL, '2025-08-13 18:55:18'),
(9, 1, 2, 1, 2, 'yes and i really like it!', 0, '847e0c51-d3cd-4c86-9a29-7300e4fd1e28', '2025-08-13 19:55:33', NULL, NULL, '2025-08-13 18:55:30'),
(10, 2, 1, 1, 2, 'AWESOME!', 0, 'af9fed69-1aa1-4c17-8728-c101e030acca', '2025-08-13 19:55:51', NULL, NULL, '2025-08-13 18:55:47'),
(11, 1, 2, 1, 2, 'yo', 0, '1925204b-a9db-4a9a-9d27-3212305c3464', '2025-08-13 20:05:59', NULL, NULL, '2025-08-13 19:05:49'),
(12, 2, 1, 1, 2, 'wtz up', 0, '2ba931d3-dd24-4243-aef0-71dbf47d57ee', '2025-08-13 20:06:10', NULL, NULL, '2025-08-13 19:06:09'),
(13, 1, 2, 1, 2, 'all good brother', 0, '0089df65-b3d5-47f0-976a-9a9852ba7e91', '2025-08-13 20:10:51', NULL, NULL, '2025-08-13 19:10:47'),
(14, 2, 1, 1, 2, 'did u', 0, 'a03821dc-c5ad-49a6-8575-64a2c8518929', '2025-08-13 20:11:21', NULL, NULL, '2025-08-13 19:11:06'),
(15, 1, 2, 1, 2, 'di du what?', 0, '341f34c5-8ab0-45af-8f44-ebe28960b5d5', '2025-08-13 20:11:33', NULL, NULL, '2025-08-13 19:11:31'),
(16, 1, 2, 1, 2, 'come?', 0, '652638f5-81a9-4963-b219-63534b877498', '2025-08-13 20:12:38', NULL, NULL, '2025-08-13 19:12:02'),
(17, 2, 1, 1, 2, 'when?', 0, '0f5cf05a-ab26-42ed-a651-f1d18b53cf1a', '2025-08-13 20:13:12', NULL, NULL, '2025-08-13 19:12:43'),
(18, 1, 2, 1, 2, 'haha', 0, '7aab6c36-8df7-4e07-8673-8ef469d3c1bb', '2025-08-13 20:19:27', NULL, NULL, '2025-08-13 19:13:51'),
(19, 2, 1, 1, 2, 'whats app?', 0, '0a066886-068c-403c-8c0c-5e0db59a5308', '2025-08-13 20:19:50', NULL, NULL, '2025-08-13 19:19:39'),
(20, 1, 2, 1, 2, 'this one', 0, '62fd49ee-c209-4500-ac93-ebda6d469fbd', '2025-08-13 20:20:52', NULL, NULL, '2025-08-13 19:20:21'),
(21, 2, 1, 1, 2, 'which meter?', 0, '9f8c0544-6315-4a31-a33b-472e0a1168e3', '2025-08-13 20:21:31', NULL, NULL, '2025-08-13 19:21:05'),
(22, 2, 1, 1, 2, 'are you saing?', 0, 'ab3d859d-5bc7-4554-b0d2-f6a6e921f550', '2025-08-13 20:21:31', NULL, NULL, '2025-08-13 19:21:24'),
(23, 1, 2, 1, 2, 'i see it now', 0, '16609723-7691-4ed7-86f8-542f537a6584', '2025-08-13 20:25:40', NULL, NULL, '2025-08-13 19:25:03'),
(24, 1, 2, 1, 2, '.', 0, '77f612ce-9e3b-4a7e-a5ba-3fee2de86fd2', '2025-08-13 20:25:40', NULL, NULL, '2025-08-13 19:25:23'),
(25, 2, 1, 1, 2, 'agreed!', 0, '7d56cf0f-dd7d-436b-9ab1-773ea336b874', '2025-08-13 20:26:11', NULL, NULL, '2025-08-13 19:25:54'),
(26, 2, 1, 1, 2, 'all fine', 0, '31e4615a-bbf6-44f1-a981-fdc72211e8d4', '2025-08-13 20:26:11', NULL, NULL, '2025-08-13 19:26:03');

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
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `pledges`
--
ALTER TABLE `pledges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
