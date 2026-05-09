<?php
declare(strict_types=1);

function require_auth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        json_error('Niet ingelogd: geen token opgegeven.', 401);
    }
    $token = substr($header, 7);

    $session = db_get('
        SELECT s.token, s.user_id, s.expires_at,
               u.id, u.username, u.email, u.role
        FROM sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.token = ?
    ', [$token]);

    if (!$session) {
        json_error('Ongeldige sessie.', 401);
    }

    if (new DateTime($session['expires_at']) < new DateTime()) {
        db_run('DELETE FROM sessions WHERE token = ?', [$token]);
        json_error('Sessie verlopen. Log opnieuw in.', 401);
    }

    return [
        'id'       => (int)$session['user_id'],
        'username' => $session['username'],
        'email'    => $session['email'],
        'role'     => $session['role'],
        'token'    => $token,
    ];
}
