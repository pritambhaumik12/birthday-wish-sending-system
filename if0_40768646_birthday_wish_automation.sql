-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 01, 2026 at 01:23 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bwu_birthday_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `departments_master`
--

CREATE TABLE `departments_master` (
  `id` int(11) NOT NULL,
  `dept_name` varchar(100) NOT NULL,
  `dept_initials` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments_master`
--

INSERT INTO `departments_master` (`id`, `dept_name`, `dept_initials`) VALUES
(5, 'B.Tech - Computer Science & Engineering', 'BTS');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `sent_to_email` varchar(100) NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'sent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`id`, `student_id`, `sent_to_email`, `sent_at`, `status`) VALUES
(27, 0, 'test.recipient@example.com', '2025-12-31 07:49:10', 'test_sent_manual'),
(28, 6, 'student1@example.com', '2025-12-31 21:43:37', 'sent'),
(29, 7, 'student2@example.com', '2025-12-31 21:48:32', 'sent');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs_24`
--

CREATE TABLE `email_logs_24` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `sent_to_email` varchar(100) NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'sent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_logs_25`
--

CREATE TABLE `email_logs_25` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `sent_to_email` varchar(100) NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'sent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections_master`
--

CREATE TABLE `sections_master` (
  `id` int(11) NOT NULL,
  `dept_id` int(11) NOT NULL,
  `year_id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `reg_open` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students_24`
--

CREATE TABLE `students_24` (
  `id` int(11) NOT NULL,
  `student_custom_id` varchar(50) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `student_mail_id` varchar(100) NOT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `student_phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students_25`
--

CREATE TABLE `students_25` (
  `id` int(11) NOT NULL,
  `student_custom_id` varchar(50) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `student_mail_id` varchar(100) NOT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `student_phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_submissions`
--

CREATE TABLE `student_submissions` (
  `id` int(11) NOT NULL,
  `student_custom_id` varchar(50) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `student_mail_id` varchar(100) NOT NULL,
  `country_code` varchar(10) NOT NULL,
  `student_phone` varchar(20) NOT NULL,
  `date_of_birth` date NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `submission_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_verified` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('auto_accept_verified', '1'),
('last_backup_by', 'Sir'),
('last_backup_time', '2026-01-01 14:51:31');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL DEFAULT 'Administrator',
  `designation` varchar(100) DEFAULT 'Administrator',
  `email` varchar(100) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `status` enum('active','inactive') DEFAULT 'active',
  `last_logout` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `designation`, `email`, `username`, `password`, `role`, `status`, `last_logout`) VALUES
(1, 'Administrator', 'Administrator', 'admin@example.com', 'admin', 'password', 'admin', 'active', NULL),
(2, 'Department Head', 'HoD - CSE', 'hod.cse@example.com', 'hod_cse', 'USE_YOUR_OWN_PASSWORD', 'admin', 'active', '2026-01-01 17:33:28');

-- --------------------------------------------------------

--
-- Table structure for table `wishes`
--

CREATE TABLE `wishes` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'Birthday Greeting',
  `student_id` int(11) DEFAULT NULL,
  `wish_message` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wishes`
--

INSERT INTO `wishes` (`id`, `title`, `student_id`, `wish_message`, `photo_path`, `created_at`, `is_deleted`) VALUES
(1, '1', NULL, '<p style=\"text-align: center; \"><img src=\"uploads/content_694f70d25707a.png\" style=\"width: 50%;\" data-filename=\"Blue and White Modern Happy Birthday Card Lanscape.png\" class=\" img-fluid\"><p style=\"text-align: center;\"><span style=\'font-family: \"Times New Roman\";\'>From,</span></p><p style=\"text-align: center;\"><span style=\'font-family: \"Times New Roman\";\'>BRAINWARE UNIVERSITY<br></span></p><p></p><p></p></p>\n', NULL, '2025-12-27 05:38:26', 0),
(2, '2', NULL, '<p style=\"text-align: center; \"><img src=\"uploads/content_69553e0974d4b.png\" style=\"width: 50%;\" data-filename=\"1.png\" class=\" img-fluid\"><p style=\"text-align: center; \">From,</p><p style=\"text-align: center; \"><span style=\'font-family: \"Times New Roman\";\'><font color=\"#0000ff\">BRAINWARE UNIVERSITY</font></span></p><p></p></p>\n', NULL, '2025-12-31 15:15:21', 0),
(3, '3', NULL, '<p style=\"text-align: center; \"><img src=\"uploads/content_69553e47ddaaf.png\" style=\"width: 50%;\" data-filename=\"2.png\" class=\" img-fluid\"><span style=\'font-family: \"Times New Roman\";\'><br></span><p style=\"text-align: center; \"><span style=\'font-family: \"Times New Roman\";\'>From,</span></p><p style=\"text-align: center; \"><span style=\'font-family: \"Times New Roman\";\'>BRAINWARE UNIVERSITY<br></span><br></p><p></p></p>\n', NULL, '2025-12-31 15:16:23', 0);

-- --------------------------------------------------------

--
-- Table structure for table `years_master`
--

CREATE TABLE `years_master` (
  `id` int(11) NOT NULL,
  `year_val` varchar(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments_master`
--
ALTER TABLE `departments_master`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_name` (`dept_name`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_logs_24`
--
ALTER TABLE `email_logs_24`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_logs_25`
--
ALTER TABLE `email_logs_25`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sections_master`
--
ALTER TABLE `sections_master`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section` (`dept_id`,`year_id`,`section_name`),
  ADD KEY `year_id` (`year_id`);

--
-- Indexes for table `students_24`
--
ALTER TABLE `students_24`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_custom_id` (`student_custom_id`);

--
-- Indexes for table `students_25`
--
ALTER TABLE `students_25`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_custom_id` (`student_custom_id`);

--
-- Indexes for table `student_submissions`
--
ALTER TABLE `student_submissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `wishes`
--
ALTER TABLE `wishes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `years_master`
--
ALTER TABLE `years_master`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_val` (`year_val`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments_master`
--
ALTER TABLE `departments_master`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `email_logs_24`
--
ALTER TABLE `email_logs_24`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_logs_25`
--
ALTER TABLE `email_logs_25`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sections_master`
--
ALTER TABLE `sections_master`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `students_24`
--
ALTER TABLE `students_24`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=324;

--
-- AUTO_INCREMENT for table `students_25`
--
ALTER TABLE `students_25`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_submissions`
--
ALTER TABLE `student_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wishes`
--
ALTER TABLE `wishes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `years_master`
--
ALTER TABLE `years_master`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `sections_master`
--
ALTER TABLE `sections_master`
  ADD CONSTRAINT `sections_master_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments_master` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sections_master_ibfk_2` FOREIGN KEY (`year_id`) REFERENCES `years_master` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
