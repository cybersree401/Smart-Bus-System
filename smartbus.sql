-- =====================================================================
-- SmartBus - Bus Reservation and Management System Database Schema
-- Compatible with MySQL 8.x / MariaDB 10.x on XAMPP
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `coupon_redemptions`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `cancellations`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `booking_passengers`;
DROP TABLE IF EXISTS `booking_seats`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `trip_tracking`;
DROP TABLE IF EXISTS `trip_seats`;
DROP TABLE IF EXISTS `trips`;
DROP TABLE IF EXISTS `route_stops`;
DROP TABLE IF EXISTS `routes`;
DROP TABLE IF EXISTS `stops`;
DROP TABLE IF EXISTS `bus_amenities`;
DROP TABLE IF EXISTS `buses`;
DROP TABLE IF EXISTS `amenities`;
DROP TABLE IF EXISTS `bus_types`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. Table: users
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `phone` VARCHAR(20) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('passenger', 'admin') NOT NULL DEFAULT 'passenger',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `reset_token` VARCHAR(100) NULL,
  `reset_expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Table: bus_types
-- ---------------------------------------------------------------------
CREATE TABLE `bus_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `category` ENUM('seater', 'sleeper', 'semi-sleeper', 'mixed') NOT NULL DEFAULT 'seater',
  `is_ac` TINYINT(1) NOT NULL DEFAULT 1,
  `has_upper_deck` TINYINT(1) NOT NULL DEFAULT 0,
  `total_seats` INT UNSIGNED NOT NULL DEFAULT 40,
  `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Table: amenities
-- ---------------------------------------------------------------------
CREATE TABLE `amenities` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `icon` VARCHAR(50) NOT NULL DEFAULT 'check',
  `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Table: buses
-- ---------------------------------------------------------------------
CREATE TABLE `buses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bus_number` VARCHAR(30) NOT NULL UNIQUE,
  `operator_name` VARCHAR(100) NOT NULL,
  `bus_type_id` INT UNSIGNED NOT NULL,
  `total_seats` INT UNSIGNED NOT NULL,
  `rating` DECIMAL(3, 2) NOT NULL DEFAULT 4.50,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_buses_bus_type` FOREIGN KEY (`bus_type_id`) REFERENCES `bus_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Table: bus_amenities (Many-to-Many)
