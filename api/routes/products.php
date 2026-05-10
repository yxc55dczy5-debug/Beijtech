<?php
declare(strict_types=1);

function handle_products(string $method, string $sub): void
{
    if ($method === 'GET' && $sub === '') {
        json_ok(db_all('SELECT * FROM products WHERE active = 1 ORDER BY sort_order ASC, id ASC'));
    }

    json_error('Route niet gevonden.', 404);
}
