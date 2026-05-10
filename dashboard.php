<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__);
}

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

function admin_url(string $tab = 'dashboard'): string
{
    return 'dashboard.php?tab=' . rawurlencode($tab);
}

function redirect_admin(string $tab = 'dashboard'): never
{
    header('Location: ' . admin_url($tab));
    exit;
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

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        throw new RuntimeException('Ongeldige sessie-token. Vernieuw de pagina en probeer opnieuw.');
    }
}

function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    $id = null;

    if (!empty($_SESSION['admin_user_id'])) {
        $id = (int)$_SESSION['admin_user_id'];
    } elseif (!empty($_SESSION['user_id'])) {
        $id = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $id = (int)$_SESSION['admin_id'];
    }

    if (!$id) {
        $user = null;
        return null;
    }

    try {
        $user = db_get(
            'SELECT id, username, email, role, last_login FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    } catch (Throwable $e) {
        error_log('current_user fout: ' . $e->getMessage());
        $user = null;
    }

    if (!$user) {
        unset(
            $_SESSION['admin_user_id'],
            $_SESSION['user_id'],
            $_SESSION['admin_id'],
            $_SESSION['username'],
            $_SESSION['role'],
            $_SESSION['is_logged_in']
        );

        return null;
    }

    $_SESSION['admin_user_id'] = (int)$user['id'];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['admin_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['role'] = (string)($user['role'] ?? 'admin');
    $_SESSION['is_logged_in'] = true;

    return $user;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function post_bool(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

function upload_product_image(int $productId): void
{
    if (empty($_FILES['image']['tmp_name']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Afbeelding uploaden mislukt.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);

    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        throw new RuntimeException('Alleen jpg, png en webp afbeeldingen zijn toegestaan.');
    }

    $row = db_get('SELECT image_path FROM products WHERE id = ?', [$productId]);
    if ($row && !empty($row['image_path'])) {
        $old = BASE_DIR . '/' . ltrim((string)$row['image_path'], '/');
        if (is_file($old)) unlink($old);
    }

    $dir = BASE_DIR . '/uploads/products';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'product-' . time() . '-' . random_int(100000, 999999) . '.' . $extMap[$mime];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('Afbeelding opslaan mislukt.');
    }
    db_run('UPDATE products SET image_path = ?, updated_at = NOW() WHERE id = ?', ['/uploads/products/' . $filename, $productId]);
}

$tab = $_GET['tab'] ?? 'dashboard';
$validTabs = ['dashboard', 'products', 'quotes', 'projects', 'users'];
if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'logout') {
            session_destroy();
            header('Location: login.php');
            exit;
        }

        require_login();
        check_csrf();

        if ($action === 'product_save') {
            $id = (int)($_POST['id'] ?? 0);
            $values = [
                trim((string)$_POST['name']),
                $_POST['category'] ?? 'overig',
                trim((string)($_POST['description'] ?? '')),
                post_bool('popular'),
                post_bool('active'),
                (int)($_POST['sort_order'] ?? 0),
                max(0, (int)($_POST['stock_quantity'] ?? 0)),
            ];
            if ($values[0] === '') throw new RuntimeException('Productnaam is verplicht.');
            if ($id > 0) {
                db_run('UPDATE products SET name=?, category=?, description=?, popular=?, active=?, sort_order=?, stock_quantity=?, updated_at=NOW() WHERE id=?', array_merge($values, [$id]));
                upload_product_image($id);
                flash('Product bijgewerkt.');
            } else {
                $newId = db_insert('INSERT INTO products (name, category, description, popular, active, sort_order, stock_quantity) VALUES (?, ?, ?, ?, ?, ?, ?)', $values);
                upload_product_image($newId);
                flash('Product toegevoegd.');
            }
            redirect_admin('products');
        }

        if ($action === 'product_toggle') {
            $id = (int)$_POST['id'];
            db_run('UPDATE products SET active = IF(active = 1, 0, 1), updated_at = NOW() WHERE id = ?', [$id]);
            flash('Productstatus aangepast.');
            redirect_admin('products');
        }

        if ($action === 'product_delete') {
            $id = (int)$_POST['id'];
            $row = db_get('SELECT image_path FROM products WHERE id = ?', [$id]);
            if ($row && !empty($row['image_path'])) {
                $file = BASE_DIR . '/' . ltrim((string)$row['image_path'], '/');
                if (is_file($file)) unlink($file);
            }
            db_run('DELETE FROM products WHERE id = ?', [$id]);
            flash('Product verwijderd.');
            redirect_admin('products');
        }

        if ($action === 'quote_save') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'] ?? 'nieuw';
            $valid = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'geboekt', 'afgewezen'];
            if (!in_array($status, $valid, true)) $status = 'nieuw';
            db_run('UPDATE quote_requests SET status = ?, admin_notes = ? WHERE id = ?', [$status, $_POST['admin_notes'] ?? '', $id]);
            flash('Offerte-aanvraag bijgewerkt.');
            redirect_admin('quotes');
        }

        if ($action === 'quote_delete') {
            db_run('DELETE FROM quote_requests WHERE id = ?', [(int)$_POST['id']]);
            flash('Offerte-aanvraag verwijderd.');
            redirect_admin('quotes');
        }

        if ($action === 'project_save') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'gepland';
            $valid = ['gepland', 'actief', 'afgerond', 'geannuleerd'];
            if (!in_array($status, $valid, true)) $status = 'gepland';
            $values = [
                trim((string)$_POST['naam']),
                trim((string)($_POST['klant'] ?? '')),
                trim((string)($_POST['locatie'] ?? '')),
                $_POST['date_from'] ?? '',
                $_POST['date_to'] ?? '',
                $status,
                trim((string)($_POST['opmerkingen'] ?? '')),
            ];
            if ($values[0] === '' || $values[3] === '' || $values[4] === '') {
                throw new RuntimeException('Projectnaam, startdatum en einddatum zijn verplicht.');
            }
            if ($id > 0) {
                db_run('UPDATE projects SET naam=?, klant=?, locatie=?, date_from=?, date_to=?, status=?, opmerkingen=? WHERE id=?', array_merge($values, [$id]));
                flash('Project bijgewerkt.');
            } else {
                db_run('INSERT INTO projects (naam, klant, locatie, date_from, date_to, status, opmerkingen) VALUES (?, ?, ?, ?, ?, ?, ?)', $values);
                flash('Project toegevoegd.');
            }
            redirect_admin('projects');
        }

        if ($action === 'project_delete') {
            db_run('DELETE FROM projects WHERE id = ?', [(int)$_POST['id']]);
            flash('Project verwijderd.');
            redirect_admin('projects');
        }

        if ($action === 'user_save') {
            $id = (int)($_POST['id'] ?? 0);
            $username = trim((string)$_POST['username']);
            $email = trim((string)$_POST['email']);
            $role = $_POST['role'] ?: 'admin';
            $password = trim((string)($_POST['password'] ?? ''));
            if ($username === '' || $email === '') throw new RuntimeException('Gebruikersnaam en e-mail zijn verplicht.');
            if ($id > 0) {
                if ($password !== '') {
                    db_run('UPDATE users SET username=?, email=?, role=?, password_hash=? WHERE id=?', [$username, $email, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
                } else {
                    db_run('UPDATE users SET username=?, email=?, role=? WHERE id=?', [$username, $email, $role, $id]);
                }
                flash('Gebruiker bijgewerkt.');
            } else {
                if ($password === '') throw new RuntimeException('Wachtwoord is verplicht voor nieuwe gebruikers.');
                db_run('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)', [$username, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
                flash('Gebruiker toegevoegd.');
            }
            redirect_admin('users');
        }

        if ($action === 'user_delete') {
            $id = (int)$_POST['id'];
            if ($id === (int)($_SESSION['admin_user_id'] ?? 0)) throw new RuntimeException('Je kunt je eigen account niet verwijderen.');
            db_run('DELETE FROM users WHERE id = ?', [$id]);
            flash('Gebruiker verwijderd.');
            redirect_admin('users');
        }
    }
} catch (Throwable $e) {
    flash($e->getMessage(), 'err');
    redirect_admin($tab);
}

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$flash = flash();
$csrf = csrf_token();

$products = $quotes = $projects = $users = [];
$stats = ['products' => 0, 'quotes' => 0, 'projects' => 0, 'users' => 0];
$loadErrors = [];

try {
    $products = db_all('SELECT * FROM products ORDER BY sort_order ASC, id ASC');
} catch (Throwable $e) {
    $loadErrors[] = 'Producten: ' . $e->getMessage();
}

try {
    $quotes = db_all('
        SELECT qr.*, COUNT(qi.id) AS item_count
        FROM quote_requests qr
        LEFT JOIN quote_items qi ON qi.quote_id = qr.id
        GROUP BY qr.id
        ORDER BY qr.created_at DESC
    ');
} catch (Throwable $e) {
    $loadErrors[] = 'Offertes: ' . $e->getMessage();
}

try {
    $projects = db_all('SELECT * FROM projects ORDER BY date_from ASC, id ASC');
} catch (Throwable $e) {
    $loadErrors[] = 'Projecten: ' . $e->getMessage();
}

try {
    $users = db_all('SELECT id, username, email, role, created_at, last_login FROM users ORDER BY id ASC');
} catch (Throwable $e) {
    $loadErrors[] = 'Gebruikers: ' . $e->getMessage();
}

$stats = ['products' => count($products), 'quotes' => count($quotes), 'projects' => count($projects), 'users' => count($users)];

$editProduct = isset($_GET['edit_product']) ? db_get('SELECT * FROM products WHERE id = ?', [(int)$_GET['edit_product']]) : null;
$editProject = isset($_GET['edit_project']) ? db_get('SELECT * FROM projects WHERE id = ?', [(int)$_GET['edit_project']]) : null;
$editUser = isset($_GET['edit_user']) ? db_get('SELECT id, username, email, role FROM users WHERE id = ?', [(int)$_GET['edit_user']]) : null;

$catLabels = ['geluid' => 'Geluid', 'licht' => 'Licht', 'microfoon' => 'Microfoon', 'overig' => 'Overig'];
$quoteStatuses = ['nieuw' => 'Nieuw', 'in_behandeling' => 'In behandeling', 'offerte_verstuurd' => 'Offerte verstuurd', 'geboekt' => 'Geboekt', 'afgewezen' => 'Afgewezen'];
$projectStatuses = ['gepland' => 'Gepland', 'actief' => 'Actief', 'afgerond' => 'Afgerond', 'geannuleerd' => 'Geannuleerd'];
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BeijTech Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#0b0b0b; color:#f8fafc; }
        .field { width:100%; border:1px solid #2a2a2a; background:#101010; color:#fff; border-radius:10px; padding:10px 12px; font-size:14px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:10px; padding:9px 13px; font-weight:800; font-size:13px; }
        .btn-gold { background:#C9A028; color:#050505; }
        .btn-dark { background:#181818; color:#f3f4f6; border:1px solid #2a2a2a; }
        .btn-red { background:#7f1d1d; color:#fecaca; border:1px solid #991b1b; }
        .panel { background:#141414; border:1px solid #2a2a2a; border-radius:14px; }
        .badge { display:inline-flex; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:800; background:#262626; color:#d4d4d4; }
        .nav-active { background:#C9A028; color:#050505; }
    </style>
</head>
<body>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-[240px_1fr]">
        <aside class="border-r border-[#252525] bg-[#0f0f0f] p-4">
            <div class="text-xl font-black mb-6"><span>BEIJ</span><span class="bg-[#C9A028] text-black px-1 ml-1">TECH</span></div>
            <nav class="space-y-2">
                <?php foreach (['dashboard' => 'Dashboard', 'projects' => 'Projecten', 'products' => 'Producten', 'quotes' => 'Offertes', 'users' => 'Gebruikers'] as $key => $label): ?>
                    <a class="btn w-full justify-start <?= $tab === $key ? 'nav-active' : 'btn-dark' ?>" href="<?= e(admin_url($key)) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="mt-8 text-sm text-gray-400">
                <p class="font-bold text-white"><?= e($user['username']) ?></p>
                <p><?= e($user['role']) ?></p>
            </div>
            <form method="post" class="mt-4">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-red w-full" type="submit">Uitloggen</button>
            </form>
        </aside>

        <main class="p-4 lg:p-8">
            <?php if ($flash): ?>
                <div class="mb-5 rounded-lg px-4 py-3 text-sm font-bold <?= $flash['type'] === 'err' ? 'bg-red-950 text-red-200' : 'bg-green-950 text-green-200' ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?php foreach ($loadErrors as $loadError): ?>
                <div class="mb-3 rounded-lg px-4 py-3 text-sm font-bold bg-red-950 text-red-200"><?= e($loadError) ?></div>
            <?php endforeach; ?>

            <?php if ($tab === 'dashboard'): ?>
                <h1 class="text-2xl font-black mb-6">Dashboard</h1>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="panel p-5"><p class="text-3xl font-black"><?= $stats['projects'] ?></p><p class="text-gray-500 text-sm">Projecten</p></div>
                    <div class="panel p-5"><p class="text-3xl font-black"><?= $stats['products'] ?></p><p class="text-gray-500 text-sm">Producten</p></div>
                    <div class="panel p-5"><p class="text-3xl font-black"><?= $stats['quotes'] ?></p><p class="text-gray-500 text-sm">Offertes</p></div>
                    <div class="panel p-5"><p class="text-3xl font-black"><?= $stats['users'] ?></p><p class="text-gray-500 text-sm">Gebruikers</p></div>
                </div>
                <section class="panel p-5 mt-6">
                    <h2 class="font-black mb-3">Laatste aanvragen</h2>
                    <?php foreach (array_slice($quotes, 0, 5) as $q): ?>
                        <div class="border-t border-[#242424] py-3 flex justify-between gap-4">
                            <span><?= e($q['naam']) ?> <span class="text-gray-500"><?= e($q['email']) ?></span></span>
                            <span class="badge"><?= e($quoteStatuses[$q['status']] ?? $q['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($tab === 'products'): ?>
                <h1 class="text-2xl font-black mb-6">Producten</h1>
                <section class="panel p-5 mb-6">
                    <h2 class="font-black mb-4"><?= $editProduct ? 'Product bewerken' : 'Product toevoegen' ?></h2>
                    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="product_save">
                        <input type="hidden" name="id" value="<?= e($editProduct['id'] ?? '') ?>">
                        <input class="field" name="name" placeholder="Naam" value="<?= e($editProduct['name'] ?? '') ?>" required>
                        <select class="field" name="category"><?php foreach ($catLabels as $k => $v): ?><option value="<?= e($k) ?>" <?= (($editProduct['category'] ?? '') === $k) ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <input class="field" type="number" name="stock_quantity" placeholder="Voorraad" value="<?= e($editProduct['stock_quantity'] ?? 0) ?>">
                        <input class="field" type="number" name="sort_order" placeholder="Volgorde" value="<?= e($editProduct['sort_order'] ?? 0) ?>">
                        <label class="field"><input type="checkbox" name="popular" <?= !empty($editProduct['popular']) ? 'checked' : '' ?>> Populair</label>
                        <label class="field"><input type="checkbox" name="active" <?= !isset($editProduct) || !empty($editProduct['active']) ? 'checked' : '' ?>> Actief</label>
                        <textarea class="field lg:col-span-2" name="description" placeholder="Omschrijving"><?= e($editProduct['description'] ?? '') ?></textarea>
                        <input class="field" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                        <button class="btn btn-gold" type="submit">Opslaan</button>
                        <?php if ($editProduct): ?><a class="btn btn-dark" href="<?= e(admin_url('products')) ?>">Annuleren</a><?php endif; ?>
                    </form>
                </section>
                <div class="panel overflow-hidden">
                    <?php foreach ($products as $p): ?>
                        <div class="p-4 border-b border-[#242424] flex flex-col lg:flex-row lg:items-center gap-3 justify-between">
                            <div>
                                <p class="font-bold"><?= e($p['name']) ?></p>
                                <p class="text-sm text-gray-500"><?= e($catLabels[$p['category']] ?? $p['category']) ?> · voorraad <?= e($p['stock_quantity']) ?> · volgorde <?= e($p['sort_order']) ?></p>
                            </div>
                            <div class="flex gap-2 flex-wrap">
                                <span class="badge"><?= $p['active'] ? 'Actief' : 'Verborgen' ?></span>
                                <a class="btn btn-dark" href="dashboard.php?tab=products&edit_product=<?= (int)$p['id'] ?>">Bewerken</a>
                                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="product_toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-dark" type="submit"><?= $p['active'] ? 'Verbergen' : 'Activeren' ?></button></form>
                                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-red" type="submit">Verwijderen</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'quotes'): ?>
                <h1 class="text-2xl font-black mb-6">Offertes</h1>
                <div class="space-y-4">
                    <?php foreach ($quotes as $q): $items = db_all('SELECT * FROM quote_items WHERE quote_id = ?', [$q['id']]); ?>
                        <section class="panel p-5">
                            <div class="flex flex-col lg:flex-row justify-between gap-4">
                                <div>
                                    <h2 class="font-black"><?= e($q['naam']) ?></h2>
                                    <p class="text-sm text-gray-500"><?= e($q['email']) ?> · <?= e($q['telefoon'] ?? '') ?> · <?= e($q['locatie'] ?? '') ?></p>
                                    <p class="text-sm text-gray-400 mt-2"><?= e($q['opmerkingen'] ?? '') ?></p>
                                    <?php foreach ($items as $item): ?><p class="text-xs text-gray-500 mt-1"><?= e($item['quantity']) ?>x <?= e($item['product_name']) ?></p><?php endforeach; ?>
                                </div>
                                <form method="post" class="space-y-2 min-w-[260px]">
                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="quote_save">
                                    <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                                    <select class="field" name="status"><?php foreach ($quoteStatuses as $k => $v): ?><option value="<?= e($k) ?>" <?= $q['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                                    <textarea class="field" name="admin_notes" placeholder="Interne notities"><?= e($q['admin_notes'] ?? '') ?></textarea>
                                    <button class="btn btn-gold" type="submit">Opslaan</button>
                                </form>
                                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="quote_delete"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><button class="btn btn-red" type="submit">Verwijderen</button></form>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'projects'): ?>
                <h1 class="text-2xl font-black mb-6">Projecten</h1>
                <section class="panel p-5 mb-6">
                    <h2 class="font-black mb-4"><?= $editProject ? 'Project bewerken' : 'Project toevoegen' ?></h2>
                    <form method="post" class="grid grid-cols-1 lg:grid-cols-4 gap-3">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="project_save">
                        <input type="hidden" name="id" value="<?= e($editProject['id'] ?? '') ?>">
                        <input class="field" name="naam" placeholder="Projectnaam" value="<?= e($editProject['naam'] ?? '') ?>" required>
                        <input class="field" name="klant" placeholder="Klant" value="<?= e($editProject['klant'] ?? '') ?>">
                        <input class="field" name="locatie" placeholder="Locatie" value="<?= e($editProject['locatie'] ?? '') ?>">
                        <select class="field" name="status"><?php foreach ($projectStatuses as $k => $v): ?><option value="<?= e($k) ?>" <?= (($editProject['status'] ?? '') === $k) ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <input class="field" type="date" name="date_from" value="<?= e($editProject['date_from'] ?? '') ?>" required>
                        <input class="field" type="date" name="date_to" value="<?= e($editProject['date_to'] ?? '') ?>" required>
                        <textarea class="field lg:col-span-2" name="opmerkingen" placeholder="Opmerkingen"><?= e($editProject['opmerkingen'] ?? '') ?></textarea>
                        <button class="btn btn-gold" type="submit">Opslaan</button>
                        <?php if ($editProject): ?><a class="btn btn-dark" href="<?= e(admin_url('projects')) ?>">Annuleren</a><?php endif; ?>
                    </form>
                </section>
                <div class="panel overflow-hidden">
                    <?php foreach ($projects as $p): ?>
                        <div class="p-4 border-b border-[#242424] flex flex-col lg:flex-row lg:items-center gap-3 justify-between">
                            <div><p class="font-bold"><?= e($p['naam']) ?></p><p class="text-sm text-gray-500"><?= e($p['date_from']) ?> t/m <?= e($p['date_to']) ?> · <?= e($p['klant'] ?? '') ?> · <?= e($p['locatie'] ?? '') ?></p></div>
                            <div class="flex gap-2 flex-wrap"><span class="badge"><?= e($projectStatuses[$p['status']] ?? $p['status']) ?></span><a class="btn btn-dark" href="dashboard.php?tab=projects&edit_project=<?= (int)$p['id'] ?>">Bewerken</a><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="project_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-red" type="submit">Verwijderen</button></form></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'users'): ?>
                <h1 class="text-2xl font-black mb-6">Gebruikers</h1>
                <section class="panel p-5 mb-6">
                    <h2 class="font-black mb-4"><?= $editUser ? 'Gebruiker bewerken' : 'Gebruiker toevoegen' ?></h2>
                    <form method="post" class="grid grid-cols-1 lg:grid-cols-4 gap-3">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="user_save">
                        <input type="hidden" name="id" value="<?= e($editUser['id'] ?? '') ?>">
                        <input class="field" name="username" placeholder="Gebruikersnaam" value="<?= e($editUser['username'] ?? '') ?>" required>
                        <input class="field" type="email" name="email" placeholder="E-mail" value="<?= e($editUser['email'] ?? '') ?>" required>
                        <select class="field" name="role"><option value="admin" <?= (($editUser['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option><option value="user" <?= (($editUser['role'] ?? '') === 'user') ? 'selected' : '' ?>>User</option></select>
                        <input class="field" type="password" name="password" placeholder="<?= $editUser ? 'Nieuw wachtwoord optioneel' : 'Wachtwoord' ?>">
                        <button class="btn btn-gold" type="submit">Opslaan</button>
                        <?php if ($editUser): ?><a class="btn btn-dark" href="<?= e(admin_url('users')) ?>">Annuleren</a><?php endif; ?>
                    </form>
                </section>
                <div class="panel overflow-hidden">
                    <?php foreach ($users as $u): ?>
                        <div class="p-4 border-b border-[#242424] flex flex-col lg:flex-row lg:items-center gap-3 justify-between">
                            <div><p class="font-bold"><?= e($u['username']) ?> <?= (int)$u['id'] === (int)$user['id'] ? '<span class="badge">Jij</span>' : '' ?></p><p class="text-sm text-gray-500"><?= e($u['email']) ?> · <?= e($u['role']) ?></p></div>
                            <div class="flex gap-2 flex-wrap"><a class="btn btn-dark" href="dashboard.php?tab=users&edit_user=<?= (int)$u['id'] ?>">Bewerken</a><?php if ((int)$u['id'] !== (int)$user['id']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-red" type="submit">Verwijderen</button></form><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
