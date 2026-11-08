<?php
// Admin authentication: password + TOTP, per-address lockout, rate limit, hardened session.

const AUTH_IDLE_SECONDS = 1800;      // 30 min idle timeout
const AUTH_MAX_SESSION_SECONDS = 43200; // 12 h absolute
const AUTH_MAX_FAILURES = 5;   // wrong tries per email + internet address ...
const AUTH_LOCK_MINUTES = 15;   // ... per 15-minute window (the account itself is never locked)
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

/**
 * Failed sign-ins are counted per email AND internet address (2026-11-09), never per account: someone who only knows
 * the admin email cannot lock the admin out from elsewhere. Unknown emails are counted the same way, so the
 * "too many tries" answer never shows which emails exist. Hashed so a long email or IPv6 address is never cut short.
 */
function auth_fail_bucket(string $email, string $ip): string
{
    return 'login-fail:' . hash('sha256', $email . '|' . $ip);
}

/** The usable base32 secret from the stored value (plain or sealed); null when sealed and the signing key cannot open it. */
function auth_totp_secret(string $stored): ?string
{
    $secret = totp_open($stored, totp_secret_is_sealed($stored) ? keys_secret_box_key() : null);
    if ($secret === null) {
        error_log('auth: an admin 2-step secret is encrypted but the signing key is missing or is not the one it was sealed with. '
            . 'Restore keys/licence-private.pem from the offline backup, or run php scripts/reset-2fa.php EMAIL.');
    }
    return $secret;
}

/** What to store after a good sign-in: a plain secret is sealed when the signing key is there (checked to open again). */
function auth_totp_store_value(string $stored, string $secret): string
{
    if (totp_secret_is_sealed($stored)) {
        return $stored;
    }
    $key = keys_secret_box_key();
    $sealed = $key !== null ? totp_seal($secret, $key) : null;
    return $sealed ?? $stored;
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
    $failBucket = auth_fail_bucket($email, $ip);
    if (rate_limit_count($pdo, $failBucket, AUTH_LOCK_MINUTES * 60) >= AUTH_MAX_FAILURES) {
        usleep(random_int(150000, 350000));
        return false; // this email from this address only; the admin can still sign in from anywhere else
    }

    $st = $pdo->prepare('SELECT id, email, password_hash, totp_secret FROM cp_admins WHERE email = ?');
    $st->execute([$email]);
    $admin = $st->fetch();

    $pwOk = password_verify($password, $admin ? $admin['password_hash'] : AUTH_DUMMY_HASH);
    $secret = $admin ? auth_totp_secret((string)$admin['totp_secret']) : null;
    $step = $secret !== null ? totp_verify($secret, $code, time(), 1) : false;

    if ($admin && $pwOk && $step !== false) {
        // Single-use codes: the step must be newer than the last one accepted. A plain secret is sealed in the same write.
        $up = $pdo->prepare(
            'UPDATE cp_admins SET totp_last_step = ?, totp_secret = ?, failed_count = 0, locked_until = NULL, last_login_at = NOW()
             WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $up->execute([$step, auth_totp_store_value((string)$admin['totp_secret'], $secret), (int)$admin['id'], $step]);
        if ($up->rowCount() === 1) {
            rate_limit_clear($pdo, $failBucket);
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['login_at'] = time();
            $_SESSION['last'] = time();
            audit_log($pdo, (int)$admin['id'], 'login_ok', null, null, null);
            return true;
        }
    }

    rate_limit_add($pdo, $failBucket, AUTH_LOCK_MINUTES * 60);
    audit_log($pdo, $admin ? (int)$admin['id'] : null, 'login_failed', null, null, null);
    usleep(random_int(150000, 350000));
    return false;
}

/**
 * Re-confirms the SIGNED-IN admin with their password and a fresh 6-digit code before a sensitive action
 * (downloading the key backup). Same single-use code rule as sign-in; rate-limited per admin (5 per 15 minutes).
 * A wrong try here never locks the account.
 */
function auth_reconfirm(PDO $pdo, int $adminId, string $password, string $code): bool
{
    if (!rate_limit_hit($pdo, 'reconfirm:' . $adminId, 5, 900) || strlen($password) > 1024) {
        return false;
    }
    $st = $pdo->prepare('SELECT id, password_hash, totp_secret FROM cp_admins WHERE id = ?');
    $st->execute([$adminId]);
    $admin = $st->fetch();
    if (!$admin) {
        return false;
    }
    $pwOk = password_verify($password, (string)$admin['password_hash']);
    $secret = auth_totp_secret((string)$admin['totp_secret']);
    $step = $secret !== null ? totp_verify($secret, $code, time(), 1) : false;
    if ($pwOk && $step !== false) {
        $up = $pdo->prepare('UPDATE cp_admins SET totp_last_step = ?, totp_secret = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $up->execute([$step, auth_totp_store_value((string)$admin['totp_secret'], $secret), $adminId, $step]);
        if ($up->rowCount() === 1) {
            return true;
        }
    }
    audit_log($pdo, $adminId, 'reconfirm_failed', null, null, null);
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
    $st = $pdo->prepare('SELECT id, email FROM cp_admins WHERE id = ?');
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
