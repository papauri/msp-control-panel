<?php
// Admin authentication: password + TOTP, lockout, rate limit, hardened session.

const AUTH_IDLE_SECONDS = 1800;      // 30 min idle timeout
const AUTH_MAX_SESSION_SECONDS = 43200; // 12 h absolute
const AUTH_MAX_FAILURES = 5;
const AUTH_LOCK_MINUTES = 15;
// Verified against when the e-mail is unknown, so timing does not reveal valid accounts.
// Cost must match every password_hash() call (bcrypt cost 10) so unknown users verify in the same time.
const AUTH_DUMMY_HASH = '$2y$10$mOZVDlz8IM98liPhXhrc0u9/sGkbkvddNirgPkZURGHGtKsGyQgD6';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = APP_ROOT . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '3600');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name((string)env('SESSION_NAME', 'mspcp_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function auth_destroy_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => is_https(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }
}

// Returns true on success. Every failure path is indistinguishable to the caller.
function auth_attempt(PDO $pdo, string $email, string $password, string $code): bool
{
    $ip = client_ip();
    if (!rate_limit_hit($pdo, 'login-ip:' . $ip, 10, 900)) {
        return false;
    }
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || strlen($password) > 1024) {
        return false;
    }

    $st = $pdo->prepare(
        'SELECT id, email, password_hash, totp_secret,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
         FROM admins WHERE email = ?'
    );
    $st->execute([$email]);
    $admin = $st->fetch();

    $pwOk = password_verify($password, $admin ? $admin['password_hash'] : AUTH_DUMMY_HASH);
    $step = $admin ? totp_verify($admin['totp_secret'], $code, time(), 1) : false;

    if ($admin && !(int)$admin['is_locked'] && $pwOk && $step !== false) {
        // Single-use codes: the step must be newer than the last one accepted.
        $up = $pdo->prepare(
            'UPDATE admins SET totp_last_step = ?, failed_count = 0, locked_until = NULL, last_login_at = NOW()
             WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $up->execute([$step, (int)$admin['id'], $step]);
        if ($up->rowCount() === 1) {
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['login_at'] = time();
            $_SESSION['last'] = time();
            audit_log($pdo, (int)$admin['id'], 'login_ok', null, null, null);
            return true;
        }
    }

    if ($admin && !(int)$admin['is_locked']) {
        // locked_until is assigned first: MySQL evaluates SET left to right.
        $f = $pdo->prepare(
            'UPDATE admins
             SET locked_until = IF(failed_count + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until),
                 failed_count = IF(failed_count + 1 >= ?, 0, failed_count + 1)
             WHERE id = ?'
        );
        $f->execute([AUTH_MAX_FAILURES, AUTH_LOCK_MINUTES, AUTH_MAX_FAILURES, (int)$admin['id']]);
    }
    audit_log($pdo, $admin ? (int)$admin['id'] : null, 'login_failed', null, null, null);
    usleep(random_int(150000, 350000));
    return false;
}

// Returns the logged-in admin row or null (no redirect).
function auth_current(PDO $pdo): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $now = time();
    if ($now - (int)($_SESSION['last'] ?? 0) > AUTH_IDLE_SECONDS
        || $now - (int)($_SESSION['login_at'] ?? 0) > AUTH_MAX_SESSION_SECONDS) {
        auth_destroy_session();
        session_boot();
        return null;
    }
    $st = $pdo->prepare('SELECT id, email FROM admins WHERE id = ?');
    $st->execute([(int)$_SESSION['admin_id']]);
    $admin = $st->fetch();
    if (!$admin) {
        auth_destroy_session();
        session_boot();
        return null;
    }
    $_SESSION['last'] = $now;
    return $admin;
}

function auth_require(PDO $pdo): array
{
    $admin = auth_current($pdo);
    if ($admin === null) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}
