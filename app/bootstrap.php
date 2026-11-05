<?php
// Loaded first by every entry point. Pure setup: no output.

if (defined('APP_ROOT')) {
    return;
}
define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/env.php';
env_load(APP_ROOT . '/.env');

date_default_timezone_set((string)env('APP_TZ', 'Africa/Blantyre'));

ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = APP_ROOT . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0700, true);
}
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/app.log');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/signing.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tenants.php';
require_once __DIR__ . '/view.php';

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
}

// REMOTE_ADDR only. Forwarded-for headers are client-controlled and are never trusted.
function client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// Entry point for HTML pages.
function web_boot(): void
{
    set_exception_handler(function ($e) {
        error_log('web exception: ' . get_class($e) . ': ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'Something went wrong.';
        exit;
    });
    send_security_headers();
    session_boot();
}
