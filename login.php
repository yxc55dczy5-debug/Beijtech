<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

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

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function go_dashboard(string $message = 'Login gelukt. Dashboard openen...'): never
{
    session_write_close();
    header('Location: dashboard.php');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="0; url=dashboard.php"><title>Dashboard openen</title>';
    echo '<script>window.location.replace("dashboard.php");</script>';
    echo '<style>body{font-family:system-ui;background:#0b0b0b;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0}.box{background:#141414;border:1px solid #2a2a2a;border-radius:14px;padding:28px;max-width:380px}.btn{display:block;background:#C9A028;color:#000;text-align:center;padding:12px 14px;border-radius:10px;font-weight:800;text-decoration:none;margin-top:16px}</style>';
    echo '</head><body><div class="box"><h1>' . e($message) . '</h1><p>Als je niet automatisch doorgaat, open het dashboard handmatig.</p><a class="btn" href="dashboard.php">Open dashboard</a></div></body></html>';
    exit;
}

if (!empty($_SESSION['admin_user_id'])) {
    go_dashboard('Je bent al ingelogd.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $identifier = trim((string)($_POST['username'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Vul gebruikersnaam en wachtwoord in.');
        }

        $isDefault = strtolower($identifier) === 'admin' && $password === 'beijtech2024';
        $user = $isDefault
            ? force_default_admin()
            : db_get('SELECT * FROM users WHERE username = ? OR email = ?', [$identifier, $identifier]);

        if (!$user || (!$isDefault && !password_verify($password, $user['password_hash']))) {
            throw new RuntimeException('Onjuiste inloggegevens.');
        }

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int)$user['id'];
        db_run('UPDATE users SET last_login = NOW() WHERE id = ?', [(int)$user['id']]);

        go_dashboard('Ingelogd als ' . $user['username'] . '.');
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
        body { font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#0b0b0b; color:#f8fafc; }
        .field { width:100%; border:1px solid #2a2a2a; background:#101010; color:#fff; border-radius:10px; padding:10px 12px; font-size:14px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; border-radius:10px; padding:11px 14px; font-weight:800; font-size:14px; }
        .btn-gold { background:#C9A028; color:#050505; }
        .panel { background:#141414; border:1px solid #2a2a2a; border-radius:14px; }
    </style>
</head>
<body>
    <main class="min-h-screen flex items-center justify-center px-4">
        <section class="w-full max-w-sm panel p-7">
            <div class="text-center mb-7">
                <div class="inline-block text-2xl font-black"><span>BEIJ</span><span class="bg-[#C9A028] text-black px-1.5 py-0.5 ml-1">TECH</span></div>
                <p class="text-gray-500 text-sm mt-2">Beheer Login</p>
            </div>

            <?php if ($flash): ?>
                <div class="mb-4 rounded-lg px-3 py-2 text-sm font-bold <?= $flash['type'] === 'err' ? 'bg-red-950 text-red-200' : 'bg-green-950 text-green-200' ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" class="space-y-4">
                <div>
                    <label class="block text-xs uppercase font-bold text-gray-400 mb-1.5">Gebruikersnaam of e-mail</label>
                    <input class="field" name="username" value="admin" autocomplete="username" required>
                </div>
                <div>
                    <label class="block text-xs uppercase font-bold text-gray-400 mb-1.5">Wachtwoord</label>
                    <input class="field" type="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-gold w-full" type="submit">Inloggen</button>
            </form>

            <p class="text-gray-600 text-xs text-center mt-4">Standaard: <span class="font-mono">admin / beijtech2024</span></p>
        </section>
    </main>
</body>
</html>
