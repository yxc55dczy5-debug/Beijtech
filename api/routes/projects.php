<?php
declare(strict_types=1);

function handle_projects(string $method, string $sub): void
{
    $VALID_STATUSES = ['gepland', 'actief', 'afgerond', 'geannuleerd'];

    // GET / — auth, all projects
    if ($method === 'GET' && $sub === '') {
        require_auth();
        json_ok(db_all('SELECT * FROM projects ORDER BY date_from ASC, id ASC'));
    }

    // POST / — auth, create project
    if ($method === 'POST' && $sub === '') {
        require_auth();
        $b = get_body();

        if (!($b['naam'] ?? '') || !($b['date_from'] ?? '') || !($b['date_to'] ?? '')) {
            json_error('Naam, startdatum en einddatum zijn verplicht.', 400);
        }

        $status = $b['status'] ?? 'gepland';
        if (!in_array($status, $VALID_STATUSES, true)) $status = 'gepland';

        $id = db_insert(
            'INSERT INTO projects (naam, klant, locatie, date_from, date_to, status, opmerkingen, quote_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $b['naam'],
                $b['klant']       ?? null,
                $b['locatie']     ?? null,
                $b['date_from'],
                $b['date_to'],
                $status,
                $b['opmerkingen'] ?? null,
                isset($b['quote_id']) ? (int)$b['quote_id'] : null,
            ]
        );
        json_ok(db_get('SELECT * FROM projects WHERE id = ?', [$id]), 201);
    }

    // PUT /:id — auth, update project
    if ($method === 'PUT' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT * FROM projects WHERE id = ?', [$id]);
        if (!$row) json_error('Project niet gevonden.', 404);

        $b      = get_body();
        $status = $b['status'] ?? $row['status'];
        if (!in_array($status, $VALID_STATUSES, true)) $status = $row['status'];

        db_run(
            'UPDATE projects SET naam=?, klant=?, locatie=?, date_from=?, date_to=?, status=?, opmerkingen=? WHERE id=?',
            [
                $b['naam']        ?? $row['naam'],
                $b['klant']       ?? $row['klant'],
                $b['locatie']     ?? $row['locatie'],
                $b['date_from']   ?? $row['date_from'],
                $b['date_to']     ?? $row['date_to'],
                $status,
                array_key_exists('opmerkingen', $b) ? $b['opmerkingen'] : $row['opmerkingen'],
                $id,
            ]
        );
        json_ok(db_get('SELECT * FROM projects WHERE id = ?', [$id]));
    }

    // DELETE /:id — auth, delete project
    if ($method === 'DELETE' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT id FROM projects WHERE id = ?', [$id]);
        if (!$row) json_error('Project niet gevonden.', 404);

        db_run('DELETE FROM projects WHERE id = ?', [$id]);
        json_ok(['message' => 'Project verwijderd.']);
    }

    json_error('Route niet gevonden.', 404);
}
