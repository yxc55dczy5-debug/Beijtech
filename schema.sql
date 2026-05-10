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

INSERT INTO `users` (`username`, `email`, `password_hash`, `role`)
VALUES ('admin', 'admin@beijtech.nl', '$2y$10$T/aGwzAKepYQtkUF7BxtrOgE46Ltd2s6ZeTJpidgmvVn66OcvmGai', 'admin')
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role` = 'admin';

CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(255) NOT NULL,
  `category`       VARCHAR(100) NOT NULL,
  `description`    TEXT,
  `image_path`     VARCHAR(500),
  `popular`        TINYINT(1)   NOT NULL DEFAULT 0,
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `stock_quantity` INT          NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_products_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`name`, `category`, `description`, `popular`, `active`, `sort_order`, `stock_quantity`)
VALUES
  ('LD Systems MAUI 28 G2 Column PA', 'geluid', 'Column PA-systeem — compact, krachtig geluid tot ±150 personen. Inclusief subwoofer en kabels.', 1, 1, 1, 0),
  ('QSC K12.2 Actieve Speaker', 'geluid', '2000W actieve full-range speaker. Kristalhelder geluid, ideaal als hoofd- of monitorluidspreker.', 1, 1, 2, 0),
  ('LD Systems MAUI 44 G2 Sub', 'geluid', 'Krachtige subwoofer voor diepe bas. Geschikt voor feesten en evenementen tot ±300 personen.', 1, 1, 3, 0),
  ('Showtec Compact Par 7 Tri Uplight', 'licht', 'Draadloze RGB uplight, ideaal voor sfeerverlichting langs wanden. Per stuk of per set.', 0, 1, 4, 0),
  ('Chauvet DJ Intimidator Spot 375Z', 'licht', 'Moving head spot met zoom — voor dynamische lichtshows op elk podium of dansvloer.', 1, 1, 5, 0),
  ('Showtec Performer 2500 Fresnel', 'licht', 'Krachtige theaterfresnel voor scèneverlichting, toneelspelen of productie-opnames.', 0, 1, 6, 0),
  ('Shure SM58 Dynamische Microfoon', 'microfoon', 'De industrie-standaard voor vocals. Robuust, betrouwbaar. Inclusief standaard en kabel.', 1, 1, 7, 0),
  ('LD Systems U508 HHD 2 Dubbel draadloos', 'microfoon', 'Professioneel dubbel draadloos systeem inclusief ontvanger. Ideaal voor sprekers en zangers.', 1, 1, 8, 0),
  ('Rookmachine 1500W', 'overig', 'Krachtige rookmachine voor sfeereffecten. Incl. rookvloeistof voor één avond.', 0, 1, 9, 0),
  ('Podiumtrap / Verhogingsplateau', 'overig', 'Modulaire podiumelementen, 2×1 m, instelbare hoogte 40–80 cm. Eenvoudig op te bouwen.', 0, 1, 10, 0),
  ('Statieven (microfoon / licht)', 'overig', 'Verstelbare statieven voor microfoon of lichtarmatuur. Per stuk of set.', 0, 1, 11, 0),
  ('Allen & Heath ZEDi-10FX Mixer', 'geluid', 'Compact 10-kanaals mengpaneel met ingebouwde FX. Voor kleine tot middelgrote evenementen.', 0, 1, 12, 0)
ON DUPLICATE KEY UPDATE
  `category` = VALUES(`category`),
  `description` = VALUES(`description`),
  `popular` = VALUES(`popular`),
  `active` = VALUES(`active`),
  `sort_order` = VALUES(`sort_order`),
  `stock_quantity` = VALUES(`stock_quantity`);

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

CREATE TABLE IF NOT EXISTS `projects` (
  `id`          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `naam`        VARCHAR(255) NOT NULL,
  `klant`       VARCHAR(255),
  `locatie`     VARCHAR(255),
  `date_from`   DATE         NOT NULL,
  `date_to`     DATE         NOT NULL,
  `status`      VARCHAR(50)  NOT NULL DEFAULT 'gepland',
  `opmerkingen` TEXT,
  `quote_id`    INT,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`quote_id`) REFERENCES `quote_requests`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_items` (
  `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id`   INT          NOT NULL,
  `product_id`   INT,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity`     INT          NOT NULL DEFAULT 1,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