-- ---------------------------------------------------------------------
CREATE TABLE `bus_amenities` (
  `bus_id` INT UNSIGNED NOT NULL,
  `amenity_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`bus_id`, `amenity_id`),
  CONSTRAINT `fk_ba_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ba_amenity` FOREIGN KEY (`amenity_id`) REFERENCES `amenities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Table: stops
-- ---------------------------------------------------------------------
CREATE TABLE `stops` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `city` VARCHAR(100) NOT NULL,
  `stop_name` VARCHAR(150) NOT NULL,
  `landmark` VARCHAR(200) NULL,
  `latitude` DECIMAL(10, 7) NULL,
  `longitude` DECIMAL(10, 7) NULL,
  INDEX `idx_stops_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Table: routes
-- ---------------------------------------------------------------------
CREATE TABLE `routes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source_city` VARCHAR(100) NOT NULL,
  `destination_city` VARCHAR(100) NOT NULL,
  `distance_km` DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 360,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_routes_source_dest` (`source_city`, `destination_city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Table: route_stops
-- ---------------------------------------------------------------------
CREATE TABLE `route_stops` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `route_id` INT UNSIGNED NOT NULL,
  `stop_id` INT UNSIGNED NOT NULL,
  `stop_type` ENUM('boarding', 'dropping', 'both') NOT NULL DEFAULT 'both',
  `stop_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `minutes_from_start` INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT `fk_rs_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rs_stop` FOREIGN KEY (`stop_id`) REFERENCES `stops` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Table: trips (Scheduled bus journeys)
-- ---------------------------------------------------------------------
CREATE TABLE `trips` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `route_id` INT UNSIGNED NOT NULL,
  `bus_id` INT UNSIGNED NOT NULL,
  `departure_datetime` DATETIME NOT NULL,
  `arrival_datetime` DATETIME NOT NULL,
  `base_fare` DECIMAL(10, 2) NOT NULL DEFAULT 500.00,
  `trip_status` ENUM('scheduled', 'boarding', 'in_transit', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_trips_dep_date` (`departure_datetime`),
  INDEX `idx_trips_route` (`route_id`),
  CONSTRAINT `fk_trips_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trips_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Table: trip_seats (Seat Inventory per trip)
-- Concurrency lock & uniqueness guarantee
-- ---------------------------------------------------------------------
CREATE TABLE `trip_seats` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trip_id` INT UNSIGNED NOT NULL,
  `seat_number` VARCHAR(10) NOT NULL,
  `deck` ENUM('lower', 'upper') NOT NULL DEFAULT 'lower',
  `seat_type` ENUM('seater', 'sleeper', 'semi-sleeper') NOT NULL DEFAULT 'seater',
  `row_num` INT UNSIGNED NOT NULL DEFAULT 1,
  `col_num` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('available', 'reserved', 'booked', 'blocked') NOT NULL DEFAULT 'available',
  `fare_multiplier` DECIMAL(4, 2) NOT NULL DEFAULT 1.00,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_trip_seat` (`trip_id`, `seat_number`),
  INDEX `idx_trip_seats_status` (`trip_id`, `status`),
  CONSTRAINT `fk_ts_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. Table: bookings
-- ---------------------------------------------------------------------
CREATE TABLE `bookings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_reference` VARCHAR(25) NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `trip_id` INT UNSIGNED NOT NULL,
  `boarding_stop_id` INT UNSIGNED NOT NULL,
  `dropping_stop_id` INT UNSIGNED NOT NULL,
  `total_seats` INT UNSIGNED NOT NULL DEFAULT 1,
  `base_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `booking_status` ENUM('PENDING', 'CONFIRMED', 'CANCELLED', 'EXPIRED', 'PAYMENT_FAILED') NOT NULL DEFAULT 'PENDING',
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bookings_user` (`user_id`),
  INDEX `idx_bookings_trip` (`trip_id`),
  INDEX `idx_bookings_ref` (`booking_reference`),
  INDEX `idx_bookings_status` (`booking_status`),
  CONSTRAINT `fk_b_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_b_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_b_board` FOREIGN KEY (`boarding_stop_id`) REFERENCES `stops` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_b_drop` FOREIGN KEY (`dropping_stop_id`) REFERENCES `stops` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. Table: booking_passengers
-- ---------------------------------------------------------------------
CREATE TABLE `booking_passengers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT UNSIGNED NOT NULL,
  `seat_number` VARCHAR(10) NOT NULL,
  `passenger_name` VARCHAR(100) NOT NULL,
  `passenger_age` INT UNSIGNED NOT NULL,
  `passenger_gender` ENUM('male', 'female', 'other') NOT NULL DEFAULT 'male',
  CONSTRAINT `fk_bp_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. Table: booking_seats
-- ---------------------------------------------------------------------
CREATE TABLE `booking_seats` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT UNSIGNED NOT NULL,
  `seat_number` VARCHAR(10) NOT NULL,
  `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY `uk_booking_seat` (`booking_id`, `seat_number`),
  CONSTRAINT `fk_bs_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. Table: payments
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT UNSIGNED NOT NULL,
  `transaction_ref` VARCHAR(50) NOT NULL UNIQUE,
  `payment_method` ENUM('UPI', 'CARD', 'NETBANKING', 'DEMO') NOT NULL DEFAULT 'DEMO',
  `payment_status` ENUM('PENDING', 'SUCCESS', 'FAILED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
  `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `demo_notes` VARCHAR(255) NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_payments_booking` (`booking_id`),
  CONSTRAINT `fk_pay_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. Table: cancellations & refunds
-- ---------------------------------------------------------------------
CREATE TABLE `cancellations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT UNSIGNED NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `cancellation_reason` VARCHAR(255) NULL,
  `refund_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `cancellation_charge` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `refund_status` ENUM('INITIATED', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'COMPLETED',
  `cancelled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_canc_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_canc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. Table: coupons
-- ---------------------------------------------------------------------
CREATE TABLE `coupons` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `discount_type` ENUM('flat', 'percentage') NOT NULL DEFAULT 'flat',
  `discount_value` DECIMAL(10, 2) NOT NULL DEFAULT 50.00,
  `min_booking_amount` DECIMAL(10, 2) NOT NULL DEFAULT 300.00,
  `max_discount` DECIMAL(10, 2) NOT NULL DEFAULT 200.00,
  `expiry_date` DATE NOT NULL,
  `usage_limit` INT UNSIGNED NOT NULL DEFAULT 1000,
  `times_used` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. Table: coupon_redemptions
-- ---------------------------------------------------------------------
CREATE TABLE `coupon_redemptions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `coupon_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `booking_id` INT UNSIGNED NOT NULL,
  `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cr_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cr_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. Table: reviews
-- ---------------------------------------------------------------------
CREATE TABLE `reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `trip_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `comment` TEXT NOT NULL,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_rev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rev_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. Table: notifications
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('booking', 'payment', 'trip', 'cancellation', 'system') NOT NULL DEFAULT 'system',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif_user` (`user_id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. Table: audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. Table: trip_tracking (Simulated GPS feed)
-- ---------------------------------------------------------------------
CREATE TABLE `trip_tracking` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `trip_id` INT UNSIGNED NOT NULL UNIQUE,
  `current_latitude` DECIMAL(10, 7) NOT NULL,
  `current_longitude` DECIMAL(10, 7) NOT NULL,
  `current_speed` INT UNSIGNED NOT NULL DEFAULT 65,
  `current_location_name` VARCHAR(150) NOT NULL DEFAULT 'En route',
  `next_stop_id` INT UNSIGNED NULL,
  `delay_minutes` INT NOT NULL DEFAULT 0,
  `status_text` VARCHAR(100) NOT NULL DEFAULT 'On Time',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tt_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA
-- Default Users:
-- Admin: admin@smartbus.com / Admin@123
-- Passenger: passenger@smartbus.com / Passenger@123
-- Passwords hashed using BCRYPT
-- =====================================================================

INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`) VALUES
(1, 'System Administrator', 'admin@smartbus.com', '9876543210', '$2y$10$wK1Pcr/9L72KkPZ5oQ9W3ODN0s2YQZ7tq1o/e54b67m7Gv7u1cK32', 'admin', 1),
(2, 'Rahul Sharma', 'passenger@smartbus.com', '9876543211', '$2y$10$0zB2bCq56qR8qJjV2L/NTe2L77mJ1t8RkQ9gH4mE5nB6kL7P8q9Zy', 'passenger', 1),
(3, 'Priya Venkatesh', 'priya@example.com', '9876543212', '$2y$10$0zB2bCq56qR8qJjV2L/NTe2L77mJ1t8RkQ9gH4mE5nB6kL7P8q9Zy', 'passenger', 1);

-- Note: The admin hash corresponds to 'Admin@123'
-- The passenger hash corresponds to 'Passenger@123'

-- Amenities
INSERT INTO `amenities` (`id`, `name`, `icon`, `description`) VALUES
(1, 'High-Speed Wi-Fi', 'wifi', 'Complimentary on-board Wi-Fi internet access'),
(2, 'Personal Charging Port', 'zap', 'Individual USB & 220V charging plug per seat'),
(3, 'Mineral Water Bottle', 'droplet', 'Chilled 500ml mineral water included'),
(4, 'Clean Blanket & Pillow', 'feather', 'Freshly laundered blanket and ergonomic pillow'),
(5, 'Reading Lights', 'sun', 'Adjustable reading lamp for night journeys'),
(6, 'Live GPS Tracking', 'map-pin', 'Real-time bus tracking and journey alerts'),
(7, 'Emergency Exit & CCTV', 'shield', '24/7 cabin security surveillance & safety hammer');

-- Bus Types
INSERT INTO `bus_types` (`id`, `name`, `category`, `is_ac`, `has_upper_deck`, `total_seats`, `description`) VALUES
(1, 'Multi-Axle AC Sleeper (2+1)', 'sleeper', 1, 1, 30, 'Ultra-luxury double-deck sleeper berths with private curtains'),
(2, 'Volvo AC Semi-Sleeper (2+2)', 'semi-sleeper', 1, 0, 40, 'Ergonomic 135-degree pushback leather reclining seats'),
(3, 'Scania Executive Seater (2+2)', 'seater', 1, 0, 44, 'Spacious calf-support seating with ample legroom'),
(4, 'Non-AC Deluxe Express (2+2)', 'seater', 0, 0, 48, 'Cost-effective comfortable intercity coach');

-- Buses
INSERT INTO `buses` (`id`, `bus_number`, `operator_name`, `bus_type_id`, `total_seats`, `rating`) VALUES
(1, 'TN-01-SB-1001', 'SmartBus Royal Cruiser', 1, 30, 4.85),
(2, 'KA-04-SB-2002', 'SmartBus Starline Volvo', 2, 40, 4.70),
(3, 'TN-09-SB-3003', 'SmartBus CityLink Express', 3, 44, 4.55),
(4, 'TN-07-SB-4004', 'SmartBus NightRider Sleeper', 1, 30, 4.90),
(5, 'AP-02-SB-5005', 'SmartBus EcoLine Deluxe', 4, 48, 4.30);

-- Bus Amenities Mapping
INSERT INTO `bus_amenities` (`bus_id`, `amenity_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7),
(2, 1), (2, 2), (2, 3), (2, 5), (2, 6), (2, 7),
(3, 2), (3, 3), (3, 5), (3, 6),
(4, 1), (4, 2), (4, 3), (4, 4), (4, 5), (4, 6), (4, 7),
(5, 5), (5, 7);

-- Stops
INSERT INTO `stops` (`id`, `city`, `stop_name`, `landmark`, `latitude`, `longitude`) VALUES
(1, 'Chennai', 'Koyambedu CMBT', 'Omni Bus Stand Platform 12', 13.067439, 80.207825),
(2, 'Chennai', 'Guindy', 'Kathipara Junction Flyover', 13.006700, 80.202100),
(3, 'Chennai', 'Perungalathur', 'Opposite Bus Stop near Police Station', 12.904800, 80.089000),
(4, 'Bengaluru', 'Electronic City Toll', 'Near Toll Plaza Gate 3', 12.845200, 77.660200),
(5, 'Bengaluru', 'Madiwala', 'Near Total Mall Bus Bay', 12.922600, 77.617400),
(6, 'Bengaluru', 'Majestic KBS', 'Platform 18 Intercity Terminal', 12.977200, 77.570800),
(7, 'Coimbatore', 'Gandhipuram Central Bus Stand', 'Near Cross Cut Road Signal', 11.018300, 76.963400),
(8, 'Coimbatore', 'Omni Bus Stand Sathy Road', 'Platform 4', 11.026800, 76.974200),
(9, 'Madurai', 'Mattuthavani Integrated Terminal', 'Bay 6', 9.946300, 78.156900),
(10, 'Madurai', 'Periyar Bus Stand', 'Near Railway Station Road', 9.917400, 78.113200),
(11, 'Trichy', 'Central Bus Stand', 'Platform 8', 10.796100, 78.685400),
(12, 'Hyderabad', 'MGBS Central Station', 'Terminal 2', 17.378900, 78.481200);

-- Routes
INSERT INTO `routes` (`id`, `source_city`, `destination_city`, `distance_km`, `duration_minutes`) VALUES
(1, 'Chennai', 'Bengaluru', 350.00, 360),
(2, 'Bengaluru', 'Chennai', 350.00, 360),
(3, 'Chennai', 'Coimbatore', 505.00, 480),
(4, 'Coimbatore', 'Chennai', 505.00, 480),
(5, 'Chennai', 'Madurai', 460.00, 450),
(6, 'Trichy', 'Chennai', 320.00, 330),
(7, 'Bengaluru', 'Hyderabad', 570.00, 540);

-- Route Stops
INSERT INTO `route_stops` (`route_id`, `stop_id`, `stop_type`, `stop_order`, `minutes_from_start`) VALUES
-- Route 1: Chennai to Bengaluru
(1, 1, 'boarding', 1, 0),
(1, 2, 'boarding', 2, 25),
(1, 3, 'boarding', 3, 50),
(1, 4, 'dropping', 4, 310),
(1, 5, 'dropping', 5, 335),
(1, 6, 'dropping', 6, 360),
-- Route 2: Bengaluru to Chennai
(2, 6, 'boarding', 1, 0),
(2, 5, 'boarding', 2, 30),
(2, 4, 'boarding', 3, 55),
(2, 3, 'dropping', 4, 315),
(2, 2, 'dropping', 5, 340),
(2, 1, 'dropping', 6, 360),
-- Route 3: Chennai to Coimbatore
(3, 1, 'boarding', 1, 0),
(3, 3, 'boarding', 2, 45),
(3, 8, 'dropping', 3, 450),
(3, 7, 'dropping', 4, 480),
-- Route 5: Chennai to Madurai
(5, 1, 'boarding', 1, 0),
(5, 3, 'boarding', 2, 45),
(5, 9, 'dropping', 3, 420),
(5, 10, 'dropping', 4, 450);

-- ---------------------------------------------------------------------
-- Trips Generator: Scheduled for Today, Tomorrow, and Next 14 Days
-- dynamically generated relative to CURDATE() so the system is ALWAYS active!
-- ---------------------------------------------------------------------

-- Today's trips
INSERT INTO `trips` (`id`, `route_id`, `bus_id`, `departure_datetime`, `arrival_datetime`, `base_fare`, `trip_status`) VALUES
(1, 1, 1, CONCAT(CURDATE(), ' 06:00:00'), CONCAT(CURDATE(), ' 12:00:00'), 850.00, 'scheduled'),
(2, 1, 2, CONCAT(CURDATE(), ' 14:00:00'), CONCAT(CURDATE(), ' 20:00:00'), 720.00, 'scheduled'),
(3, 1, 4, CONCAT(CURDATE(), ' 22:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 04:30:00'), 950.00, 'scheduled'),
(4, 2, 2, CONCAT(CURDATE(), ' 07:30:00'), CONCAT(CURDATE(), ' 13:30:00'), 720.00, 'scheduled'),
(5, 3, 3, CONCAT(CURDATE(), ' 21:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 05:00:00'), 800.00, 'scheduled'),
(6, 5, 1, CONCAT(CURDATE(), ' 22:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 05:30:00'), 890.00, 'scheduled'),
-- Tomorrow's trips
(7, 1, 1, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 06:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 12:00:00'), 850.00, 'scheduled'),
(8, 1, 2, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 14:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 20:00:00'), 720.00, 'scheduled'),
(9, 1, 4, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 22:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 04:30:00'), 950.00, 'scheduled'),
(10, 2, 2, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 07:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 13:30:00'), 720.00, 'scheduled'),
(11, 3, 3, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 21:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 05:00:00'), 800.00, 'scheduled'),
(12, 5, 1, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 22:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 05:30:00'), 890.00, 'scheduled'),
-- Day 2, 3, 4, 7 Future trips
(13, 1, 4, CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 22:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 3 DAY), ' 04:30:00'), 950.00, 'scheduled'),
(14, 1, 2, CONCAT(DATE_ADD(CURDATE(), INTERVAL 3 DAY), ' 14:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 3 DAY), ' 20:00:00'), 720.00, 'scheduled'),
(15, 3, 1, CONCAT(DATE_ADD(CURDATE(), INTERVAL 4 DAY), ' 21:30:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 5 DAY), ' 05:30:00'), 980.00, 'scheduled'),
(16, 7, 3, CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), ' 20:00:00'), CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 05:00:00'), 920.00, 'scheduled');

-- ---------------------------------------------------------------------
-- Helper Stored Procedure / Direct Seeding of Trip Seats
-- Creates standard layouts:
-- Sleeper (30 seats: L1-L15 lower deck, U1-U15 upper deck)
-- Seater (40 seats: 1A, 1B, 1C, 1D ... 10A, 10B, 10C, 10D)
-- ---------------------------------------------------------------------

-- Seed Seats for Trip 1 (Sleeper 30 seats: L1..L15, U1..U15)
INSERT INTO `trip_seats` (`trip_id`, `seat_number`, `deck`, `seat_type`, `row_num`, `col_num`, `status`, `fare_multiplier`) VALUES
(1, 'L1', 'lower', 'sleeper', 1, 1, 'available', 1.00),
(1, 'L2', 'lower', 'sleeper', 1, 2, 'booked', 1.00),
(1, 'L3', 'lower', 'sleeper', 1, 3, 'available', 1.00),
(1, 'L4', 'lower', 'sleeper', 2, 1, 'available', 1.00),
(1, 'L5', 'lower', 'sleeper', 2, 2, 'available', 1.00),
(1, 'L6', 'lower', 'sleeper', 2, 3, 'available', 1.00),
(1, 'L7', 'lower', 'sleeper', 3, 1, 'booked', 1.00),
(1, 'L8', 'lower', 'sleeper', 3, 2, 'available', 1.00),
(1, 'L9', 'lower', 'sleeper', 3, 3, 'available', 1.00),
(1, 'L10', 'lower', 'sleeper', 4, 1, 'available', 1.00),
(1, 'L11', 'lower', 'sleeper', 4, 2, 'available', 1.00),
(1, 'L12', 'lower', 'sleeper', 4, 3, 'available', 1.00),
(1, 'L13', 'lower', 'sleeper', 5, 1, 'available', 1.00),
(1, 'L14', 'lower', 'sleeper', 5, 2, 'available', 1.00),
(1, 'L15', 'lower', 'sleeper', 5, 3, 'available', 1.00),
(1, 'U1', 'upper', 'sleeper', 1, 1, 'available', 1.05),
(1, 'U2', 'upper', 'sleeper', 1, 2, 'available', 1.05),
(1, 'U3', 'upper', 'sleeper', 1, 3, 'available', 1.05),
(1, 'U4', 'upper', 'sleeper', 2, 1, 'available', 1.05),
(1, 'U5', 'upper', 'sleeper', 2, 2, 'booked', 1.05),
(1, 'U6', 'upper', 'sleeper', 2, 3, 'available', 1.05),
(1, 'U7', 'upper', 'sleeper', 3, 1, 'available', 1.05),
(1, 'U8', 'upper', 'sleeper', 3, 2, 'available', 1.05),
(1, 'U9', 'upper', 'sleeper', 3, 3, 'available', 1.05),
(1, 'U10', 'upper', 'sleeper', 4, 1, 'available', 1.05),
(1, 'U11', 'upper', 'sleeper', 4, 2, 'available', 1.05),
(1, 'U12', 'upper', 'sleeper', 4, 3, 'available', 1.05),
(1, 'U13', 'upper', 'sleeper', 5, 1, 'available', 1.05),
(1, 'U14', 'upper', 'sleeper', 5, 2, 'available', 1.05),
(1, 'U15', 'upper', 'sleeper', 5, 3, 'available', 1.05);

-- Seed Seats for Trip 2 (Seater 40 seats)
INSERT INTO `trip_seats` (`trip_id`, `seat_number`, `deck`, `seat_type`, `row_num`, `col_num`, `status`, `fare_multiplier`) VALUES
(2, '1A', 'lower', 'semi-sleeper', 1, 1, 'available', 1.00),
(2, '1B', 'lower', 'semi-sleeper', 1, 2, 'available', 1.00),
(2, '1C', 'lower', 'semi-sleeper', 1, 3, 'available', 1.00),
(2, '1D', 'lower', 'semi-sleeper', 1, 4, 'available', 1.00),
(2, '2A', 'lower', 'semi-sleeper', 2, 1, 'booked', 1.00),
(2, '2B', 'lower', 'semi-sleeper', 2, 2, 'booked', 1.00),
(2, '2C', 'lower', 'semi-sleeper', 2, 3, 'available', 1.00),
(2, '2D', 'lower', 'semi-sleeper', 2, 4, 'available', 1.00),
(2, '3A', 'lower', 'semi-sleeper', 3, 1, 'available', 1.00),
(2, '3B', 'lower', 'semi-sleeper', 3, 2, 'available', 1.00),
(2, '3C', 'lower', 'semi-sleeper', 3, 3, 'available', 1.00),
(2, '3D', 'lower', 'semi-sleeper', 3, 4, 'available', 1.00),
(2, '4A', 'lower', 'semi-sleeper', 4, 1, 'available', 1.00),
(2, '4B', 'lower', 'semi-sleeper', 4, 2, 'available', 1.00),
(2, '4C', 'lower', 'semi-sleeper', 4, 3, 'available', 1.00),
(2, '4D', 'lower', 'semi-sleeper', 4, 4, 'available', 1.00),
(2, '5A', 'lower', 'semi-sleeper', 5, 1, 'available', 1.00),
(2, '5B', 'lower', 'semi-sleeper', 5, 2, 'available', 1.00),
(2, '5C', 'lower', 'semi-sleeper', 5, 3, 'available', 1.00),
(2, '5D', 'lower', 'semi-sleeper', 5, 4, 'available', 1.00),
(2, '6A', 'lower', 'semi-sleeper', 6, 1, 'available', 1.00),
(2, '6B', 'lower', 'semi-sleeper', 6, 2, 'available', 1.00),
(2, '6C', 'lower', 'semi-sleeper', 6, 3, 'available', 1.00),
(2, '6D', 'lower', 'semi-sleeper', 6, 4, 'available', 1.00),
(2, '7A', 'lower', 'semi-sleeper', 7, 1, 'available', 1.00),
(2, '7B', 'lower', 'semi-sleeper', 7, 2, 'available', 1.00),
(2, '7C', 'lower', 'semi-sleeper', 7, 3, 'available', 1.00),
(2, '7D', 'lower', 'semi-sleeper', 7, 4, 'available', 1.00),
(2, '8A', 'lower', 'semi-sleeper', 8, 1, 'available', 1.00),
(2, '8B', 'lower', 'semi-sleeper', 8, 2, 'available', 1.00),
(2, '8C', 'lower', 'semi-sleeper', 8, 3, 'available', 1.00),
(2, '8D', 'lower', 'semi-sleeper', 8, 4, 'available', 1.00),
(2, '9A', 'lower', 'semi-sleeper', 9, 1, 'available', 1.00),
(2, '9B', 'lower', 'semi-sleeper', 9, 2, 'available', 1.00),
(2, '9C', 'lower', 'semi-sleeper', 9, 3, 'available', 1.00),
(2, '9D', 'lower', 'semi-sleeper', 9, 4, 'available', 1.00),
(2, '10A', 'lower', 'semi-sleeper', 10, 1, 'available', 1.00),
(2, '10B', 'lower', 'semi-sleeper', 10, 2, 'available', 1.00),
(2, '10C', 'lower', 'semi-sleeper', 10, 3, 'available', 1.00),
(2, '10D', 'lower', 'semi-sleeper', 10, 4, 'available', 1.00);

-- Seed Seats for Trip 3, 7, 8
INSERT INTO `trip_seats` (`trip_id`, `seat_number`, `deck`, `seat_type`, `row_num`, `col_num`, `status`, `fare_multiplier`)
SELECT 3, seat_number, deck, seat_type, row_num, col_num, 'available', fare_multiplier FROM `trip_seats` WHERE trip_id = 1;

INSERT INTO `trip_seats` (`trip_id`, `seat_number`, `deck`, `seat_type`, `row_num`, `col_num`, `status`, `fare_multiplier`)
SELECT 7, seat_number, deck, seat_type, row_num, col_num, 'available', fare_multiplier FROM `trip_seats` WHERE trip_id = 1;

INSERT INTO `trip_seats` (`trip_id`, `seat_number`, `deck`, `seat_type`, `row_num`, `col_num`, `status`, `fare_multiplier`)
SELECT 8, seat_number, deck, seat_type, row_num, col_num, 'available', fare_multiplier FROM `trip_seats` WHERE trip_id = 2;

-- ---------------------------------------------------------------------
-- Coupons
-- ---------------------------------------------------------------------
INSERT INTO `coupons` (`id`, `code`, `discount_type`, `discount_value`, `min_booking_amount`, `max_discount`, `expiry_date`, `usage_limit`, `times_used`, `is_active`) VALUES
(1, 'WELCOME50', 'percentage', 20.00, 400.00, 150.00, DATE_ADD(CURDATE(), INTERVAL 90 DAY), 500, 12, 1),
(2, 'SMART100', 'flat', 100.00, 600.00, 100.00, DATE_ADD(CURDATE(), INTERVAL 90 DAY), 1000, 45, 1),
(3, 'SUPERBUS', 'percentage', 15.00, 500.00, 200.00, DATE_ADD(CURDATE(), INTERVAL 60 DAY), 200, 5, 1);

-- ---------------------------------------------------------------------
-- Sample Confirmed Booking
-- ---------------------------------------------------------------------
INSERT INTO `bookings` (`id`, `booking_reference`, `user_id`, `trip_id`, `boarding_stop_id`, `dropping_stop_id`, `total_seats`, `base_amount`, `discount_amount`, `tax_amount`, `total_amount`, `booking_status`, `created_at`) VALUES
(1, 'SB-202610-8812', 2, 1, 1, 5, 2, 1700.00, 100.00, 80.00, 1680.00, 'CONFIRMED', NOW());

INSERT INTO `booking_passengers` (`booking_id`, `seat_number`, `passenger_name`, `passenger_age`, `passenger_gender`) VALUES
(1, 'L2', 'Rahul Sharma', 28, 'male'),
(1, 'L7', 'Sneha Sharma', 26, 'female');

INSERT INTO `booking_seats` (`booking_id`, `seat_number`, `price`) VALUES
(1, 'L2', 850.00),
(1, 'L7', 850.00);

INSERT INTO `payments` (`booking_id`, `transaction_ref`, `payment_method`, `payment_status`, `amount`, `currency`, `demo_notes`, `paid_at`) VALUES
(1, 'TXN-DEMO-9921448', 'UPI', 'SUCCESS', 1680.00, 'INR', 'Simulated UPI transfer approval (smartbus@demo)', NOW());

INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(2, 'Booking Confirmed!', 'Your ticket for Chennai to Bengaluru (PNR: SB-202610-8812) has been confirmed.', 'booking', 0, NOW()),
(2, 'Payment Received', 'Demo payment of ₹1680.00 via UPI was successfully completed.', 'payment', 0, NOW());

-- ---------------------------------------------------------------------
-- Reviews
-- ---------------------------------------------------------------------
INSERT INTO `reviews` (`user_id`, `trip_id`, `rating`, `comment`, `is_approved`, `created_at`) VALUES
(2, 1, 5, 'Superb journey! The sleeper berth was clean, on-time departure from Koyambedu, and smooth driving.', 1, NOW()),
(3, 2, 5, 'Clean AC Volvo bus, charging sockets were functioning, arrived 15 minutes before time at Madiwala!', 1, NOW());

-- ---------------------------------------------------------------------
-- Simulated GPS Tracking Seed for Trip 1
-- (En route from Chennai to Bengaluru near Vellore)
-- ---------------------------------------------------------------------
INSERT INTO `trip_tracking` (`trip_id`, `current_latitude`, `current_longitude`, `current_speed`, `current_location_name`, `next_stop_id`, `delay_minutes`, `status_text`, `updated_at`) VALUES
(1, 12.916500, 79.132500, 72, 'Near Vellore Bypass NH48', 4, 0, 'On Time', NOW());

-- ---------------------------------------------------------------------
-- Initial Audit Log
-- ---------------------------------------------------------------------
INSERT INTO `audit_logs` (`admin_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 'SYSTEM_INIT', 'SYSTEM', 1, 'SmartBus database initialized with seed records and schedules', '127.0.0.1', NOW());

