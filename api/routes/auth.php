<?php
declare(strict_types=1);

function handle_auth(string $method, string $sub): void
{
    // POST /login
    if ($method === 'POST' && $sub === '/login') {
        $b          = get_body();
        $password   = $b['password'] ?? '';
        $identifier = $b['username'] ?? $b['email'] ?? '';

        if (!$password || !$identifier) {
            json_error('Gebruikersnaam/e-mail en wachtwoord zijn verplicht.', 400);
        }

        $user = db_get('SELECT * FROM users WHERE username = ? OR email = ?', [$identifier, $identifier]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_error('Ongeldige inloggegevens.', 401);
        }

        $token     = bin2hex(random_bytes(32));
        $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

        db_run('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)', [$token, $user['id'], $expiresAt]);
        db_run('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

        json_ok([
            'token' => $token,
            'user'  => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
            ],
        ]);
    }

    // POST /logout
    if ($method === 'POST' && $sub === '/logout') {
        $auth = require_auth();
        db_run('DELETE FROM sessions WHERE token = ?', [$auth['token']]);
        json_ok(['message' => 'Uitgelogd.']);
    }

    // GET /me
    if ($method === 'GET' && $sub === '/me') {
        $auth = require_auth();
        json_ok(db_get('SELECT id, username, email, role, last_login FROM users WHERE id = ?', [$auth['id']]));
    }

    // GET /users
    if ($method === 'GET' && $sub === '/users') {
        require_auth();
        json_ok(db_all('SELECT id, username, email, role, created_at, last_login FROM users ORDER BY id'));
    }

    // POST /users
    if ($method === 'POST' && $sub === '/users') {
        require_auth();
        $b = get_body();

        if (!($b['username'] ?? '') || !($b['email'] ?? '') || !($b['password'] ?? '')) {
            json_error('Gebruikersnaam, e-mail en wachtwoord zijn verplicht.', 400);
        }

        try {
            $hash = password_hash($b['password'], PASSWORD_BCRYPT);
            $id   = db_insert(
                'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
                [$b['username'], $b['email'], $hash, $b['role'] ?? 'admin']
            );
            json_ok(db_get('SELECT id, username, email, role, created_at FROM users WHERE id = ?', [$id]), 201);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                json_error('Gebruikersnaam of e-mail is al in gebruik.', 409);
            }
            throw $e;
        }
    }

    // PUT /users/:id
    if ($method === 'PUT' && preg_match('#^/users/(\d+)$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $b   = get_body();
        $row = db_get('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$row) json_error('Gebruiker niet gevonden.', 404);

        try {
            $username = $b['username'] ?? $row['username'];
            $email    = $b['email']    ?? $row['email'];
            $role     = $b['role']     ?? $row['role'];

            if (!empty($b['password'])) {
                $hash = password_hash($b['password'], PASSWORD_BCRYPT);
                db_run('UPDATE users SET username=?, email=?, role=?, password_hash=? WHERE id=?', [$username, $email, $role, $hash, $id]);
            } else {
                db_run('UPDATE users SET username=?, email=?, role=? WHERE id=?', [$username, $email, $role, $id]);
            }

            json_ok(db_get('SELECT id, username, email, role, created_at, last_login FROM users WHERE id = ?', [$id]));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                json_error('Gebruikersnaam of e-mail is al in gebruik.', 409);
            }
            throw $e;
        }
    }

    // DELETE /users/:id
    if ($method === 'DELETE' && preg_match('#^/users/(\d+)$#', $sub, $m)) {
        $auth = require_auth();
        $id   = (int)$m[1];

        if ($id === $auth['id']) json_error('Je kunt je eigen account niet verwijderen.', 400);

        $row = db_get('SELECT id FROM users WHERE id = ?', [$id]);
        if (!$row) json_error('Gebruiker niet gevonden.', 404);

        db_run('DELETE FROM users WHERE id = ?', [$id]);
        json_ok(['message' => 'Gebruiker verwijderd.']);
    }

    json_error('Route niet gevonden.', 404);
}
