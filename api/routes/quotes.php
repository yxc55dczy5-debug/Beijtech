<?php
declare(strict_types=1);

function handle_quotes(string $method, string $sub): void
{
    if ($method === 'POST' && $sub === '') {
        $b = get_body();

        if (!($b['naam'] ?? '') || !($b['email'] ?? '')) {
            json_error('Naam en e-mail zijn verplicht.', 400);
        }

        $quoteId = db_transaction(function () use ($b) {
            $id = db_insert(
                'INSERT INTO quote_requests
                    (naam, email, telefoon, bedrijf, locatie, date_from, date_to, levering_heen, levering_retour, opmerkingen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $b['naam'],
                    $b['email'],
                    $b['telefoon']    ?? null,
                    $b['bedrijf']     ?? null,
                    $b['locatie']     ?? null,
                    $b['date_from']   ?? null,
                    $b['date_to']     ?? null,
                    empty($b['levering_heen'])   ? 0 : 1,
                    empty($b['levering_retour']) ? 0 : 1,
                    $b['opmerkingen'] ?? null,
                ]
            );

            if (!empty($b['items']) && is_array($b['items'])) {
                foreach ($b['items'] as $item) {
                    db_run(
                        'INSERT INTO quote_items (quote_id, product_id, product_name, quantity) VALUES (?, ?, ?, ?)',
                        [
                            $id,
                            $item['product_id']   ?? null,
                            $item['product_name'] ?? 'Onbekend product',
                            max(1, (int)($item['quantity'] ?? 1)),
                        ]
                    );
                }
            }

            return $id;
        });

        json_ok(['id' => $quoteId, 'message' => 'Aanvraag ontvangen'], 201);
    }

    json_error('Route niet gevonden.', 404);
}
