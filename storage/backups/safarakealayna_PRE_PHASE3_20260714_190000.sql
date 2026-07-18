-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: safarakealayna
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `account_entries`
--

DROP TABLE IF EXISTS `account_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance_after` decimal(15,2) NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_entries_transaction_id_foreign` (`transaction_id`),
  KEY `account_entries_account_id_transaction_id_index` (`account_id`,`transaction_id`),
  CONSTRAINT `account_entries_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `account_entries_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_entries`
--

LOCK TABLES `account_entries` WRITE;
/*!40000 ALTER TABLE `account_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('cashbox','wallet','bank','treasury') COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `owner_type` enum('owner','office') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'owner',
  `created_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounts_created_by_foreign` (`created_by`),
  KEY `accounts_type_owner_type_index` (`type`,`owner_type`),
  KEY `accounts_currency_index` (`currency`),
  CONSTRAINT `accounts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_workflows`
--

DROP TABLE IF EXISTS `approval_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approvable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approvable_id` bigint unsigned NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `action_type` enum('booking','transfer','currency_conversion','payment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_workflows_approved_by_foreign` (`approved_by`),
  KEY `approval_workflows_approvable_type_approvable_id_index` (`approvable_type`,`approvable_id`),
  KEY `approval_workflows_status_index` (`status`),
  KEY `approval_workflows_requested_by_index` (`requested_by`),
  CONSTRAINT `approval_workflows_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `approval_workflows_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_workflows`
--

LOCK TABLES `approval_workflows` WRITE;
/*!40000 ALTER TABLE `approval_workflows` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_workflows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_action_index` (`user_id`,`action`),
  KEY `audit_logs_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bus_bookings`
--

DROP TABLE IF EXISTS `bus_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bus_bookings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `inventory_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `profit` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bus_bookings_inventory_id_foreign` (`inventory_id`),
  KEY `bus_bookings_customer_id_foreign` (`customer_id`),
  KEY `bus_bookings_employee_id_foreign` (`employee_id`),
  KEY `bus_bookings_account_id_foreign` (`account_id`),
  CONSTRAINT `bus_bookings_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `bus_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `bus_bookings_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `bus_bookings_inventory_id_foreign` FOREIGN KEY (`inventory_id`) REFERENCES `bus_inventories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bus_bookings`
--

LOCK TABLES `bus_bookings` WRITE;
/*!40000 ALTER TABLE `bus_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `bus_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bus_companies`
--

DROP TABLE IF EXISTS `bus_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bus_companies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bus_companies_is_active_index` (`is_active`),
  KEY `bus_companies_created_by_index` (`created_by`),
  CONSTRAINT `bus_companies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bus_companies`
--

LOCK TABLES `bus_companies` WRITE;
/*!40000 ALTER TABLE `bus_companies` DISABLE KEYS */;
/*!40000 ALTER TABLE `bus_companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bus_inventories`
--

DROP TABLE IF EXISTS `bus_inventories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bus_inventories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `route` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `travel_date` date NOT NULL,
  `departure_time` time DEFAULT NULL,
  `total_tickets` int NOT NULL,
  `available_tickets` int NOT NULL,
  `cost_per_ticket` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `payment_type` enum('cash','deferred') COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_cost` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `remaining_debt` decimal(12,2) NOT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bus_inventories_account_id_foreign` (`account_id`),
  KEY `bus_inventories_transaction_id_foreign` (`transaction_id`),
  KEY `bus_inventories_created_by_foreign` (`created_by`),
  KEY `bus_inventories_company_id_index` (`company_id`),
  KEY `bus_inventories_travel_date_index` (`travel_date`),
  KEY `bus_inventories_available_tickets_index` (`available_tickets`),
  KEY `bus_inventories_remaining_debt_index` (`remaining_debt`),
  KEY `bus_inventories_company_id_travel_date_index` (`company_id`,`travel_date`),
  CONSTRAINT `bus_inventories_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `bus_inventories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `bus_companies` (`id`),
  CONSTRAINT `bus_inventories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `bus_inventories_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bus_inventories`
--

LOCK TABLES `bus_inventories` WRITE;
/*!40000 ALTER TABLE `bus_inventories` DISABLE KEYS */;
/*!40000 ALTER TABLE `bus_inventories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bus_tickets`
--

DROP TABLE IF EXISTS `bus_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bus_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `passenger_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bus_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ticket_count` int NOT NULL,
  `from_city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departure_date` date NOT NULL,
  `departure_time` time DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `return_time` time DEFAULT NULL,
  `purchase_price` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `profit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `employee_id` bigint unsigned NOT NULL,
  `payment_method` enum('cash','bank_transfer','cash_wallet','office_safe','office_drawer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bus_tickets_employee_id_index` (`employee_id`),
  KEY `bus_tickets_payment_method_index` (`payment_method`),
  KEY `bus_tickets_created_at_index` (`created_at`),
  CONSTRAINT `bus_tickets_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bus_tickets`
--

LOCK TABLES `bus_tickets` WRITE;
/*!40000 ALTER TABLE `bus_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `bus_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `national_id` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_expiry` date DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `affiliation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_tier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'STANDARD',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customers_full_name_index` (`full_name`),
  KEY `customers_phone_index` (`phone`),
  KEY `customers_national_id_index` (`national_id`),
  KEY `customers_passport_number_index` (`passport_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employees_user_id_index` (`user_id`),
  KEY `employees_status_index` (`status`),
  CONSTRAINT `employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(15,6) NOT NULL,
  `effective_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_rates_from_currency_to_currency_effective_date_unique` (`from_currency`,`to_currency`,`effective_date`),
  KEY `exchange_rates_created_by_foreign` (`created_by`),
  KEY `exchange_rates_from_currency_to_currency_index` (`from_currency`,`to_currency`),
  KEY `exchange_rates_effective_date_index` (`effective_date`),
  CONSTRAINT `exchange_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchange_rates`
--

LOCK TABLES `exchange_rates` WRITE;
/*!40000 ALTER TABLE `exchange_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `exchange_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fawry_transactions`
--

DROP TABLE IF EXISTS `fawry_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fawry_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_type` enum('withdrawal','deposit','payment','travel_permit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_amount` decimal(12,2) NOT NULL,
  `fawry_price` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `profit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `employee_id` bigint unsigned NOT NULL,
  `payment_method` enum('cash','bank_transfer','cash_wallet','office_safe','office_drawer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fawry_transactions_employee_id_index` (`employee_id`),
  KEY `fawry_transactions_payment_method_index` (`payment_method`),
  KEY `fawry_transactions_created_at_index` (`created_at`),
  CONSTRAINT `fawry_transactions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fawry_transactions`
--

LOCK TABLES `fawry_transactions` WRITE;
/*!40000 ALTER TABLE `fawry_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `fawry_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `flight_bookings`
--

DROP TABLE IF EXISTS `flight_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flight_bookings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `booking_reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `booking_channel_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `booking_channel_provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `customer_id` bigint unsigned NOT NULL,
  `agent_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `origin` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departure_date` date NOT NULL,
  `departure_time` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_date` date DEFAULT NULL,
  `return_time` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trip_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `airline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `passenger_count` int NOT NULL,
  `baggage_allowance_kg` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flight_bookings_booking_reference_unique` (`booking_reference`),
  KEY `flight_bookings_booking_reference_index` (`booking_reference`),
  KEY `flight_bookings_status_index` (`status`),
  KEY `flight_bookings_customer_id_index` (`customer_id`),
  KEY `flight_bookings_departure_date_index` (`departure_date`),
  CONSTRAINT `flight_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `flight_bookings`
--

LOCK TABLES `flight_bookings` WRITE;
/*!40000 ALTER TABLE `flight_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `flight_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `flight_payments`
--

DROP TABLE IF EXISTS `flight_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flight_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flight_booking_id` bigint unsigned NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `treasury_account` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` datetime NOT NULL,
  `paid_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `flight_payments_flight_booking_id_index` (`flight_booking_id`),
  KEY `flight_payments_payment_method_index` (`payment_method`),
  KEY `flight_payments_treasury_account_index` (`treasury_account`),
  CONSTRAINT `flight_payments_flight_booking_id_foreign` FOREIGN KEY (`flight_booking_id`) REFERENCES `flight_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `flight_payments`
--

LOCK TABLES `flight_payments` WRITE;
/*!40000 ALTER TABLE `flight_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `flight_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `flight_pricing`
--

DROP TABLE IF EXISTS `flight_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flight_pricing` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flight_booking_id` bigint unsigned NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `purchase_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `profit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `booking_currency` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_in_foreign_currency` decimal(15,2) DEFAULT NULL,
  `exchange_rate_used` decimal(15,4) DEFAULT NULL,
  `purchase_price_egp` decimal(15,2) DEFAULT NULL,
  `selling_price_egp` decimal(15,2) DEFAULT NULL,
  `profit_egp` decimal(15,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `flight_pricing_flight_booking_id_index` (`flight_booking_id`),
  CONSTRAINT `flight_pricing_flight_booking_id_foreign` FOREIGN KEY (`flight_booking_id`) REFERENCES `flight_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `flight_pricing`
--

LOCK TABLES `flight_pricing` WRITE;
/*!40000 ALTER TABLE `flight_pricing` DISABLE KEYS */;
/*!40000 ALTER TABLE `flight_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `flight_segments`
--

DROP TABLE IF EXISTS `flight_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flight_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flight_booking_id` bigint unsigned NOT NULL,
  `from_airport` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_airport` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departure_date` date NOT NULL,
  `departure_time` time NOT NULL,
  `arrival_time` time NOT NULL,
  `airline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `flight_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `baggage` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `flight_segments_flight_booking_id_index` (`flight_booking_id`),
  KEY `flight_segments_from_airport_index` (`from_airport`),
  KEY `flight_segments_to_airport_index` (`to_airport`),
  KEY `flight_segments_departure_date_index` (`departure_date`),
  CONSTRAINT `flight_segments_flight_booking_id_foreign` FOREIGN KEY (`flight_booking_id`) REFERENCES `flight_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `flight_segments`
--

LOCK TABLES `flight_segments` WRITE;
/*!40000 ALTER TABLE `flight_segments` DISABLE KEYS */;
/*!40000 ALTER TABLE `flight_segments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hajj_umra_bookings`
--

DROP TABLE IF EXISTS `hajj_umra_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hajj_umra_bookings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `program_id` bigint unsigned NOT NULL,
  `module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'HAJJ_UMRA',
  `companion_customer_id` bigint unsigned DEFAULT NULL,
  `purchase_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `profit` decimal(15,2) NOT NULL,
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `per_person` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hajj_umra_bookings_customer_id_foreign` (`customer_id`),
  KEY `hajj_umra_bookings_program_id_foreign` (`program_id`),
  KEY `hajj_umra_bookings_companion_customer_id_foreign` (`companion_customer_id`),
  KEY `hajj_umra_bookings_status_index` (`status`),
  KEY `hajj_umra_bookings_module_index` (`module`),
  CONSTRAINT `hajj_umra_bookings_companion_customer_id_foreign` FOREIGN KEY (`companion_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hajj_umra_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hajj_umra_bookings_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hajj_umra_bookings`
--

LOCK TABLES `hajj_umra_bookings` WRITE;
/*!40000 ALTER TABLE `hajj_umra_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `hajj_umra_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hajj_umra_payments`
--

DROP TABLE IF EXISTS `hajj_umra_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hajj_umra_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hajj_umra_booking_id` bigint unsigned NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `treasury_account` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` datetime NOT NULL,
  `paid_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hajj_umra_payments_hajj_umra_booking_id_foreign` (`hajj_umra_booking_id`),
  KEY `hajj_umra_payments_payment_method_index` (`payment_method`),
  KEY `hajj_umra_payments_payment_date_index` (`payment_date`),
  CONSTRAINT `hajj_umra_payments_hajj_umra_booking_id_foreign` FOREIGN KEY (`hajj_umra_booking_id`) REFERENCES `hajj_umra_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hajj_umra_payments`
--

LOCK TABLES `hajj_umra_payments` WRITE;
/*!40000 ALTER TABLE `hajj_umra_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `hajj_umra_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2026_04_26_000000_create_users_table',1),(2,'2026_04_26_000001_create_password_reset_tokens_table',1),(3,'2026_04_26_143056_create_sessions_table',1),(4,'2026_04_26_211146_create_customers_table',1),(5,'2026_04_26_211424_create_flight_bookings_table',1),(6,'2026_04_26_211451_create_flight_segments_table',1),(7,'2026_04_26_211511_create_flight_pricing_table',1),(8,'2026_04_26_211511_create_passengers_table',1),(9,'2026_04_27_123013_create_flight_payments_table',1),(10,'2026_04_27_124250_create_programs_table',1),(11,'2026_04_27_124551_create_hajj_umra_bookings_table',1),(12,'2026_04_27_124640_create_visa_details_table',1),(13,'2026_04_27_124645_create_visa_bookings_table',1),(14,'2026_04_27_145756_create_hajj_umra_payments_table',1),(15,'2026_04_27_145800_create_cache_table',1),(16,'2026_04_27_145910_create_visa_payments_table',1),(17,'2026_04_27_160500_create_bus_tickets_table',1),(18,'2026_04_27_160600_create_fawry_transactions_table',1),(19,'2026_04_27_170000_consolidate_hajj_visa_duplicates',1),(20,'2026_04_27_170100_create_treasury_transactions_table',1),(21,'2026_04_27_170116_create_approval_workflows_table',1),(22,'2026_04_27_170117_create_accounts_table',1),(23,'2026_04_27_170117_create_transactions_table',1),(24,'2026_04_27_170118_create_account_entries_table',1),(25,'2026_04_27_170118_create_transfers_table',1),(26,'2026_04_27_170119_create_exchange_rates_table',1),(27,'2026_04_27_170120_create_audit_logs_table',1),(28,'2026_04_27_230344_create_bus_companies_table',1),(29,'2026_04_27_230402_create_employees_table',1),(30,'2026_04_27_230403_create_bus_inventories_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passengers`
--

DROP TABLE IF EXISTS `passengers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `passengers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flight_booking_id` bigint unsigned NOT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('adult','child','infant') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'adult',
  `date_of_birth` date DEFAULT NULL,
  `relation_to_customer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsible_adult_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `passengers_responsible_adult_id_foreign` (`responsible_adult_id`),
  KEY `passengers_flight_booking_id_index` (`flight_booking_id`),
  KEY `passengers_type_index` (`type`),
  CONSTRAINT `passengers_flight_booking_id_foreign` FOREIGN KEY (`flight_booking_id`) REFERENCES `flight_bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `passengers_responsible_adult_id_foreign` FOREIGN KEY (`responsible_adult_id`) REFERENCES `passengers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passengers`
--

LOCK TABLES `passengers` WRITE;
/*!40000 ALTER TABLE `passengers` DISABLE KEYS */;
/*!40000 ALTER TABLE `passengers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `programs`
--

DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `programs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `program_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_nights` int NOT NULL,
  `accommodation_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mecca_hotel_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mecca_nights` int NOT NULL,
  `medina_hotel_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medina_nights` int DEFAULT NULL,
  `departure_date` date NOT NULL,
  `return_date` date NOT NULL,
  `airline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trip_supervisor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `executing_company` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departure_point` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `booking_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `program_price_tier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `programs_program_type_index` (`program_type`),
  KEY `programs_booking_status_index` (`booking_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `programs`
--

LOCK TABLES `programs` WRITE;
/*!40000 ALTER TABLE `programs` DISABLE KEYS */;
/*!40000 ALTER TABLE `programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('income','expense','transfer','refund') COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` enum('flight','bus','fawry','online','hajj_umra','visa','wallet','general') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `from_account_id` bigint unsigned DEFAULT NULL,
  `to_account_id` bigint unsigned DEFAULT NULL,
  `approval_workflow_id` bigint unsigned DEFAULT NULL,
  `related_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transactions_to_account_id_foreign` (`to_account_id`),
  KEY `transactions_approval_workflow_id_foreign` (`approval_workflow_id`),
  KEY `transactions_created_by_foreign` (`created_by`),
  KEY `transactions_type_module_index` (`type`,`module`),
  KEY `transactions_from_account_id_to_account_id_index` (`from_account_id`,`to_account_id`),
  KEY `transactions_related_type_related_id_index` (`related_type`,`related_id`),
  CONSTRAINT `transactions_approval_workflow_id_foreign` FOREIGN KEY (`approval_workflow_id`) REFERENCES `approval_workflows` (`id`),
  CONSTRAINT `transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `transactions_from_account_id_foreign` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `transactions_to_account_id_foreign` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfers`
--

DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_account_id` bigint unsigned NOT NULL,
  `to_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `from_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange_rate` decimal(10,6) DEFAULT NULL,
  `converted_amount` decimal(15,2) DEFAULT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `approval_workflow_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transfers_to_account_id_foreign` (`to_account_id`),
  KEY `transfers_transaction_id_foreign` (`transaction_id`),
  KEY `transfers_approval_workflow_id_foreign` (`approval_workflow_id`),
  KEY `transfers_created_by_foreign` (`created_by`),
  KEY `transfers_from_account_id_to_account_id_index` (`from_account_id`,`to_account_id`),
  CONSTRAINT `transfers_approval_workflow_id_foreign` FOREIGN KEY (`approval_workflow_id`) REFERENCES `approval_workflows` (`id`),
  CONSTRAINT `transfers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `transfers_from_account_id_foreign` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `transfers_to_account_id_foreign` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `transfers_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfers`
--

LOCK TABLES `transfers` WRITE;
/*!40000 ALTER TABLE `transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `treasury_transactions`
--

DROP TABLE IF EXISTS `treasury_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_treasury` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_treasury` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `flight_booking_id` bigint unsigned DEFAULT NULL,
  `hajj_umra_booking_id` bigint unsigned DEFAULT NULL,
  `visa_booking_id` bigint unsigned DEFAULT NULL,
  `agent_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `treasury_transactions_flight_booking_id_foreign` (`flight_booking_id`),
  KEY `treasury_transactions_hajj_umra_booking_id_foreign` (`hajj_umra_booking_id`),
  KEY `treasury_transactions_visa_booking_id_foreign` (`visa_booking_id`),
  KEY `treasury_transactions_from_treasury_index` (`from_treasury`),
  KEY `treasury_transactions_to_treasury_index` (`to_treasury`),
  KEY `treasury_transactions_created_at_index` (`created_at`),
  CONSTRAINT `treasury_transactions_flight_booking_id_foreign` FOREIGN KEY (`flight_booking_id`) REFERENCES `flight_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `treasury_transactions_hajj_umra_booking_id_foreign` FOREIGN KEY (`hajj_umra_booking_id`) REFERENCES `hajj_umra_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `treasury_transactions_visa_booking_id_foreign` FOREIGN KEY (`visa_booking_id`) REFERENCES `visa_bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `treasury_transactions`
--

LOCK TABLES `treasury_transactions` WRITE;
/*!40000 ALTER TABLE `treasury_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `treasury_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Phase 3 Verifier','phase3-verifier@example.com',NULL,'$2y$12$/7JcalEKbAuPULZ1QNJFrOBVq4hF9nnMuBDT2uEaUTOzc3dri/mZC','admin',1,NULL,'2026-07-14 10:30:48','2026-07-14 10:30:48');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visa_bookings`
--

DROP TABLE IF EXISTS `visa_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visa_bookings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `visa_detail_id` bigint unsigned NOT NULL,
  `module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'VISA',
  `purchase_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `service_fee` decimal(15,2) DEFAULT NULL,
  `profit` decimal(15,2) NOT NULL,
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `visa_bookings_customer_id_foreign` (`customer_id`),
  KEY `visa_bookings_visa_detail_id_foreign` (`visa_detail_id`),
  KEY `visa_bookings_status_index` (`status`),
  KEY `visa_bookings_module_index` (`module`),
  CONSTRAINT `visa_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visa_bookings_visa_detail_id_foreign` FOREIGN KEY (`visa_detail_id`) REFERENCES `visa_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visa_bookings`
--

LOCK TABLES `visa_bookings` WRITE;
/*!40000 ALTER TABLE `visa_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `visa_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visa_details`
--

DROP TABLE IF EXISTS `visa_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visa_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visa_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `validity_from` date DEFAULT NULL,
  `validity_to` date DEFAULT NULL,
  `executing_company` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executing_agent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executing_agent_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submission_date` date DEFAULT NULL,
  `expected_result_date` date DEFAULT NULL,
  `visa_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `visa_details_visa_type_index` (`visa_type`),
  KEY `visa_details_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visa_details`
--

LOCK TABLES `visa_details` WRITE;
/*!40000 ALTER TABLE `visa_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `visa_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visa_payments`
--

DROP TABLE IF EXISTS `visa_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visa_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visa_booking_id` bigint unsigned NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP',
  `treasury_account` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` datetime NOT NULL,
  `paid_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `visa_payments_visa_booking_id_foreign` (`visa_booking_id`),
  KEY `visa_payments_payment_method_index` (`payment_method`),
  KEY `visa_payments_payment_date_index` (`payment_date`),
  CONSTRAINT `visa_payments_visa_booking_id_foreign` FOREIGN KEY (`visa_booking_id`) REFERENCES `visa_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visa_payments`
--

LOCK TABLES `visa_payments` WRITE;
/*!40000 ALTER TABLE `visa_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `visa_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'safarakealayna'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-14 18:59:59
