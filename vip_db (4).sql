-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jan 29, 2026 at 05:45 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vip_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_receivable`
--

CREATE TABLE `account_receivable` (
  `AR_ID` int UNSIGNED NOT NULL,
  `Sale_ID` int UNSIGNED NOT NULL,
  `Customer_ID` int UNSIGNED NOT NULL,
  `amount_due` decimal(12,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `invoice_amount` decimal(12,2) DEFAULT NULL,
  `opening_balance` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `account_receivable`
--

INSERT INTO `account_receivable` (`AR_ID`, `Sale_ID`, `Customer_ID`, `amount_due`, `due_date`, `status`, `invoice_date`, `invoice_amount`, `opening_balance`, `created_at`, `updated_at`) VALUES
(3, 8, 3, 1500.00, '2026-03-01', 'Partial', '2026-01-30', 5000.00, 2000.00, NULL, '2026-01-29 17:43:15'),
(4, 9, 4, 3500.00, '2026-03-01', 'Open', '2026-01-30', 3500.00, 3500.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `Log_ID` int UNSIGNED NOT NULL,
  `User_ID` int UNSIGNED NOT NULL,
  `Activity` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adjustment_details`
--

CREATE TABLE `adjustment_details` (
  `AdjustmentDetail_ID` int UNSIGNED NOT NULL,
  `Product_ID` int UNSIGNED NOT NULL,
  `Adjustment_ID` int UNSIGNED NOT NULL,
  `old_quantity` int DEFAULT NULL,
  `new_quantity` int DEFAULT NULL,
  `adjustment_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `adjustment_details`
--

INSERT INTO `adjustment_details` (`AdjustmentDetail_ID`, `Product_ID`, `Adjustment_ID`, `old_quantity`, `new_quantity`, `adjustment_type`, `reason`, `created_at`, `updated_at`) VALUES
(3, 3, 3, 32, 30, 'decrease', 'Correction', NULL, NULL),
(4, 1, 4, 1, 6, 'increase', 'Correction', NULL, NULL),
(5, 6, 5, 3, 2, 'decrease', 'Correction', NULL, NULL),
(6, 7, 6, 8, 5, 'decrease', 'Correction', NULL, NULL),
(7, 7, 7, 5, 2, 'decrease', 'Correction', NULL, NULL),
(8, 7, 8, 2, -1, 'decrease', 'Correction', NULL, NULL),
(9, 4, 9, 5, 6, 'increase', 'Correction', NULL, NULL),
(10, 4, 10, 6, 7, 'increase', 'Correction', NULL, NULL),
(11, 3, 11, 35, 36, 'increase', 'Other', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `app_users`
--

CREATE TABLE `app_users` (
  `User_ID` int UNSIGNED NOT NULL,
  `user_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Role_ID` int UNSIGNED DEFAULT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_user_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `app_users`
--

INSERT INTO `app_users` (`User_ID`, `user_name`, `password`, `Role_ID`, `is_active`, `status`, `linked_user_id`, `created_at`, `updated_at`) VALUES
(3, 'amdad', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 1, 1, 'active', NULL, '2026-01-22 19:15:44', '2026-01-22 19:15:44');

-- --------------------------------------------------------

--
-- Table structure for table `ar_payment`
--

CREATE TABLE `ar_payment` (
  `payment_ID` int UNSIGNED NOT NULL,
  `payment_date` date DEFAULT NULL,
  `amount_paid` decimal(12,2) DEFAULT NULL,
  `remaining_balance` decimal(12,2) DEFAULT NULL,
  `collected_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ar_payment`
--

INSERT INTO `ar_payment` (`payment_ID`, `payment_date`, `amount_paid`, `remaining_balance`, `collected_by`, `created_at`, `updated_at`) VALUES
(3, '2026-01-30', 500.00, 1500.00, 3, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `ar_retry_attempt`
--

CREATE TABLE `ar_retry_attempt` (
  `Retry_ID` int UNSIGNED NOT NULL,
  `Payment_ID` int UNSIGNED NOT NULL,
  `retried_by` int UNSIGNED DEFAULT NULL,
  `attempt_no` int DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `Customer_ID` int UNSIGNED NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`Customer_ID`, `type`, `phone_number`, `address`, `created_at`, `updated_at`, `customer_name`) VALUES
(1, 'Regular', '090872745609', 'Gusa Purok 3', NULL, NULL, 'Tarik James Amdad'),
(2, 'VIP', '09087242131', 'Tablon', NULL, NULL, 'James bond'),
(3, 'Wholesale', '09087274509', 'Villianueva', NULL, NULL, 'Asado Shoopaw'),
(4, 'Wholesale', '0931231334', 'El salvador', NULL, NULL, 'Big papa'),
(5, 'VIP', '0908712131', 'Jasaan', NULL, NULL, 'Midorima'),
(7, 'Regular', 'N/A', '', NULL, NULL, 'Walk-in Customer');

-- --------------------------------------------------------

--
-- Table structure for table `damage_goods`
--

CREATE TABLE `damage_goods` (
  `Damage_ID` int UNSIGNED NOT NULL,
  `Inventory_ID` int UNSIGNED NOT NULL,
  `Adjustment_ID` int UNSIGNED NOT NULL,
  `quantity` int DEFAULT NULL,
  `reported_by` int UNSIGNED DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `damage_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery`
--

CREATE TABLE `delivery` (
  `Delivery_ID` int UNSIGNED NOT NULL,
  `Order_ID` int UNSIGNED NOT NULL,
  `delivery_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schedule_date` date DEFAULT NULL,
  `actual_date_arrived` date DEFAULT NULL,
  `delivery_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivered_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivered_to` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delivery`
--

INSERT INTO `delivery` (`Delivery_ID`, `Order_ID`, `delivery_address`, `schedule_date`, `actual_date_arrived`, `delivery_status`, `delivered_by`, `delivered_to`, `created_at`, `updated_at`) VALUES
(1, 5, '', NULL, NULL, 'Scheduled', 'Joyboy', 'Asado Shoopaw', NULL, NULL),
(2, 6, '', NULL, '2026-01-26', 'Delivered', 'james', 'Tarik James Amdad', NULL, '2026-01-25 17:29:53'),
(3, 3, '', NULL, NULL, 'Scheduled', 'al james', 'Tarik James Amdad', NULL, NULL),
(4, 2, '', NULL, NULL, 'Out for Delivery', 'james o', 'James bond', NULL, '2026-01-28 09:00:16'),
(5, 7, 'tablon', NULL, NULL, 'Scheduled', 'Joyboy', 'Tarik James Amdad', NULL, NULL),
(6, 8, '', '2026-01-26', NULL, 'Scheduled', 'small papa', 'Big papa', NULL, NULL),
(7, 9, 'Cagayan De Oro', '2026-01-28', NULL, 'Scheduled', 'small papa', 'Asado Shoopaw', NULL, NULL),
(8, 10, '', NULL, '2026-01-26', 'Delivered', 'small papa', 'Midorima', NULL, '2026-01-25 18:09:40'),
(9, 11, 'Cagayan De Oro', '2026-01-30', NULL, 'Scheduled', 'Joyboy', 'Asado Shoopaw', NULL, NULL),
(10, 12, '', '2026-01-29', NULL, 'Scheduled', 'Joyboy', 'James bond', NULL, NULL),
(11, 13, '', '2026-01-29', '2026-01-28', 'Delivered', 'james', 'Asado Shoopaw', NULL, '2026-01-28 07:53:16'),
(12, 14, '', NULL, '2026-01-28', 'Delivered', 'Papa jhons', 'Big papa', NULL, '2026-01-28 09:04:35');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_detail`
--

CREATE TABLE `delivery_detail` (
  `Delivery_Detail_ID` int UNSIGNED NOT NULL,
  `Delivery_ID` int UNSIGNED NOT NULL,
  `Order_detail_ID` int UNSIGNED NOT NULL,
  `Damage_ID` int UNSIGNED DEFAULT NULL,
  `received_qty` int DEFAULT NULL,
  `damage_qty` int DEFAULT NULL,
  `remarks` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','out_for_delivery','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delivery_detail`
--

INSERT INTO `delivery_detail` (`Delivery_Detail_ID`, `Delivery_ID`, `Order_detail_ID`, `Damage_ID`, `received_qty`, `damage_qty`, `remarks`, `created_at`, `updated_at`, `status`) VALUES
(1, 4, 1, NULL, 20, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(2, 3, 2, NULL, 20, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(3, 1, 4, NULL, 30, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(4, 2, 5, NULL, 20, 0, NULL, '2026-01-27 09:54:04', '2026-01-28 08:38:41', 'delivered'),
(5, 5, 6, NULL, 20, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(6, 6, 7, NULL, 20, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(7, 6, 8, NULL, 30, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(8, 6, 9, NULL, 10, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(9, 7, 10, NULL, 10, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(10, 8, 11, NULL, 10, 0, NULL, '2026-01-27 09:54:04', '2026-01-28 07:48:47', 'delivered'),
(11, 9, 12, NULL, 50, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(12, 10, 13, NULL, 10, 0, NULL, '2026-01-27 09:54:04', '2026-01-27 09:54:04', 'pending'),
(16, 11, 14, NULL, 10, 0, NULL, '2026-01-28 08:08:22', '2026-01-28 08:08:33', 'delivered'),
(17, 12, 15, NULL, 10, 0, NULL, '2026-01-28 09:04:08', '2026-01-28 09:05:34', 'delivered'),
(18, 12, 16, NULL, 30, 0, NULL, '2026-01-28 09:04:08', '2026-01-28 09:05:34', 'delivered'),
(19, 12, 17, NULL, 5, 0, NULL, '2026-01-28 09:04:08', '2026-01-28 09:05:34', 'delivered'),
(20, 12, 18, NULL, 10, 0, NULL, '2026-01-28 09:04:08', '2026-01-28 09:05:34', 'delivered');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_adjustment`
--

CREATE TABLE `manual_adjustment` (
  `Adjustment_ID` int UNSIGNED NOT NULL,
  `adjustment_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `manual_adjustment`
--

INSERT INTO `manual_adjustment` (`Adjustment_ID`, `adjustment_date`, `notes`, `created_at`, `updated_at`, `created_by`) VALUES
(3, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 00:05:17', NULL, 3),
(4, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 00:05:59', NULL, 3),
(5, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 00:37:29', NULL, 3),
(6, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 01:18:23', NULL, 3),
(7, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 01:24:25', NULL, 3),
(8, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 01:25:34', NULL, 3),
(9, '2026-01-23', 'Manual inventory adjustment', '2026-01-24 01:25:52', NULL, 3),
(10, '2026-01-24', 'Manual inventory adjustment', '2026-01-24 01:29:06', NULL, 3),
(11, '2026-01-24', 'Manual inventory adjustment', '2026-01-24 01:42:46', NULL, 3);

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(5, '2014_10_12_000000_create_users_table', 1),
(6, '2014_10_12_100000_create_password_resets_table', 1),
(7, '2019_08_19_000000_create_failed_jobs_table', 1),
(8, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(9, '2026_01_22_000001_create_roles_table', 2),
(10, '2026_01_22_000002_create_app_users_table', 3),
(11, '2026_01_22_000003_create_products_table', 4),
(13, '2026_01_22_000004_create_productions_table', 5),
(14, '2026_01_22_000005_create_activity_logs_table', 6),
(15, '2026_01_22_000006_create_customers_table', 7),
(16, '2026_01_22_000007_create_stockin_inventory_table', 7),
(17, '2026_01_22_000008_create_sales_table', 7),
(18, '2026_01_22_000009_create_sale_details_table', 7),
(19, '2026_01_22_000010_create_orders_table', 8),
(20, '2026_01_22_000011_create_order_details_table', 8),
(21, '2026_01_22_000012_create_delivery_table', 9),
(22, '2026_01_22_000013_create_sale_source_table', 9),
(23, '2026_01_22_000014_create_delivery_detail_table', 9),
(24, '2026_01_22_000015_create_manual_adjustment_table', 10),
(25, '2026_01_22_000016_create_adjustment_details_table', 10),
(26, '2026_01_22_000017_create_damage_goods_table', 10),
(27, '2026_01_22_000018_create_account_receivable_table', 10),
(28, '2026_01_22_000019_create_ar_payment_table', 10),
(29, '2026_01_22_000020_create_singil_table', 10),
(30, '2026_01_22_000021_create_ar_retry_attempt_table', 10),
(31, '2026_01_23_000001_add_performance_indexes', 11);

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `Order_ID` int UNSIGNED NOT NULL,
  `Customer_ID` int UNSIGNED NOT NULL,
  `Sale_ID` int UNSIGNED DEFAULT NULL,
  `order_date` datetime DEFAULT NULL,
  `order_status` enum('pending','Confirmed','Scheduled for Delivery','Out for Delivery','delivered','Completed','Cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `total_amount` decimal(12,2) DEFAULT NULL,
  `remarks` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`Order_ID`, `Customer_ID`, `Sale_ID`, `order_date`, `order_status`, `total_amount`, `remarks`, `created_at`, `updated_at`) VALUES
(2, 2, NULL, '2026-01-26 00:00:00', 'Out for Delivery', 600.00, '', NULL, '2026-01-28 09:00:16'),
(3, 1, NULL, '2026-01-25 00:00:00', 'Cancelled', 400.00, '', NULL, '2026-01-25 17:04:10'),
(4, 2, NULL, '2026-01-25 00:00:00', 'Scheduled for Delivery', 300.00, '', NULL, '2026-01-28 09:00:01'),
(5, 3, NULL, '2026-01-26 00:00:00', 'pending', 300.00, '', NULL, NULL),
(6, 1, 6, '2026-01-26 00:00:00', 'Completed', 2000.00, '', NULL, '2026-01-28 08:38:41'),
(7, 1, NULL, '2026-01-26 00:00:00', 'Cancelled', 400.00, '', NULL, '2026-01-26 19:44:12'),
(8, 4, NULL, '2026-01-26 00:00:00', 'pending', 2100.00, '', NULL, NULL),
(9, 3, NULL, '2026-01-26 00:00:00', 'Scheduled for Delivery', 1000.00, '', NULL, '2026-01-26 16:33:37'),
(10, 5, 2, '2026-01-26 00:00:00', 'Completed', 150.00, '', NULL, '2026-01-28 07:48:47'),
(11, 3, NULL, '2026-01-27 00:00:00', 'pending', 500.00, '', NULL, NULL),
(12, 2, NULL, '2026-01-27 00:00:00', 'Cancelled', 1000.00, '', NULL, '2026-01-27 05:24:44'),
(13, 3, 5, '2026-01-28 00:00:00', 'Completed', 300.00, '', NULL, '2026-01-28 08:08:33'),
(14, 4, 7, '2026-01-28 00:00:00', 'Completed', 1300.00, '', NULL, '2026-01-28 09:05:34');

-- --------------------------------------------------------

--
-- Table structure for table `order_details`
--

CREATE TABLE `order_details` (
  `Order_detail_ID` int UNSIGNED NOT NULL,
  `Order_ID` int UNSIGNED NOT NULL,
  `Product_ID` int UNSIGNED NOT NULL,
  `ordered_qty` int DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_details`
--

INSERT INTO `order_details` (`Order_detail_ID`, `Order_ID`, `Product_ID`, `ordered_qty`, `unit_price`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 20, 30.00, NULL, NULL),
(2, 3, 7, 20, 20.00, NULL, NULL),
(3, 4, 3, 30, 10.00, NULL, NULL),
(4, 5, 3, 30, 10.00, NULL, NULL),
(5, 6, 4, 20, 100.00, NULL, NULL),
(6, 7, 7, 20, 20.00, NULL, NULL),
(7, 8, 3, 20, 10.00, NULL, NULL),
(8, 8, 6, 30, 30.00, NULL, NULL),
(9, 8, 4, 10, 100.00, NULL, NULL),
(10, 9, 4, 10, 100.00, NULL, NULL),
(11, 10, 3, 10, 15.00, NULL, NULL),
(12, 11, 3, 50, 10.00, NULL, NULL),
(13, 12, 4, 10, 100.00, NULL, NULL),
(14, 13, 1, 10, 30.00, NULL, NULL),
(15, 14, 7, 10, 20.00, NULL, NULL),
(16, 14, 3, 30, 10.00, NULL, NULL),
(17, 14, 4, 5, 100.00, NULL, NULL),
(18, 14, 6, 10, 30.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productions`
--

CREATE TABLE `productions` (
  `Production_ID` int UNSIGNED NOT NULL,
  `Product_ID` int UNSIGNED NOT NULL,
  `production_type` enum('stockin','orders') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stockin',
  `date` date DEFAULT NULL,
  `produced_qty` int DEFAULT NULL,
  `production_date` date DEFAULT NULL,
  `status` enum('produced','in_storage','ready_for_delivery','delivered','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'produced',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `Order_ID` int UNSIGNED DEFAULT NULL,
  `bag_size` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bag_size_unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'kg',
  `number_of_bags` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `productions`
--

INSERT INTO `productions` (`Production_ID`, `Product_ID`, `production_type`, `date`, `produced_qty`, `production_date`, `status`, `notes`, `created_by`, `Order_ID`, `bag_size`, `bag_size_unit`, `number_of_bags`) VALUES
(1, 3, 'stockin', NULL, 20, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(2, 3, 'stockin', NULL, 10, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(3, 3, 'stockin', NULL, 1, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(4, 3, 'stockin', NULL, 1, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(5, 1, 'stockin', NULL, 1, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(6, 3, 'stockin', NULL, 5, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(7, 4, 'stockin', NULL, 5, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(8, 6, 'stockin', NULL, 3, '2026-01-24', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(9, 7, 'stockin', NULL, 3, '2026-01-23', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(10, 7, 'stockin', NULL, 5, '2026-01-24', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(11, 3, 'stockin', NULL, 1, '2026-01-24', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(12, 7, 'stockin', NULL, 23, '2026-01-26', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(13, 6, 'stockin', NULL, 5, '2026-01-26', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(14, 6, 'stockin', NULL, 3, '2026-01-26', 'produced', NULL, 3, NULL, NULL, 'kg', NULL),
(15, 1, 'orders', NULL, 10, '2026-01-27', 'produced', NULL, 3, 10, '5', 'kg', 2),
(16, 1, 'orders', NULL, 10, '2026-01-27', 'produced', NULL, 3, 9, '1', 'kg', 10),
(17, 4, 'stockin', NULL, 20, '2026-01-27', 'produced', NULL, 3, NULL, '1', 'kg', 20),
(18, 4, 'orders', NULL, 10, '2026-01-27', 'produced', NULL, 3, 8, NULL, 'kg', 10),
(19, 3, 'orders', NULL, 20, '2026-01-27', 'produced', NULL, 3, 8, NULL, 'kg', 20),
(20, 7, 'stockin', NULL, 10, '2026-01-27', 'produced', NULL, 3, NULL, NULL, 'kg', 10),
(21, 7, 'stockin', NULL, 10, '2026-01-27', 'produced', NULL, 3, NULL, NULL, 'kg', 10),
(22, 4, 'orders', NULL, 10, '2026-01-27', 'produced', NULL, 3, 12, NULL, 'kg', 10),
(23, 7, 'stockin', NULL, 20, '2026-01-27', 'produced', NULL, 3, NULL, NULL, '0', 20),
(24, 1, 'stockin', NULL, 5, '2026-01-27', 'produced', NULL, 3, NULL, NULL, 'kg', 5),
(25, 6, 'stockin', NULL, 56, '2026-01-27', 'produced', NULL, 3, NULL, NULL, 'kg', 56),
(26, 4, 'orders', NULL, 10, '2026-01-27', 'produced', NULL, 3, 12, NULL, 'kg', 10),
(27, 4, 'stockin', NULL, 3, '2026-01-27', 'produced', NULL, 3, NULL, NULL, 'kg', 3),
(28, 3, 'orders', NULL, 50, '2026-01-27', 'produced', NULL, 3, 11, NULL, 'kg', 50);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `Product_ID` int UNSIGNED NOT NULL,
  `product_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `form` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wholesale_price` decimal(10,2) DEFAULT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `is_discontinued` tinyint NOT NULL DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_update` datetime DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`Product_ID`, `product_name`, `form`, `unit`, `wholesale_price`, `retail_price`, `is_discontinued`, `description`, `last_update`, `created_date`, `created_at`, `updated_at`) VALUES
(1, 'Ice', 'Crushed', '1KG', 30.00, 40.00, 0, '', NULL, '2026-01-23 03:23:13', NULL, NULL),
(3, 'Ice', 'Crushed', '70G', 10.00, 15.00, 0, '', NULL, '2026-01-23 03:31:33', NULL, NULL),
(4, 'Ice', 'Cubes', 'Block', 100.00, 120.00, 0, 'per block', NULL, '2026-01-23 03:33:49', NULL, NULL),
(6, 'Ice', 'Cubes', '5kg', 30.00, 35.00, 0, '', NULL, '2026-01-23 21:18:45', NULL, NULL),
(7, 'Ice', 'Crushed', '10KG', 20.00, 30.00, 0, '', NULL, '2026-01-24 01:15:45', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `Role_ID` int UNSIGNED NOT NULL,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`Role_ID`, `role_name`, `role_description`, `created_at`, `updated_at`) VALUES
(1, 'Owner', 'System owner', '2026-01-22 19:15:25', '2026-01-22 19:15:25'),
(2, 'Manager', 'Manages operations', '2026-01-22 19:15:25', '2026-01-22 19:15:25'),
(3, 'Cashier', 'Handles sales', '2026-01-22 19:15:25', '2026-01-22 19:15:25');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `Sale_ID` int UNSIGNED NOT NULL,
  `User_ID` int UNSIGNED NOT NULL,
  `Customer_ID` int UNSIGNED NOT NULL,
  `sale_date` datetime DEFAULT NULL,
  `sale_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `payment` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`Sale_ID`, `User_ID`, `Customer_ID`, `sale_date`, `sale_type`, `total_amount`, `created_by`, `payment`, `created_at`, `updated_at`) VALUES
(2, 3, 5, NULL, NULL, NULL, 3, NULL, '2026-01-28 07:48:47', NULL),
(3, 3, 7, NULL, NULL, NULL, 3, NULL, '2026-01-28 07:49:18', NULL),
(4, 3, 7, NULL, NULL, NULL, 3, NULL, '2026-01-28 07:50:49', NULL),
(5, 3, 3, NULL, NULL, NULL, 3, NULL, '2026-01-28 08:08:33', NULL),
(6, 3, 1, NULL, NULL, NULL, 3, NULL, '2026-01-28 08:38:41', NULL),
(7, 3, 4, NULL, NULL, NULL, 3, NULL, '2026-01-28 09:05:34', NULL),
(8, 3, 1, NULL, NULL, NULL, 3, NULL, NULL, NULL),
(9, 3, 1, NULL, NULL, NULL, 3, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_details`
--

CREATE TABLE `sale_details` (
  `Sale_detail_ID` int UNSIGNED NOT NULL,
  `Sale_ID` int UNSIGNED NOT NULL,
  `Product_ID` int UNSIGNED NOT NULL,
  `quantity` int DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sale_details`
--

INSERT INTO `sale_details` (`Sale_detail_ID`, `Sale_ID`, `Product_ID`, `quantity`, `unit_price`, `subtotal`, `created_at`, `updated_at`) VALUES
(2, 2, 3, 10, 15.00, 150.00, NULL, NULL),
(3, 3, 1, 10, 40.00, 400.00, NULL, NULL),
(4, 4, 1, 1, 40.00, 40.00, NULL, NULL),
(5, 5, 1, 10, 30.00, 300.00, NULL, NULL),
(6, 6, 4, 20, 100.00, 2000.00, NULL, NULL),
(7, 7, 7, 10, 20.00, 200.00, NULL, NULL),
(8, 7, 3, 30, 10.00, 300.00, NULL, NULL),
(9, 7, 4, 5, 100.00, 500.00, NULL, NULL),
(10, 7, 6, 10, 30.00, 300.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_source`
--

CREATE TABLE `sale_source` (
  `Sale_delivery_ID` int UNSIGNED NOT NULL,
  `Delivery_ID` int UNSIGNED NOT NULL,
  `Sale_ID` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sale_source`
--

INSERT INTO `sale_source` (`Sale_delivery_ID`, `Delivery_ID`, `Sale_ID`, `created_at`, `updated_at`) VALUES
(2, 8, 2, NULL, NULL),
(3, 11, 5, NULL, NULL),
(4, 2, 6, NULL, NULL),
(5, 12, 7, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `singil`
--

CREATE TABLE `singil` (
  `Singl_ID` int UNSIGNED NOT NULL,
  `AR_ID` int UNSIGNED NOT NULL,
  `Payment_ID` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `singil`
--

INSERT INTO `singil` (`Singl_ID`, `AR_ID`, `Payment_ID`, `created_at`, `updated_at`) VALUES
(1, 3, 3, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `stockin_inventory`
--

CREATE TABLE `stockin_inventory` (
  `Inventory_ID` int UNSIGNED NOT NULL,
  `Product_ID` int UNSIGNED NOT NULL,
  `Production_ID` int UNSIGNED DEFAULT NULL,
  `date_in` date DEFAULT NULL,
  `handled_by` int UNSIGNED DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `storage_limit` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stockin_inventory`
--

INSERT INTO `stockin_inventory` (`Inventory_ID`, `Product_ID`, `Production_ID`, `date_in`, `handled_by`, `quantity`, `storage_limit`, `created_at`, `updated_at`) VALUES
(1, 3, 1, '2026-01-23', 3, 67, 1000, NULL, '2026-01-28 09:05:34'),
(2, 1, 5, '2026-01-23', 3, 10, 1000, NULL, '2026-01-28 08:08:33'),
(3, 4, 7, '2026-01-23', 3, 35, 1000, NULL, '2026-01-28 09:05:34'),
(4, 6, 8, '2026-01-24', 3, 56, 1000, NULL, '2026-01-28 09:05:34'),
(5, 7, 9, '2026-01-23', 3, 52, 1000, NULL, '2026-01-28 09:05:34');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_receivable`
--
ALTER TABLE `account_receivable`
  ADD PRIMARY KEY (`AR_ID`),
  ADD KEY `idx_ar_status_due_date` (`status`,`due_date`),
  ADD KEY `idx_ar_status` (`status`),
  ADD KEY `idx_ar_due_date` (`due_date`),
  ADD KEY `idx_ar_customer_id` (`Customer_ID`),
  ADD KEY `idx_ar_sale_id` (`Sale_ID`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`Log_ID`),
  ADD KEY `activity_logs_user_id_foreign` (`User_ID`);

--
-- Indexes for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD PRIMARY KEY (`AdjustmentDetail_ID`),
  ADD KEY `adjustment_details_product_id_foreign` (`Product_ID`),
  ADD KEY `adjustment_details_adjustment_id_foreign` (`Adjustment_ID`);

--
-- Indexes for table `app_users`
--
ALTER TABLE `app_users`
  ADD PRIMARY KEY (`User_ID`),
  ADD KEY `app_users_linked_user_id_foreign` (`linked_user_id`),
  ADD KEY `idx_app_users_role_id` (`Role_ID`),
  ADD KEY `idx_app_users_active` (`is_active`),
  ADD KEY `idx_app_users_active_role` (`is_active`,`Role_ID`);

--
-- Indexes for table `ar_payment`
--
ALTER TABLE `ar_payment`
  ADD PRIMARY KEY (`payment_ID`),
  ADD KEY `ar_payment_collected_by_foreign` (`collected_by`);

--
-- Indexes for table `ar_retry_attempt`
--
ALTER TABLE `ar_retry_attempt`
  ADD PRIMARY KEY (`Retry_ID`),
  ADD KEY `ar_retry_attempt_payment_id_foreign` (`Payment_ID`),
  ADD KEY `ar_retry_attempt_retried_by_foreign` (`retried_by`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`Customer_ID`),
  ADD KEY `idx_customers_created_at` (`created_at`),
  ADD KEY `idx_customers_type` (`type`);

--
-- Indexes for table `damage_goods`
--
ALTER TABLE `damage_goods`
  ADD PRIMARY KEY (`Damage_ID`),
  ADD KEY `damage_goods_inventory_id_foreign` (`Inventory_ID`),
  ADD KEY `damage_goods_adjustment_id_foreign` (`Adjustment_ID`),
  ADD KEY `damage_goods_reported_by_foreign` (`reported_by`);

--
-- Indexes for table `delivery`
--
ALTER TABLE `delivery`
  ADD PRIMARY KEY (`Delivery_ID`),
  ADD KEY `delivery_order_id_foreign` (`Order_ID`),
  ADD KEY `idx_delivery_status` (`delivery_status`),
  ADD KEY `idx_delivery_schedule_date` (`schedule_date`),
  ADD KEY `idx_delivery_status_schedule` (`delivery_status`,`schedule_date`);

--
-- Indexes for table `delivery_detail`
--
ALTER TABLE `delivery_detail`
  ADD PRIMARY KEY (`Delivery_Detail_ID`),
  ADD KEY `delivery_detail_delivery_id_foreign` (`Delivery_ID`),
  ADD KEY `delivery_detail_order_detail_id_foreign` (`Order_detail_ID`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `manual_adjustment`
--
ALTER TABLE `manual_adjustment`
  ADD PRIMARY KEY (`Adjustment_ID`),
  ADD KEY `manual_adjustment_created_by_foreign` (`created_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`Order_ID`),
  ADD KEY `orders_sale_id_foreign` (`Sale_ID`),
  ADD KEY `idx_orders_order_date` (`order_date`),
  ADD KEY `idx_orders_status` (`order_status`),
  ADD KEY `idx_orders_status_date` (`order_status`,`order_date`),
  ADD KEY `idx_orders_customer_id` (`Customer_ID`);

--
-- Indexes for table `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`Order_detail_ID`),
  ADD KEY `order_details_order_id_foreign` (`Order_ID`),
  ADD KEY `idx_order_details_product_id` (`Product_ID`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `productions`
--
ALTER TABLE `productions`
  ADD PRIMARY KEY (`Production_ID`),
  ADD KEY `productions_created_by_foreign` (`created_by`),
  ADD KEY `idx_productions_product_id` (`Product_ID`),
  ADD KEY `idx_productions_production_date` (`production_date`),
  ADD KEY `fk_productions_order` (`Order_ID`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`Product_ID`),
  ADD KEY `idx_products_discontinued` (`is_discontinued`),
  ADD KEY `idx_products_name` (`product_name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`Role_ID`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`Sale_ID`),
  ADD KEY `sales_created_by_foreign` (`created_by`),
  ADD KEY `idx_sales_sale_date` (`sale_date`),
  ADD KEY `idx_sales_date_amount` (`sale_date`,`total_amount`),
  ADD KEY `idx_sales_user_id` (`User_ID`),
  ADD KEY `idx_sales_customer_id` (`Customer_ID`),
  ADD KEY `idx_sales_payment` (`payment`);

--
-- Indexes for table `sale_details`
--
ALTER TABLE `sale_details`
  ADD PRIMARY KEY (`Sale_detail_ID`),
  ADD KEY `sale_details_sale_id_foreign` (`Sale_ID`),
  ADD KEY `idx_sale_details_product_id` (`Product_ID`);

--
-- Indexes for table `sale_source`
--
ALTER TABLE `sale_source`
  ADD PRIMARY KEY (`Sale_delivery_ID`),
  ADD KEY `sale_source_delivery_id_foreign` (`Delivery_ID`),
  ADD KEY `sale_source_sale_id_foreign` (`Sale_ID`);

--
-- Indexes for table `singil`
--
ALTER TABLE `singil`
  ADD PRIMARY KEY (`Singl_ID`),
  ADD KEY `singil_ar_id_foreign` (`AR_ID`),
  ADD KEY `singil_payment_id_foreign` (`Payment_ID`);

--
-- Indexes for table `stockin_inventory`
--
ALTER TABLE `stockin_inventory`
  ADD PRIMARY KEY (`Inventory_ID`),
  ADD KEY `stockin_inventory_production_id_foreign` (`Production_ID`),
  ADD KEY `stockin_inventory_handled_by_foreign` (`handled_by`),
  ADD KEY `idx_inventory_product_id` (`Product_ID`),
  ADD KEY `idx_inventory_product_date` (`Product_ID`,`date_in`),
  ADD KEY `idx_inventory_date_in` (`date_in`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_receivable`
--
ALTER TABLE `account_receivable`
  MODIFY `AR_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `Log_ID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  MODIFY `AdjustmentDetail_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `app_users`
--
ALTER TABLE `app_users`
  MODIFY `User_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ar_payment`
--
ALTER TABLE `ar_payment`
  MODIFY `payment_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `ar_retry_attempt`
--
ALTER TABLE `ar_retry_attempt`
  MODIFY `Retry_ID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `Customer_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `damage_goods`
--
ALTER TABLE `damage_goods`
  MODIFY `Damage_ID` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery`
--
ALTER TABLE `delivery`
  MODIFY `Delivery_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `delivery_detail`
--
ALTER TABLE `delivery_detail`
  MODIFY `Delivery_Detail_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_adjustment`
--
ALTER TABLE `manual_adjustment`
  MODIFY `Adjustment_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `Order_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `order_details`
--
ALTER TABLE `order_details`
  MODIFY `Order_detail_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `productions`
--
ALTER TABLE `productions`
  MODIFY `Production_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `Product_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `Role_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `Sale_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sale_details`
--
ALTER TABLE `sale_details`
  MODIFY `Sale_detail_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sale_source`
--
ALTER TABLE `sale_source`
  MODIFY `Sale_delivery_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `singil`
--
ALTER TABLE `singil`
  MODIFY `Singl_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `stockin_inventory`
--
ALTER TABLE `stockin_inventory`
  MODIFY `Inventory_ID` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_receivable`
--
ALTER TABLE `account_receivable`
  ADD CONSTRAINT `account_receivable_customer_id_foreign` FOREIGN KEY (`Customer_ID`) REFERENCES `customers` (`Customer_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `account_receivable_sale_id_foreign` FOREIGN KEY (`Sale_ID`) REFERENCES `sales` (`Sale_ID`) ON DELETE CASCADE;

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`User_ID`) REFERENCES `app_users` (`User_ID`) ON DELETE CASCADE;

--
-- Constraints for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD CONSTRAINT `adjustment_details_adjustment_id_foreign` FOREIGN KEY (`Adjustment_ID`) REFERENCES `manual_adjustment` (`Adjustment_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `adjustment_details_product_id_foreign` FOREIGN KEY (`Product_ID`) REFERENCES `products` (`Product_ID`) ON DELETE CASCADE;

--
-- Constraints for table `app_users`
--
ALTER TABLE `app_users`
  ADD CONSTRAINT `app_users_linked_user_id_foreign` FOREIGN KEY (`linked_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `app_users_role_id_foreign` FOREIGN KEY (`Role_ID`) REFERENCES `roles` (`Role_ID`) ON DELETE SET NULL;

--
-- Constraints for table `ar_payment`
--
ALTER TABLE `ar_payment`
  ADD CONSTRAINT `ar_payment_collected_by_foreign` FOREIGN KEY (`collected_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL;

--
-- Constraints for table `ar_retry_attempt`
--
ALTER TABLE `ar_retry_attempt`
  ADD CONSTRAINT `ar_retry_attempt_payment_id_foreign` FOREIGN KEY (`Payment_ID`) REFERENCES `ar_payment` (`payment_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `ar_retry_attempt_retried_by_foreign` FOREIGN KEY (`retried_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL;

--
-- Constraints for table `damage_goods`
--
ALTER TABLE `damage_goods`
  ADD CONSTRAINT `damage_goods_adjustment_id_foreign` FOREIGN KEY (`Adjustment_ID`) REFERENCES `manual_adjustment` (`Adjustment_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `damage_goods_inventory_id_foreign` FOREIGN KEY (`Inventory_ID`) REFERENCES `stockin_inventory` (`Inventory_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `damage_goods_reported_by_foreign` FOREIGN KEY (`reported_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL;

--
-- Constraints for table `delivery`
--
ALTER TABLE `delivery`
  ADD CONSTRAINT `delivery_order_id_foreign` FOREIGN KEY (`Order_ID`) REFERENCES `orders` (`Order_ID`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_detail`
--
ALTER TABLE `delivery_detail`
  ADD CONSTRAINT `delivery_detail_delivery_id_foreign` FOREIGN KEY (`Delivery_ID`) REFERENCES `delivery` (`Delivery_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_detail_order_detail_id_foreign` FOREIGN KEY (`Order_detail_ID`) REFERENCES `order_details` (`Order_detail_ID`) ON DELETE CASCADE;

--
-- Constraints for table `manual_adjustment`
--
ALTER TABLE `manual_adjustment`
  ADD CONSTRAINT `manual_adjustment_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`Customer_ID`) REFERENCES `customers` (`Customer_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_sale_id_foreign` FOREIGN KEY (`Sale_ID`) REFERENCES `sales` (`Sale_ID`) ON DELETE CASCADE;

--
-- Constraints for table `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `order_details_order_id_foreign` FOREIGN KEY (`Order_ID`) REFERENCES `orders` (`Order_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_details_product_id_foreign` FOREIGN KEY (`Product_ID`) REFERENCES `products` (`Product_ID`) ON DELETE CASCADE;

--
-- Constraints for table `productions`
--
ALTER TABLE `productions`
  ADD CONSTRAINT `fk_productions_order` FOREIGN KEY (`Order_ID`) REFERENCES `orders` (`Order_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `productions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL,
  ADD CONSTRAINT `productions_product_id_foreign` FOREIGN KEY (`Product_ID`) REFERENCES `products` (`Product_ID`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`User_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_customer_id_foreign` FOREIGN KEY (`Customer_ID`) REFERENCES `customers` (`Customer_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_user_id_foreign` FOREIGN KEY (`User_ID`) REFERENCES `app_users` (`User_ID`) ON DELETE CASCADE;

--
-- Constraints for table `sale_details`
--
ALTER TABLE `sale_details`
  ADD CONSTRAINT `sale_details_product_id_foreign` FOREIGN KEY (`Product_ID`) REFERENCES `products` (`Product_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_details_sale_id_foreign` FOREIGN KEY (`Sale_ID`) REFERENCES `sales` (`Sale_ID`) ON DELETE CASCADE;

--
-- Constraints for table `sale_source`
--
ALTER TABLE `sale_source`
  ADD CONSTRAINT `sale_source_delivery_id_foreign` FOREIGN KEY (`Delivery_ID`) REFERENCES `delivery` (`Delivery_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_source_sale_id_foreign` FOREIGN KEY (`Sale_ID`) REFERENCES `sales` (`Sale_ID`) ON DELETE CASCADE;

--
-- Constraints for table `singil`
--
ALTER TABLE `singil`
  ADD CONSTRAINT `singil_ar_id_foreign` FOREIGN KEY (`AR_ID`) REFERENCES `account_receivable` (`AR_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `singil_payment_id_foreign` FOREIGN KEY (`Payment_ID`) REFERENCES `ar_payment` (`payment_ID`) ON DELETE CASCADE;

--
-- Constraints for table `stockin_inventory`
--
ALTER TABLE `stockin_inventory`
  ADD CONSTRAINT `stockin_inventory_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `app_users` (`User_ID`) ON DELETE SET NULL,
  ADD CONSTRAINT `stockin_inventory_product_id_foreign` FOREIGN KEY (`Product_ID`) REFERENCES `products` (`Product_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `stockin_inventory_production_id_foreign` FOREIGN KEY (`Production_ID`) REFERENCES `productions` (`Production_ID`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
