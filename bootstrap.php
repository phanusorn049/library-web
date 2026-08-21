<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function database_config(): array
{
    $fileConfig = [];
    $configFile = __DIR__ . '/db_config.php';
    if (is_file($configFile)) {
        $loadedConfig = require $configFile;
        if (is_array($loadedConfig)) {
            $fileConfig = $loadedConfig;
        }
    }

    return [
        'host' => getenv('DB_HOST') ?: ($fileConfig['host'] ?? 'localhost'),
        'user' => getenv('DB_USER') ?: ($fileConfig['user'] ?? 'root'),
        'password' => getenv('DB_PASS') ?: ($fileConfig['password'] ?? 'root'),
        'database' => getenv('DB_NAME') ?: ($fileConfig['database'] ?? 'library_project'),
        'port' => (int)(getenv('DB_PORT') ?: ($fileConfig['port'] ?? 8889)),
        'ssl' => getenv('DB_SSL') === '1' || ($fileConfig['ssl'] ?? false) === true,
    ];
}

function db(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = database_config();

    $connection = mysqli_init();
    if ($config['ssl']) {
        mysqli_ssl_set($connection, null, null, __DIR__ . '/certs/cacert.pem', null, null);
    }
    mysqli_real_connect($connection, $config['host'], $config['user'], $config['password'], $config['database'], $config['port'], null, $config['ssl'] ? MYSQLI_CLIENT_SSL : 0);
    mysqli_set_charset($connection, 'utf8mb4');
    return $connection;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('คำขอไม่ถูกต้อง กรุณาลองใหม่');
    }
}

function require_login(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    return (int) $_SESSION['user_id'];
}

function require_admin(): void
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}
