-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 10, 2026 at 04:02 PM
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
-- Database: `neuroguard_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`admin_id`, `username`, `password_hash`, `last_login`) VALUES
(1, 'admin', 'admin123', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `alert_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `alert_type` enum('Drowsy','Yawn','Distracted') NOT NULL,
  `severity` enum('Low','Medium','High') DEFAULT 'Medium',
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`alert_id`, `session_id`, `driver_id`, `alert_type`, `severity`, `timestamp`) VALUES
(501, 1, 1, 'Drowsy', 'High', '2026-03-10 18:58:59'),
(502, 1, 1, 'Drowsy', 'High', '2026-03-10 18:58:59'),
(503, 1, 1, 'Drowsy', 'High', '2026-03-10 18:58:59'),
(504, 1, 1, 'Drowsy', 'High', '2026-03-10 18:58:59'),
(505, 1, 1, 'Drowsy', 'High', '2026-03-10 18:58:59'),
(506, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(507, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(508, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(509, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(510, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(511, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(512, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:03'),
(513, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(514, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(515, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(516, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(517, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(518, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:04'),
(519, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(520, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(521, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(522, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(523, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(524, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(525, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:05'),
(526, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:06'),
(527, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:07'),
(528, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:07'),
(529, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:11'),
(530, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:11'),
(531, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:11'),
(532, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:11'),
(533, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(534, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(535, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(536, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(537, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(538, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(539, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:15'),
(540, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:16'),
(541, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:16'),
(542, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:16'),
(543, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:17'),
(544, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:17'),
(545, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:17'),
(546, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:17'),
(547, 1, 1, 'Distracted', 'Medium', '2026-03-10 18:59:17'),
(548, 1, 1, 'Distracted', 'Medium', '2026-03-10 18:59:17'),
(549, 1, 1, 'Distracted', 'Medium', '2026-03-10 18:59:17'),
(550, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:17'),
(551, 1, 1, 'Distracted', 'Medium', '2026-03-10 18:59:17'),
(552, 1, 1, 'Drowsy', 'High', '2026-03-10 18:59:18'),
(553, 1, 1, 'Distracted', 'Medium', '2026-03-10 18:59:18'),
(554, 1, 1, 'Drowsy', 'High', '2026-03-10 19:08:46'),
(555, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:08:47'),
(556, 1, 1, 'Drowsy', 'High', '2026-03-10 19:08:56'),
(557, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:09:01'),
(558, 1, 1, 'Drowsy', 'High', '2026-03-10 19:09:06'),
(559, 1, 1, 'Drowsy', 'High', '2026-03-10 19:09:16'),
(560, 1, 1, 'Yawn', 'Medium', '2026-03-10 19:09:23'),
(561, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:09:25'),
(562, 1, 1, 'Drowsy', 'High', '2026-03-10 19:09:26'),
(563, 1, 1, 'Drowsy', 'High', '2026-03-10 19:11:05'),
(564, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:11:08'),
(565, 1, 1, 'Drowsy', 'High', '2026-03-10 19:11:16'),
(566, 1, 1, 'Yawn', 'Medium', '2026-03-10 19:11:23'),
(567, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:11:25'),
(568, 1, 1, 'Drowsy', 'High', '2026-03-10 19:11:29'),
(569, 1, 1, 'Drowsy', 'High', '2026-03-10 19:17:37'),
(570, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:17:44'),
(571, 1, 1, 'Drowsy', 'High', '2026-03-10 19:17:51'),
(572, 1, 1, 'Drowsy', 'High', '2026-03-10 19:18:01'),
(573, 1, 1, 'Drowsy', 'High', '2026-03-10 19:27:45'),
(574, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:27:49'),
(575, 1, 1, 'Drowsy', 'High', '2026-03-10 19:27:55'),
(576, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:28:00'),
(577, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:36:45'),
(578, 1, 1, 'Drowsy', 'High', '2026-03-10 19:36:45'),
(579, 1, 1, 'Drowsy', 'High', '2026-03-10 19:36:57'),
(580, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:36:58'),
(581, 1, 1, 'Drowsy', 'High', '2026-03-10 19:37:07'),
(582, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:37:15'),
(583, 1, 1, 'Drowsy', 'High', '2026-03-10 19:37:18'),
(584, 1, 1, 'Distracted', 'Medium', '2026-03-10 19:37:30'),
(585, 1, 1, 'Drowsy', 'High', '2026-03-10 19:37:31'),
(586, 2, 1, 'Drowsy', 'High', '2026-03-10 20:22:12'),
(587, 2, 1, 'Distracted', 'Medium', '2026-03-10 20:22:15');

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `license_number` varchar(50) NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`driver_id`, `full_name`, `license_number`, `phone_number`, `created_at`) VALUES
(1, 'Test User', 'ABC-12345', '0771234567', '2026-03-10 13:04:23');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `session_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `start_time` datetime DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `status` enum('Active','Completed') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`session_id`, `driver_id`, `start_time`, `end_time`, `status`) VALUES
(1, 1, '2026-03-10 18:34:23', NULL, 'Active'),
(2, 1, '2026-03-10 20:22:05', NULL, 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`driver_id`),
  ADD UNIQUE KEY `license_number` (`license_number`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=588;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
