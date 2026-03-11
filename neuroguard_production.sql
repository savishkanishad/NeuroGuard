-- NeuroGuard Production Database Schema
-- Use this file to import into your InfinityFree phpMyAdmin panel.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `admin_users`
--
CREATE TABLE IF NOT EXISTS `admin_users` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Default Admin Account (Change password after first login!)
--
INSERT INTO `admin_users` (`username`, `password_hash`) VALUES ('admin', 'admin123');

--
-- Table structure for table `drivers`
--
CREATE TABLE IF NOT EXISTS `drivers` (
  `driver_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `license_number` varchar(50) NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`driver_id`),
  UNIQUE KEY `license_number` (`license_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Seed a test driver for the monitor to work immediately
--
INSERT INTO `drivers` (`driver_id`, `full_name`, `license_number`) VALUES (1, 'Main Driver', 'NG-PRODUCTION-01');

--
-- Table structure for table `sessions`
--
CREATE TABLE IF NOT EXISTS `sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `start_time` datetime DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `status` enum('Active','Completed') DEFAULT 'Active',
  PRIMARY KEY (`session_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Active session for demo
--
INSERT INTO `sessions` (`session_id`, `driver_id`, `status`) VALUES (1, 1, 'Active');

--
-- Table structure for table `alerts`
--
CREATE TABLE IF NOT EXISTS `alerts` (
  `alert_id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `alert_type` enum('Drowsy','Yawn','Distracted') NOT NULL,
  `severity` enum('Low','Medium','High') DEFAULT 'Medium',
  `timestamp` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`alert_id`),
  KEY `session_id` (`session_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
