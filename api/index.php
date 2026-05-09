<?php
declare(strict_types=1);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Global helpers ────────────────────────────────────────────────────────────

function json_ok(mixed $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_body(): array
{
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true) ?? [];
    }
    return $body;
}

// Absolute path to the website root (public_html), one level up from api/
define('BASE_DIR', dirname(__DIR__));

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    json_error('api/config.php niet gevonden. Kopieer config.example.php naar config.php en vul je databasegegevens in.', 500);
}

require_once $configFile;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

try {
    maybe_seed();
} catch (Throwable $e) {
    json_error('Database fout bij opstarten: ' . $e->getMessage(), 500);
}

// ── Routing ───────────────────────────────────────────────────────────────────
// Strip the /api prefix so routes work regardless of subdirectory depth.

$method    = $_SERVER['REQUEST_METHOD'];
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // e.g. /api
$uri       = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$sub       = substr($uri, strlen($scriptDir));             // strip /api
$sub       = '/' . ltrim($sub ?? '', '/');
$sub       = ($sub === '/') ? '' : rtrim($sub, '/');

try {
    if (str_starts_with($sub, '/auth')) {
        require_once __DIR__ . '/routes/auth.php';
        handle_auth($method, substr($sub, 5));
    } elseif (str_starts_with($sub, '/products')) {
        require_once __DIR__ . '/routes/products.php';
        handle_products($method, substr($sub, 9));
    } elseif (str_starts_with($sub, '/quotes')) {
        require_once __DIR__ . '/routes/quotes.php';
        handle_quotes($method, substr($sub, 7));
    } elseif (str_starts_with($sub, '/projects')) {
        require_once __DIR__ . '/routes/projects.php';
        handle_projects($method, substr($sub, 9));
    } else {
        json_error('Route niet gevonden.', 404);
    }
} catch (PDOException $e) {
    json_error('Database fout: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    json_error('Server fout: ' . $e->getMessage(), 500);
}
