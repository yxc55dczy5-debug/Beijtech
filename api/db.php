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

function maybe_seed(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // Auto-migrate: stock_quantity on products
    $col = db_get("SHOW COLUMNS FROM `products` LIKE 'stock_quantity'");
    if (!$col) {
        db_run('ALTER TABLE `products` ADD COLUMN `stock_quantity` INT NOT NULL DEFAULT 0 AFTER `sort_order`');
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

    $row = db_get('SELECT COUNT(*) AS cnt FROM users');
    if ((int)$row['cnt'] === 0) {
        $hash = password_hash('beijtech2024', PASSWORD_BCRYPT);
        db_run(
            "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
            ['admin', 'info@beijtech.nl', $hash]
        );
    }

    $row = db_get('SELECT COUNT(*) AS cnt FROM products');
    if ((int)$row['cnt'] === 0) {
        $products = [
            ['LD Systems MAUI 28 G2 Column PA',        'geluid',    'Column PA-systeem — compact, krachtig geluid tot ±150 personen. Inclusief subwoofer en kabels.',             1, 1],
            ['QSC K12.2 Actieve Speaker',               'geluid',    '2000W actieve full-range speaker. Kristalhelder geluid, ideaal als hoofd- of monitorluidspreker.',           1, 2],
            ['LD Systems MAUI 44 G2 Sub',               'geluid',    'Krachtige subwoofer voor diepe bas. Geschikt voor feesten en evenementen tot ±300 personen.',                 1, 3],
            ['Showtec Compact Par 7 Tri Uplight',       'licht',     'Draadloze RGB uplight, ideaal voor sfeerverlichting langs wanden. Per stuk of per set.',                      0, 4],
            ['Chauvet DJ Intimidator Spot 375Z',        'licht',     'Moving head spot met zoom — voor dynamische lichtshows op elk podium of dansvloer.',                          1, 5],
            ['Showtec Performer 2500 Fresnel',          'licht',     'Krachtige theaterfresnel voor scèneverlichting, toneelspelen of productie-opnames.',                          0, 6],
            ['Shure SM58 Dynamische Microfoon',         'microfoon', 'De industrie-standaard voor vocals. Robuust, betrouwbaar. Inclusief standaard en kabel.',                     1, 7],
            ['LD Systems U508 HHD 2 Dubbel draadloos', 'microfoon', 'Professioneel dubbel draadloos systeem inclusief ontvanger. Ideaal voor sprekers en zangers.',               1, 8],
            ['Rookmachine 1500W',                       'overig',    'Krachtige rookmachine voor sfeereffecten. Incl. rookvloeistof voor één avond.',                              0, 9],
            ['Podiumtrap / Verhogingsplateau',          'overig',    'Modulaire podiumelementen, 2×1 m, instelbare hoogte 40–80 cm. Eenvoudig op te bouwen.',                     0, 10],
            ['Statieven (microfoon / licht)',           'overig',    'Verstelbare statieven voor microfoon of lichtarmatuur. Per stuk of set.',                                    0, 11],
            ['Allen & Heath ZEDi-10FX Mixer',           'geluid',    'Compact 10-kanaals mengpaneel met ingebouwde FX. Voor kleine tot middelgrote evenementen.',                  0, 12],
        ];
        foreach ($products as [$name, $cat, $desc, $popular, $order]) {
            db_run(
                'INSERT INTO products (name, category, description, popular, active, sort_order) VALUES (?, ?, ?, ?, 1, ?)',
                [$name, $cat, $desc, $popular, $order]
            );
        }
    }
}
