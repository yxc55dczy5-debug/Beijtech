<?php
declare(strict_types=1);

function handle_quotes(string $method, string $sub): void
{
    $VALID_STATUSES = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'geboekt', 'afgewezen'];

    // POST / — public, submit quote request
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
                            (int)($item['quantity'] ?? 1),
                        ]
                    );
                }
            }

            return $id;
        });

        json_ok(['id' => $quoteId, 'message' => 'Aanvraag ontvangen'], 201);
    }

    // GET / — auth, all quotes with item count
    if ($method === 'GET' && $sub === '') {
        require_auth();
        json_ok(db_all('
            SELECT qr.*, COUNT(qi.id) AS item_count
            FROM quote_requests qr
            LEFT JOIN quote_items qi ON qi.quote_id = qr.id
            GROUP BY qr.id
            ORDER BY qr.created_at DESC
        '));
    }

    // GET /:id — auth, single quote with items
    if ($method === 'GET' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id    = (int)$m[1];
        $quote = db_get('SELECT * FROM quote_requests WHERE id = ?', [$id]);
        if (!$quote) json_error('Aanvraag niet gevonden.', 404);

        $quote['items'] = db_all('SELECT * FROM quote_items WHERE quote_id = ?', [$id]);
        json_ok($quote);
    }

    // PUT /:id/status — auth, update status and/or admin_notes
    if ($method === 'PUT' && preg_match('#^/(\d+)/status$#', $sub, $m)) {
        require_auth();
        $id     = (int)$m[1];
        $b      = get_body();
        $status = $b['status'] ?? null;

        if ($status !== null && !in_array($status, $VALID_STATUSES, true)) {
            json_error('Ongeldige status. Geldige waarden: ' . implode(', ', $VALID_STATUSES) . '.', 400);
        }
        if ($status === null && !array_key_exists('admin_notes', $b)) {
            json_error('Status of admin_notes is verplicht.', 400);
        }

        $quote = db_get('SELECT * FROM quote_requests WHERE id = ?', [$id]);
        if (!$quote) json_error('Aanvraag niet gevonden.', 404);

        $newStatus = $status ?? $quote['status'];
        $newNotes  = array_key_exists('admin_notes', $b) ? $b['admin_notes'] : $quote['admin_notes'];

        db_run(
            'UPDATE quote_requests SET status=?, admin_notes=? WHERE id=?',
            [$newStatus, $newNotes, $id]
        );

        $updated          = db_get('SELECT * FROM quote_requests WHERE id = ?', [$id]);
        $updated['items'] = db_all('SELECT * FROM quote_items WHERE quote_id = ?', [$id]);
        json_ok($updated);
    }

    // DELETE /:id — auth, delete quote (FK cascade removes items)
    if ($method === 'DELETE' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id    = (int)$m[1];
        $quote = db_get('SELECT id FROM quote_requests WHERE id = ?', [$id]);
        if (!$quote) json_error('Aanvraag niet gevonden.', 404);

        db_run('DELETE FROM quote_requests WHERE id = ?', [$id]);
        json_ok(['message' => 'Aanvraag verwijderd.']);
    }

    json_error('Route niet gevonden.', 404);
}
