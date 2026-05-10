<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

const LOGIN_DEBUG = true;

try {
    maybe_seed();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database fout bij opstarten: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function debug_stop(array $data): never
{
    if (!LOGIN_DEBUG) {
        exit;
    }

    echo '<pre style="background:#111;color:#0f0;padding:20px;font-size:14px;white-space:pre-wrap;">';
    print_r($data);
    echo '</pre>';
    exit;
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type
        ];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function column_exists(string $table, string $column): bool
{
    try {
        $row = db_get("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ", [$table, $column]);

        return !empty($row) && (int)$row['total'] > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (
    !empty($_SESSION['admin_user_id']) ||
    !empty($_SESSION['user_id']) ||
    !empty($_SESSION['admin_id'])
) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $identifier = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Vul gebruikersnaam en wachtwoord in.');
        }

        $debug = [
            'stap' => 'start login',
            'identifier' => $identifier,
            'password_filled' => $password !== '',
        ];

        $isDefaultLogin = strtolower($identifier) === 'admin' && $password === 'beijtech2024';

        $debug['is_default_login'] = $isDefaultLogin ? 'ja' : 'nee';

        if ($isDefaultLogin) {
            $user = force_default_admin();
        } else {
            $user = db_get(
                'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
                [$identifier, $identifier]
            );
        }

        $debug['user_found'] = !empty($user) ? 'ja' : 'nee';
        $debug['user'] = $user;

        if (!$user) {
            debug_stop($debug + [
                'fout' => 'Gebruiker niet gevonden in users-tabel.'
            ]);
        }

        if (!$isDefaultLogin) {
            $hash = (string)($user['password_hash'] ?? '');

            $debug['password_hash_exists'] = $hash !== '' ? 'ja' : 'nee';
            $debug['password_verify'] = password_verify($password, $hash) ? 'ja' : 'nee';

            if ($hash === '' || !password_verify($password, $hash)) {
                debug_stop($debug + [
                    'fout' => 'Wachtwoord klopt niet of password_hash is leeg.'
                ]);
            }
        }

        $userId = (int)$user['id'];

        session_regenerate_id(true);

        $_SESSION['admin_user_id'] = $userId;
        $_SESSION['user_id'] = $userId;
        $_SESSION['admin_id'] = $userId;
        $_SESSION['username'] = (string)($user['username'] ?? '');
        $_SESSION['role'] = (string)($user['role'] ?? 'admin');
        $_SESSION['is_logged_in'] = true;

        $debug['session_after_login'] = $_SESSION;

        $debug['last_login_column_exists'] = column_exists('users', 'last_login') ? 'ja' : 'nee';

        if (!column_exists('users', 'last_login')) {
            debug_stop($debug + [
                'fout' => 'Kolom last_login bestaat niet in users-tabel.',
                'oplossing' => 'Voer deze SQL uit: ALTER TABLE users ADD COLUMN last_login DATETIME NULL DEFAULT NULL;'
            ]);
        }

        db_run(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$userId]
        );

        $checkUser = db_get(
            'SELECT id, username, email, last_login FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );

        $debug['after_update_user'] = $checkUser;

        if (empty($checkUser['last_login'])) {
            debug_stop($debug + [
                'fout' => 'UPDATE uitgevoerd, maar last_login blijft leeg.',
                'mogelijke_oorzaak' => 'db_run() voert query niet goed uit, of je kijkt in een andere database/tabel.'
            ]);
        }

        if (LOGIN_DEBUG) {
            debug_stop($debug + [
                'resultaat' => 'Login en last_login update werken. Zet LOGIN_DEBUG nu op false.'
            ]);
        }

        header('Location: dashboard.php');
        exit;

    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        header('Location: login.php');
        exit;
    }
}

$flash = flash();
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BeijTech Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0b0b0b;
            color: #f8fafc;
        }

        .field {
            width: 100%;
            border: 1px solid #2a2a2a;
            background: #101010;
            color: #fff;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 800;
            font-size: 14px;
        }

        .btn-gold {
            background: #C9A028;
            color: #050505;
        }

        .panel {
            background: #141414;
            border: 1px solid #2a2a2a;
            border-radius: 14px;
        }
    </style>
</head>
<body>
    <main class="min-h-screen flex items-center justify-center px-4">
        <section class="w-full max-w-sm panel p-7">
            <div class="text-center mb-7">
                <div class="inline-block text-2xl font-black">
                    <span>BEIJ</span><span class="bg-[#C9A028] text-black px-1.5 py-0.5 ml-1">TECH</span>
                </div>
                <p class="text-gray-500 text-sm mt-2">Beheer Login</p>
            </div>

            <?php if ($flash): ?>
                <div class="mb-4 rounded-lg px-3 py-2 text-sm font-bold <?= $flash['type'] === 'err' ? 'bg-red-950 text-red-200' : 'bg-green-950 text-green-200' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="space-y-4">
                <div>
                    <label class="block text-xs uppercase font-bold text-gray-400 mb-1.5">
                        Gebruikersnaam of e-mail
                    </label>
                    <input class="field" name="username" value="admin" autocomplete="username" required>
                </div>

                <div>
                    <label class="block text-xs uppercase font-bold text-gray-400 mb-1.5">
                        Wachtwoord
                    </label>
                    <input class="field" type="password" name="password" autocomplete="current-password" required>
                </div>

                <button class="btn btn-gold w-full" type="submit">
                    Inloggen
                </button>
            </form>

            <p class="text-gray-600 text-xs text-center mt-4">
                Standaard: <span class="font-mono">admin / beijtech2024</span>
            </p>
        </section>
    </main>
</body>
</html>
