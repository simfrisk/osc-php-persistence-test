<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Env;
use App\PgSessionHandler;

ini_set('display_errors', '0');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Common cookie settings. The app is served over HTTPS on *.apps.osaas.io. */
function session_cookie(string $name): void
{
    // Reject session ids the server did not issue (session fixation).
    ini_set('session.use_strict_mode', '1');
    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Start a session for one of the three test backends.
 * valkey: phpredis handler configured in code with ini_set
 * ini:    whatever php.ini says (setup.sh writes a redis drop-in when SESSION_INI_MODE=redis)
 * pg:     custom SessionHandlerInterface on Postgres
 */
function start_backend_session(string $backend): void
{
    switch ($backend) {
        case 'valkey':
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', Env::valkeySavePath('PHPSESS_'));
            ini_set('session.gc_maxlifetime', '86400');
            // phpredis does not lock sessions by default. Turn it on if parallel requests write the session.
            ini_set('redis.session.locking_enabled', '1');
            // Default lock wait is 100 retries x 20 ms = 2 s, after which the request runs WITHOUT the lock.
            ini_set('redis.session.lock_retries', '300');
            ini_set('redis.session.lock_wait_time', '50000');
            session_cookie('VKSESS');
            break;
        case 'ini':
            session_cookie('INISESS');
            break;
        case 'pg':
            session_set_save_handler(new PgSessionHandler(Env::pdo(), 86400), true);
            session_cookie('PGSESS');
            break;
        default:
            json_out(['error' => 'unknown backend'], 404);
    }
    session_start();
}

try {
    // The platform probes /healthz.
    if ($path === '/healthz') {
        json_out(['ok' => true]);
    }

    // Session tests: /{valkey|ini|pg}/login (POST user=...), /{...}/me, /{...}/logout
    if (preg_match('#^/(valkey|ini|pg)/(login|me|logout|incr)$#', $path, $m)) {
        [$_, $backend, $action] = $m;
        $t0 = microtime(true);
        start_backend_session($backend);
        $startMs = round((microtime(true) - $t0) * 1000, 1);

        if ($action === 'login') {
            if ($method !== 'POST') {
                json_out(['error' => 'POST user=<name>'], 405);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = substr((string) ($_POST['user'] ?? 'demo'), 0, 64);
            $_SESSION['logged_in_at'] = gmdate('c');
            $_SESSION['login_host'] = gethostname();
            // A value with a NUL byte, to prove binary-safe storage.
            $_SESSION['binary'] = "a\0b";
        }
        if ($action === 'incr') {
            // Read, wait, write: shows whether parallel requests lose updates (no session locking).
            $n = (int) ($_SESSION['n'] ?? 0);
            usleep(300000);
            $_SESSION['n'] = $n + 1;
            json_out(['backend' => $backend, 'n' => $_SESSION['n']]);
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            json_out(['backend' => $backend, 'logged_out' => true]);
        }

        json_out([
            'backend' => $backend,
            'save_handler' => ini_get('session.save_handler'),
            'session_name' => session_name(),
            'session_id_prefix' => substr(session_id(), 0, 6),
            'logged_in' => isset($_SESSION['user']),
            'user' => $_SESSION['user'] ?? null,
            'logged_in_at' => $_SESSION['logged_in_at'] ?? null,
            'login_host' => $_SESSION['login_host'] ?? null,
            'n' => $_SESSION['n'] ?? 0,
            'binary_ok' => ($_SESSION['binary'] ?? null) === "a\0b",
            'served_by_host' => gethostname(),
            'strict_mode' => ini_get('session.use_strict_mode'),
            'redis_locking' => ini_get('redis.session.locking_enabled'),
            'redis_lock_retries' => ini_get('redis.session.lock_retries'),
            'session_start_ms' => $startMs,
        ]);
    }

    // Upload tests
    if ($path === '/s3' && $method === 'GET') {
        $s3 = Env::s3();
        $res = $s3->listObjectsV2(['Bucket' => Env::get('S3_BUCKET'), 'Prefix' => 'uploads/']);
        echo '<!doctype html><meta charset="utf-8"><title>Uploads</title><h1>Uploads in MinIO</h1>';
        echo '<form method="post" action="/s3/upload" enctype="multipart/form-data">'
            . '<input type="file" name="file" required> <button>Upload</button></form><ul>';
        foreach ($res['Contents'] ?? [] as $o) {
            $k = (string) $o['Key'];
            echo '<li><a href="/s3/file?key=' . h(rawurlencode($k)) . '">' . h($k) . '</a> ' . (int) $o['Size'] . ' bytes</li>';
        }
        echo '</ul>';
        exit;
    }

    if ($path === '/s3/upload' && $method === 'POST') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            json_out(['error' => 'upload failed', 'php_upload_error' => $f['error'] ?? null], 400);
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $f['name'])) ?: 'file';
        $key = 'uploads/' . gmdate('Ymd') . '/' . bin2hex(random_bytes(8)) . '-' . $safe;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
        $t0 = microtime(true);
        Env::s3()->putObject([
            'Bucket' => Env::get('S3_BUCKET'),
            'Key' => $key,
            'SourceFile' => $f['tmp_name'],
            'ContentType' => $mime,
        ]);
        json_out([
            'stored' => true,
            'key' => $key,
            'size' => (int) $f['size'],
            'sha256' => hash_file('sha256', $f['tmp_name']),
            'content_type' => $mime,
            'put_ms' => round((microtime(true) - $t0) * 1000, 1),
            'download' => '/s3/file?key=' . rawurlencode($key),
        ]);
    }

    if ($path === '/s3/file' && $method === 'GET') {
        $key = (string) ($_GET['key'] ?? '');
        if (!str_starts_with($key, 'uploads/') || str_contains($key, '..')) {
            json_out(['error' => 'bad key'], 400);
        }
        try {
            $obj = Env::s3()->getObject(['Bucket' => Env::get('S3_BUCKET'), 'Key' => $key]);
        } catch (Aws\S3\Exception\S3Exception $e) {
            json_out(['error' => 'not found'], $e->getStatusCode() === 404 ? 404 : 502);
        }
        header('Content-Type: ' . ($obj['ContentType'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) $obj['ContentLength']);
        header('Content-Disposition: attachment; filename="' . basename($key) . '"');
        $body = $obj['Body'];
        while (!$body->eof()) {
            echo $body->read(65536);
        }
        exit;
    }

    if ($path === '/status.json' || $path === '/') {
        $marker = @file_get_contents('/tmp/osc-setup-ran');
        json_out([
            'php_version' => PHP_VERSION,
            'has_redis_ext' => extension_loaded('redis'),
            'redis_ext_version' => phpversion('redis') ?: null,
            'has_pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'default_session_save_handler' => ini_get('session.save_handler'),
            'session_ini_dropin' => file_exists('/usr/local/etc/php/conf.d/zz-session-redis.ini'),
            'env_present' => array_map(
                fn ($k) => Env::get($k) !== null,
                array_combine(
                    $k = ['DATABASE_URL', 'VALKEY_HOST', 'VALKEY_PORT', 'VALKEY_PASSWORD', 'S3_ENDPOINT', 'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'S3_BUCKET', 'SESSION_INI_MODE'],
                    $k
                )
            ),
            'setup_ran_at' => $marker ? trim($marker) : null,
            'hostname' => gethostname(),
        ]);
    }

    json_out(['error' => 'not found'], 404);
} catch (Throwable $e) {
    // log_errors is off in the runner, so write to stderr, which reaches get-my-app-logs.
    file_put_contents('php://stderr', 'Unhandled: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    json_out(['error' => 'internal error', 'type' => get_class($e)], 500);
}
