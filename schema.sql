-- BeijTech Verhuurshop — MySQL schema
-- Importeer dit bestand via phpMyAdmin: Database → Importeren
-- Maak eerst een lege database aan (bijv. "beijtech") en selecteer die.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(100) NOT NULL UNIQUE,
  `email`         VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          VARCHAR(50)  NOT NULL DEFAULT 'admin',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`    DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `token`      VARCHAR(64)  NOT NULL PRIMARY KEY,
  `user_id`    INT          NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id`          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(255) NOT NULL,
  `category`    VARCHAR(100) NOT NULL,
  `description` TEXT,
  `image_path`  VARCHAR(500),
  `popular`     TINYINT(1)   NOT NULL DEFAULT 0,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_requests` (
  `id`              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `naam`            VARCHAR(255) NOT NULL,
  `email`           VARCHAR(255) NOT NULL,
  `telefoon`        VARCHAR(50),
  `bedrijf`         VARCHAR(255),
  `locatie`         VARCHAR(255),
  `date_from`       DATE,
  `date_to`         DATE,
  `levering_heen`   TINYINT(1)   DEFAULT 0,
  `levering_retour` TINYINT(1)   DEFAULT 0,
  `opmerkingen`     TEXT,
  `status`          VARCHAR(50)  NOT NULL DEFAULT 'nieuw',
  `admin_notes`     TEXT,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_items` (
  `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `quote_id`     INT          NOT NULL,
  `product_id`   INT,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity`     INT          NOT NULL DEFAULT 1,
  FOREIGN KEY (`quote_id`) REFERENCES `quote_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
