<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_get(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row ?: null;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}

function db_transaction(callable $fn): mixed
{
    db()->beginTransaction();
    try {
        $result = $fn();
        db()->commit();
        return $result;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function ensure_schema(): void
{
    db_run("CREATE TABLE IF NOT EXISTS `users` (
        `id`            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username`      VARCHAR(100) NOT NULL UNIQUE,
        `email`         VARCHAR(255) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `role`          VARCHAR(50)  NOT NULL DEFAULT 'admin',
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_login`    DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `products` (
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
        `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `quote_requests` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `quote_items` (
        `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `quote_id`     INT          NOT NULL,
        `product_id`   INT,
        `product_name` VARCHAR(255) NOT NULL,
        `quantity`     INT          NOT NULL DEFAULT 1,
        FOREIGN KEY (`quote_id`) REFERENCES `quote_requests`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `projects` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `project_items` (
        `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `project_id`   INT          NOT NULL,
        `product_id`   INT,
        `product_name` VARCHAR(255) NOT NULL,
        `quantity`     INT          NOT NULL DEFAULT 1,
        FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_default_admin(): void
{
    $password = 'beijtech2024';
    $admin = db_get('SELECT id, password_hash FROM users WHERE username = ?', ['admin']);

    if (!$admin) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $email = db_get('SELECT id FROM users WHERE email = ?', ['info@beijtech.nl'])
            ? 'admin@beijtech.nl'
            : 'info@beijtech.nl';
        db_run(
            "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
            ['admin', $email, $hash]
        );
        return;
    }

    if (!password_verify($password, $admin['password_hash'])) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db_run(
            "UPDATE users SET password_hash = ?, role = 'admin' WHERE id = ?",
            [$hash, $admin['id']]
        );
    }
}

function force_default_admin(): array
{
    $password = 'beijtech2024';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $admin = db_get('SELECT * FROM users WHERE username = ?', ['admin']);

    if ($admin) {
        db_run(
            "UPDATE users SET password_hash = ?, role = 'admin' WHERE username = ?",
            [$hash, 'admin']
        );
    } else {
        $email = 'admin@beijtech.nl';
        if (db_get('SELECT id FROM users WHERE email = ?', [$email])) {
            $email = 'admin+' . time() . '@beijtech.nl';
        }

        db_run(
            "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
            ['admin', $email, $hash]
        );
    }

    $admin = db_get('SELECT * FROM users WHERE username = ?', ['admin']);
    if (!$admin) {
        throw new RuntimeException('Admin account kon niet worden aangemaakt of opgehaald.');
    }

    return $admin;
}

function maybe_seed(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    ensure_schema();

    db_run('DROP TABLE IF EXISTS `sessions`');

    // Auto-migrate: stock_quantity on products
    $col = db_get("SHOW COLUMNS FROM `products` LIKE 'stock_quantity'");
    if (!$col) {
        db_run('ALTER TABLE `products` ADD COLUMN `stock_quantity` INT NOT NULL DEFAULT 0 AFTER `sort_order`');
    }

    $demoProducts = [
        'LD Systems MAUI 28 G2 Column PA',
        'QSC K12.2 Actieve Speaker',
        'LD Systems MAUI 44 G2 Sub',
        'Showtec Compact Par 7 Tri Uplight',
        'Chauvet DJ Intimidator Spot 375Z',
        'Showtec Performer 2500 Fresnel',
        'Shure SM58 Dynamische Microfoon',
        'LD Systems U508 HHD 2 Dubbel draadloos',
        'Rookmachine 1500W',
        'Podiumtrap / Verhogingsplateau',
        'Statieven (microfoon / licht)',
        'Allen & Heath ZEDi-10FX Mixer',
    ];
    $placeholders = implode(',', array_fill(0, count($demoProducts), '?'));
    db_run(
        "DELETE p1 FROM `products` p1
         JOIN `products` p2 ON p1.`name` = p2.`name` AND p1.`id` > p2.`id`
         WHERE p1.`name` IN ($placeholders)",
        $demoProducts
    );

    $idx = db_get("SHOW INDEX FROM `products` WHERE Key_name = 'uniq_products_name'");
    if (!$idx) {
        try {
            db_run('ALTER TABLE `products` ADD UNIQUE KEY `uniq_products_name` (`name`)');
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
        }
    }

    // Auto-migrate: project_items table
    db_run("CREATE TABLE IF NOT EXISTS `project_items` (
        `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `project_id`   INT          NOT NULL,
        `product_id`   INT,
        `product_name` VARCHAR(255) NOT NULL,
        `quantity`     INT          NOT NULL DEFAULT 1,
        FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_default_admin();
}
