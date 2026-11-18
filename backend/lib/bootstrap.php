<?php
/**
 * Common bootstrap included by every API endpoint.
 * Loads the environment, applies CORS and security headers, and provides the
 * database connection and JSON request/response helpers.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Environment (.env in the backend folder; real environment variables win)
// ---------------------------------------------------------------------
function env(string $key, $default = null)
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $file = __DIR__ . '/../.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (getenv($k) === false) {
                    putenv("$k=" . trim($v, "\"'"));
                }
            }
        }
    }
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Nairobi'));
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/PayrollCalculator.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/PayrollService.php';
require_once __DIR__ . '/Renderers.php';

// ---------------------------------------------------------------------
// Database (single shared PDO connection)
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'paysphere'));
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+03:00'");
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ---------------------------------------------------------------------
// HTTP: CORS, security headers, JSON in/out, errors
// ---------------------------------------------------------------------
class ApiError extends Exception
{
    public int $status;
    public array $extra;

    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }
}

function send_headers(): void
{
    $allowed = array_map('trim', explode(',', (string)env('CORS_ORIGIN', 'http://localhost:5173')));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && (in_array($origin, $allowed, true) || in_array('*', $allowed, true))) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 600');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (env('APP_ENV') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = null, string $message = 'OK', int $status = 200): void
{
    $body = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $body['data'] = $data;
    }
    json_out($body, $status);
}

function html_out(string $html): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            throw new ApiError('Request body must be valid JSON.', 400);
        }
        $data = array_merge($_POST, $data);
    }
    return $data;
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

function action(): string
{
    return (string)($_GET['action'] ?? '');
}

/** Run an endpoint handler with uniform error handling. */
function run(callable $handler): void
{
    send_headers();
    try {
        $handler();
    } catch (ApiError $e) {
        json_out(array_merge(['success' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
    } catch (PDOException $e) {
        error_log('[PaySphere DB] ' . $e->getMessage());
        $msg = $e->getCode() === '23000'
            ? 'That record conflicts with an existing one (duplicate value).'
            : 'A database error occurred.';
        json_out(['success' => false, 'message' => $msg], $e->getCode() === '23000' ? 409 : 500);
    } catch (Throwable $e) {
        error_log('[PaySphere] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $msg = env('APP_DEBUG') === 'true' ? $e->getMessage() : 'An unexpected error occurred.';
        json_out(['success' => false, 'message' => $msg], 500);
    }
}

// ---------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------
/** Throws 422 with field errors if any required field is missing. */
function require_fields(array $data, array $fields): void
{
    $errors = [];
    foreach ($fields as $f) {
        if (!isset($data[$f]) || (is_string($data[$f]) && trim($data[$f]) === '')) {
            $errors[$f] = 'This field is required.';
        }
    }
    if ($errors) {
        throw new ApiError('Please fill in all required fields.', 422, ['errors' => $errors]);
    }
}

function fail_field(string $field, string $message): void
{
    throw new ApiError($message, 422, ['errors' => [$field => $message]]);
}

function clean(?string $s, int $max = 255): ?string
{
    if ($s === null) {
        return null;
    }
    $s = trim(str_replace(chr(0), '', $s));
    return $s === '' ? null : mb_substr($s, 0, $max);
}

function valid_kra_pin(?string $pin): bool
{
    return $pin === null || $pin === '' || preg_match('/^[AP]\d{9}[A-Z]$/', strtoupper($pin)) === 1;
}

/** Normalise a Kenyan mobile number to 2547XXXXXXXX / 2541XXXXXXXX; null if invalid. */
function normalise_phone(?string $phone): ?string
{
    if ($phone === null || trim($phone) === '') {
        return null;
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
        return '254' . $m[1];
    }
    if (preg_match('/^254([17]\d{8})$/', $digits, $m)) {
        return '254' . $m[1];
    }
    if (preg_match('/^([17]\d{8})$/', $digits, $m)) {
        return '254' . $m[1];
    }
    return null;
}

function valid_date(?string $d): bool
{
    if (!$d) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Simple file-based rate limiter (per IP and bucket). */
function rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    $file = sys_get_temp_dir() . '/paysphere_rl_' . md5($bucket . '|' . client_ip());
    $now = time();
    $hits = is_readable($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $now - $t < $windowSeconds));
    if (count($hits) >= $max) {
        throw new ApiError('Too many requests. Please wait a moment and try again.', 429);
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}
