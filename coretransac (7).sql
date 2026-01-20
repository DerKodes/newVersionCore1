-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 20, 2026 at 06:44 PM
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
-- Database: `coretransac`
--

-- --------------------------------------------------------

--
-- Table structure for table `bills_of_lading`
--

CREATE TABLE `bills_of_lading` (
  `bl_id` int(11) NOT NULL,
  `bl_number` varchar(50) DEFAULT NULL,
  `bl_type` enum('HBL','MBL') DEFAULT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `consolidation_id` int(11) DEFAULT NULL,
  `shipper` varchar(255) DEFAULT NULL,
  `consignee` varchar(255) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consolidations`
--

CREATE TABLE `consolidations` (
  `consolidation_id` int(11) NOT NULL,
  `consolidation_code` varchar(50) DEFAULT NULL,
  `trip_no` varchar(50) DEFAULT NULL,
  `vehicle_set` char(1) DEFAULT NULL,
  `transport_mode` enum('SEA','AIR','LAND') DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `status` enum('OPEN','READY_TO_DISPATCH','DECONSOLIDATED') DEFAULT 'OPEN',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `consolidations`
--

INSERT INTO `consolidations` (`consolidation_id`, `consolidation_code`, `trip_no`, `vehicle_set`, `transport_mode`, `origin`, `destination`, `status`, `created_by`, `created_at`) VALUES
(14, 'CONSO-696F93AA03753', 'TRIP-20260120-02C03', 'B', 'LAND', '47 Dona Pilar St. Villa Beatriz Matandang Balara, Quezon City, Metro Manila, 1119', 'Topaz, Novaliches, Quezon City, Metro Manila', 'READY_TO_DISPATCH', 1, '2026-01-20 14:39:38'),
(15, 'CONSO-696FA0031ACF3', 'TRIP-20260120-1A513', 'C', 'LAND', 'Caloocan City', 'Antipolo City', 'READY_TO_DISPATCH', 1, '2026-01-20 15:32:19'),
(16, 'CONSO-696FA0889B949', 'TRIP-20260120-9B1AC', 'D', 'LAND', 'Bulacan City', 'San Mateo', 'READY_TO_DISPATCH', 1, '2026-01-20 15:34:32'),
(17, 'CONSO-696FA82A373D9', 'TRIP-20260120-36FF4', 'A', 'LAND', 'Matandang Balara', 'Topaz, Novaliches, Quezon City, Metro Manila', 'READY_TO_DISPATCH', 1, '2026-01-20 16:07:06'),
(18, 'CONSO-696FB11D164B6', 'TRIP-20260120-16095', 'B', 'LAND', 'Metro Manila', 'Caloocan City', 'READY_TO_DISPATCH', 1, '2026-01-20 16:45:17'),
(19, 'CONSO-696FB4646A6C8', 'TRIP-20260120-6A100', 'B', 'LAND', 'Novaliches', 'Valenzuela', 'READY_TO_DISPATCH', 1, '2026-01-20 16:59:16');

-- --------------------------------------------------------

--
-- Table structure for table `consolidation_shipments`
--

CREATE TABLE `consolidation_shipments` (
  `id` int(11) NOT NULL,
  `consolidation_id` int(11) DEFAULT NULL,
  `shipment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `consolidation_shipments`
--

INSERT INTO `consolidation_shipments` (`id`, `consolidation_id`, `shipment_id`) VALUES
(14, 14, 19),
(15, 15, 20),
(16, 16, 21),
(17, 17, 22),
(18, 18, 23),
(19, 19, 24);

-- --------------------------------------------------------

--
-- Table structure for table `deconsolidation_logs`
--

CREATE TABLE `deconsolidation_logs` (
  `id` int(11) NOT NULL,
  `consolidation_id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `deconsolidated_by` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hmbl`
--

CREATE TABLE `hmbl` (
  `hmbl_id` int(11) NOT NULL,
  `hmbl_no` varchar(50) DEFAULT NULL,
  `consolidation_id` int(11) NOT NULL,
  `shipper` varchar(255) DEFAULT NULL,
  `consignee` varchar(255) DEFAULT NULL,
  `notify_party` varchar(255) DEFAULT NULL,
  `port_of_loading` varchar(100) DEFAULT NULL,
  `port_of_discharge` varchar(100) DEFAULT NULL,
  `vessel` varchar(100) DEFAULT NULL,
  `voyage` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hmbl`
--

INSERT INTO `hmbl` (`hmbl_id`, `hmbl_no`, `consolidation_id`, `shipper`, `consignee`, `notify_party`, `port_of_loading`, `port_of_discharge`, `vessel`, `voyage`, `created_by`, `created_at`) VALUES
(8, 'HMBL-2026-696F93C2EB2B9', 14, 'SLATE', 'Kim Dexter', '', '47 Dona Pilar St. Villa Beatriz Matandang Balara, Quezon City, Metro Manila, 1119', 'P2GP+JVX, Topaz, Novaliches, Quezon City, Metro Manila', '1HTMMAAM2EH468983', NULL, 1, '2026-01-20 14:40:02'),
(9, 'HMBL-2026-696FA019D8E30', 15, 'SLATE', 'Marie Salazar', '', 'Caloocan City', 'Antipolo City', '1HTMMAAM2EH468983', NULL, 1, '2026-01-20 15:32:41'),
(10, 'HMBL-2026-696FA0A522E0A', 16, 'SLATE', 'Nolasco', '', 'Bulacan City', 'San Mateo', '1HTMMAAM2EH468983', NULL, 1, '2026-01-20 15:35:01'),
(11, 'HMBL-2026-696FA845307B6', 17, 'SLATE', 'Nolasco', '', 'Matandang Balara', 'Topaz, Novaliches, Quezon City, Metro Manila', '1HTMMAAM2EH468983', 'TRIP-20260120-36FF4', 1, '2026-01-20 16:07:33'),
(12, 'HMBL-2026-696FB12E5165E', 18, 'SLATE', 'Marie Salazar', 'Marie Salazar', 'Metro Manila', 'Caloocan City', '1HTMMAAM2EH468983', 'TRIP-20260120-16095', 1, '2026-01-20 16:45:34'),
(13, 'HMBL-2026-696FB4702F768', 19, 'Hesus', 'Manuel', 'Manuel', 'Novaliches', 'Valenzuela', '1HTMMAAM2EH468983', 'TRIP-20260120-6A100', 1, '2026-01-20 16:59:28');

-- --------------------------------------------------------

--
-- Table structure for table `hmbl_shipments`
--

CREATE TABLE `hmbl_shipments` (
  `hmbl_id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hmbl_shipments`
--

INSERT INTO `hmbl_shipments` (`hmbl_id`, `shipment_id`) VALUES
(8, 19),
(9, 20),
(10, 21),
(11, 22),
(12, 23),
(13, 24);

-- --------------------------------------------------------

--
-- Table structure for table `house_bill_of_lading`
--

CREATE TABLE `house_bill_of_lading` (
  `hbl_id` int(11) NOT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `hbl_number` varchar(50) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `shipper` varchar(100) DEFAULT NULL,
  `consignee` varchar(100) DEFAULT NULL,
  `cargo_description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `master_bill_of_lading`
--

CREATE TABLE `master_bill_of_lading` (
  `mbl_id` int(11) NOT NULL,
  `consolidation_id` int(11) DEFAULT NULL,
  `mbl_number` varchar(50) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `carrier_name` varchar(100) DEFAULT NULL,
  `voyage_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `contract_number` varchar(50) NOT NULL,
  `sender_name` varchar(100) DEFAULT NULL,
  `sender_contact` varchar(50) DEFAULT NULL,
  `receiver_name` varchar(100) DEFAULT NULL,
  `receiver_contact` varchar(50) DEFAULT NULL,
  `origin_address` text DEFAULT NULL,
  `destination_address` text DEFAULT NULL,
  `transport_mode` enum('SEA','AIR','LAND') NOT NULL DEFAULT 'LAND',
  `weight` decimal(10,2) DEFAULT NULL,
  `package_type` varchar(50) DEFAULT NULL,
  `package_description` text DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `distance_km` decimal(10,2) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `sla_agreement` varchar(100) DEFAULT NULL,
  `ai_estimated_time` varchar(50) DEFAULT NULL,
  `target_delivery_date` date DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','BOOKED','COMPLETED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `origin_lat` decimal(10,7) DEFAULT NULL,
  `origin_lng` decimal(10,7) DEFAULT NULL,
  `destination_lat` decimal(10,7) DEFAULT NULL,
  `destination_lng` decimal(10,7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `user_id`, `contract_number`, `sender_name`, `sender_contact`, `receiver_name`, `receiver_contact`, `origin_address`, `destination_address`, `transport_mode`, `weight`, `package_type`, `package_description`, `payment_method`, `bank_name`, `distance_km`, `price`, `sla_agreement`, `ai_estimated_time`, `target_delivery_date`, `status`, `created_at`, `origin_lat`, `origin_lng`, `destination_lat`, `destination_lng`) VALUES
(18, 1, 'PO-696F93966FFD7', 'Derrick James', '1234556', 'Kim Dexter', '9821234', '47 Dona Pilar St. Villa Beatriz Matandang Balara, Quezon City, Metro Manila, 1119', 'Topaz, Novaliches, Quezon City, Metro Manila', 'LAND', 15.00, 'standard box', '123', 'CASH', '', 15.00, 0.00, 'SLA-24H', '1DAY', '2026-01-21', 'BOOKED', '2026-01-20 14:39:18', 0.0000000, 0.0000000, 0.0000000, 0.0000000),
(19, 1, 'PO-696F9FF614D07', 'Linsie Marie', '0987654', 'Marie Salazar', '9821234', 'Caloocan City', 'Antipolo City', 'LAND', 15.00, 'standard box', '123', 'CASH', '', 15.00, 350.00, 'SLA-24H', '1DAY', '2026-01-21', 'BOOKED', '2026-01-20 15:32:06', 0.0000000, 0.0000000, 0.0000000, 0.0000000),
(20, 1, 'PO-696FA07D13A12', 'Hesus', '0987654', 'Nolasco', '9821234', 'Bulacan City', 'San Mateo', 'LAND', 15.00, 'standard box', '123', 'CASH', '', 15.00, 350.00, 'SLA-24H', '1DAY', '2026-01-20', 'BOOKED', '2026-01-20 15:34:21', 0.0000000, 0.0000000, 0.0000000, 0.0000000),
(21, 1, 'PO-696FA812BBE7B', 'Samuel', '1234556', 'Manuel', '1241241221', 'Matandang Balara', 'Topaz, Novaliches, Quezon City, Metro Manila', 'LAND', 12.00, 'standard box', '123', 'CASH', '', 15.00, 350.00, 'SLA-24H', '1DAY', '2026-01-21', 'BOOKED', '2026-01-20 16:06:42', 0.0000000, 0.0000000, 0.0000000, 0.0000000),
(22, 1, 'PO-696FB107C3717', 'Linsie Marie', '0987654', 'Marie Salazar', '1233456', 'Metro Manila', 'Caloocan City', 'LAND', 20.00, 'standard box', '123', 'CASH', '', 0.00, 0.00, 'SLA-24H', '1DAY', '2026-01-21', 'BOOKED', '2026-01-20 16:44:55', 0.0000000, 0.0000000, 0.0000000, 0.0000000),
(23, 1, 'PO-696FB4575B433', 'Hesus', '1234556', 'Manuel', '1241241221', 'Novaliches', 'Valenzuela', 'LAND', 15.00, 'standard box', '123', 'CASH', '', 0.00, 350.00, 'SLA-24H', '1DAY', '2026-01-21', 'BOOKED', '2026-01-20 16:59:03', 0.0000000, 0.0000000, 0.0000000, 0.0000000);

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `shipment_id` int(11) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `shipment_code` varchar(50) DEFAULT NULL,
  `tracking_no` varchar(50) DEFAULT NULL,
  `transport_mode` enum('SEA','LAND','AIR') NOT NULL,
  `origin` text DEFAULT NULL,
  `destination` text DEFAULT NULL,
  `route_details` text DEFAULT NULL,
  `status` enum('BOOKED','CONSOLIDATED','READY_FOR_DISPATCH','IN_TRANSIT','ARRIVED','DELIVERED') DEFAULT 'BOOKED',
  `consolidated` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_lat` decimal(10,8) DEFAULT NULL,
  `current_lng` decimal(11,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipments`
--

INSERT INTO `shipments` (`shipment_id`, `po_id`, `shipment_code`, `tracking_no`, `transport_mode`, `origin`, `destination`, `route_details`, `status`, `consolidated`, `created_at`, `current_lat`, `current_lng`) VALUES
(19, 18, 'SHIP-696F93A08DF50', NULL, 'LAND', '47 Dona Pilar St. Villa Beatriz Matandang Balara, Quezon City, Metro Manila, 1119', 'Topaz, Novaliches, Quezon City, Metro Manila', NULL, 'DELIVERED', 1, '2026-01-20 14:39:28', NULL, NULL),
(20, 19, 'SHIP-696F9FF9B905D', NULL, 'LAND', 'Caloocan City', 'Antipolo City', NULL, 'ARRIVED', 1, '2026-01-20 15:32:09', NULL, NULL),
(21, 20, 'SHIP-696FA0814250A', NULL, 'LAND', 'Bulacan City', 'San Mateo', NULL, 'IN_TRANSIT', 1, '2026-01-20 15:34:25', NULL, NULL),
(22, 21, 'SHIP-696FA81EAF6CF', NULL, 'LAND', 'Matandang Balara', 'Topaz, Novaliches, Quezon City, Metro Manila', NULL, 'IN_TRANSIT', 1, '2026-01-20 16:06:54', NULL, NULL),
(23, 22, 'SHIP-696FB10EAB40C', NULL, 'LAND', 'Metro Manila', 'Caloocan City', NULL, 'IN_TRANSIT', 1, '2026-01-20 16:45:02', NULL, NULL),
(24, 23, 'SHIP-696FB45BDA538', NULL, 'LAND', 'Novaliches', 'Valenzuela', NULL, 'IN_TRANSIT', 1, '2026-01-20 16:59:07', NULL, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `shipment_timeline`
-- (See below for the actual view)
--
CREATE TABLE `shipment_timeline` (
`shipment_code` varchar(50)
,`status` varchar(50)
,`location` text
,`updated_at` timestamp
,`full_name` varchar(100)
);

-- --------------------------------------------------------

--
-- Table structure for table `shipment_tracking`
--

CREATE TABLE `shipment_tracking` (
  `tracking_id` int(11) NOT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `location` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','STAFF') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin User', 'admin@slate.com', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'ADMIN', '2026-01-11 08:49:38'),
(2, 'Staff User', 'staff@slate.com', '10176e7b7b24d317acfcf8d2064cfd2f24e154f7b5a96603077d5ef813d6a6b6', 'STAFF', '2026-01-11 08:49:38');

-- --------------------------------------------------------

--
-- Structure for view `shipment_timeline`
--
DROP TABLE IF EXISTS `shipment_timeline`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `shipment_timeline`  AS SELECT `s`.`shipment_code` AS `shipment_code`, `t`.`status` AS `status`, `t`.`location` AS `location`, `t`.`updated_at` AS `updated_at`, `u`.`full_name` AS `full_name` FROM ((`shipment_tracking` `t` join `shipments` `s` on(`t`.`shipment_id` = `s`.`shipment_id`)) left join `users` `u` on(`t`.`updated_by` = `u`.`user_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bills_of_lading`
--
ALTER TABLE `bills_of_lading`
  ADD PRIMARY KEY (`bl_id`),
  ADD UNIQUE KEY `bl_number` (`bl_number`);

--
-- Indexes for table `consolidations`
--
ALTER TABLE `consolidations`
  ADD PRIMARY KEY (`consolidation_id`),
  ADD UNIQUE KEY `consolidation_code` (`consolidation_code`),
  ADD UNIQUE KEY `idx_trip_no` (`trip_no`);

--
-- Indexes for table `consolidation_shipments`
--
ALTER TABLE `consolidation_shipments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `consolidation_id` (`consolidation_id`),
  ADD KEY `shipment_id` (`shipment_id`);

--
-- Indexes for table `deconsolidation_logs`
--
ALTER TABLE `deconsolidation_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hmbl`
--
ALTER TABLE `hmbl`
  ADD PRIMARY KEY (`hmbl_id`),
  ADD UNIQUE KEY `hmbl_no` (`hmbl_no`);

--
-- Indexes for table `hmbl_shipments`
--
ALTER TABLE `hmbl_shipments`
  ADD PRIMARY KEY (`hmbl_id`,`shipment_id`);

--
-- Indexes for table `house_bill_of_lading`
--
ALTER TABLE `house_bill_of_lading`
  ADD PRIMARY KEY (`hbl_id`),
  ADD UNIQUE KEY `hbl_number` (`hbl_number`),
  ADD KEY `shipment_id` (`shipment_id`);

--
-- Indexes for table `master_bill_of_lading`
--
ALTER TABLE `master_bill_of_lading`
  ADD PRIMARY KEY (`mbl_id`),
  ADD UNIQUE KEY `mbl_number` (`mbl_number`),
  ADD KEY `consolidation_id` (`consolidation_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `contract_number` (`contract_number`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`shipment_id`),
  ADD UNIQUE KEY `po_id` (`po_id`),
  ADD UNIQUE KEY `shipment_code` (`shipment_code`);

--
-- Indexes for table `shipment_tracking`
--
ALTER TABLE `shipment_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `shipment_id` (`shipment_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bills_of_lading`
--
ALTER TABLE `bills_of_lading`
  MODIFY `bl_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consolidations`
--
ALTER TABLE `consolidations`
  MODIFY `consolidation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `consolidation_shipments`
--
ALTER TABLE `consolidation_shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `deconsolidation_logs`
--
ALTER TABLE `deconsolidation_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `hmbl`
--
ALTER TABLE `hmbl`
  MODIFY `hmbl_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `house_bill_of_lading`
--
ALTER TABLE `house_bill_of_lading`
  MODIFY `hbl_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_bill_of_lading`
--
ALTER TABLE `master_bill_of_lading`
  MODIFY `mbl_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `shipment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `shipment_tracking`
--
ALTER TABLE `shipment_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `consolidation_shipments`
--
ALTER TABLE `consolidation_shipments`
  ADD CONSTRAINT `consolidation_shipments_ibfk_1` FOREIGN KEY (`consolidation_id`) REFERENCES `consolidations` (`consolidation_id`),
  ADD CONSTRAINT `consolidation_shipments_ibfk_2` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`shipment_id`);

--
-- Constraints for table `house_bill_of_lading`
--
ALTER TABLE `house_bill_of_lading`
  ADD CONSTRAINT `house_bill_of_lading_ibfk_1` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`shipment_id`);

--
-- Constraints for table `master_bill_of_lading`
--
ALTER TABLE `master_bill_of_lading`
  ADD CONSTRAINT `master_bill_of_lading_ibfk_1` FOREIGN KEY (`consolidation_id`) REFERENCES `consolidations` (`consolidation_id`);

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_shipments_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`);

--
-- Constraints for table `shipment_tracking`
--
ALTER TABLE `shipment_tracking`
  ADD CONSTRAINT `shipment_tracking_ibfk_1` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`shipment_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
