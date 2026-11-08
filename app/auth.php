<?php
// Admin authentication: password + TOTP, per-address lockout, rate limit, hardened session.

const AUTH_IDLE_SECONDS = 1800;      // 30 min idle timeout
const AUTH_MAX_SESSION_SECONDS = 43200; // 12 h absolute
const AUTH_MAX_FAILURES = 5;   // wrong tries per email + internet address ...
const AUTH_LOCK_MINUTES = 15;   // ... per 15-minute window (the account itself is never locked)
const AUTH_SETUP_SECONDS = 900;  // a first sign-in's setup (2-step codes + new password) must be finished within 15 min
const AUTH_MIN_PASSWORD = 12;
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

/**
 * Ties a signed-in session to the account's current password and 2-step secret (2026-11-09): when either changes
 * (a reset by another admin, reset-2fa.php, reset-password.php, a password change), every other session ends.
 */
function auth_fp(string $passwordHash, string $storedSecret): string
{
    return hash('sha256', 'cp-session-v1|' . $passwordHash . '|' . $storedSecret);
}

/** Signs this browser in as $adminId (new session id; fingerprint of the account as stored now). */
function auth_start_session(int $adminId, string $passwordHash, string $storedSecret): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['fp'] = auth_fp($passwordHash, $storedSecret);
    $_SESSION['login_at'] = time();
    $_SESSION['last'] = time();
}

/**
 * Sign-in. Returns 'ok' (signed in), 'setup' (right password for an account that has no 2-step codes yet: a new
 * user, or one an admin reset - the browser gets a short setup session, never admin_id) or '' (failed; every
 * failure looks the same).
 */
function auth_attempt(PDO $pdo, string $email, string $password, string $code): string
{
    $ip = client_ip();
    if (!rate_limit_hit($pdo, 'login-ip:' . $ip, 10, 900)) {
        return '';
    }
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || strlen($password) > 1024) {
        return '';
    }
    $failBucket = auth_fail_bucket($email, $ip);
    if (rate_limit_count($pdo, $failBucket, AUTH_LOCK_MINUTES * 60) >= AUTH_MAX_FAILURES) {
        usleep(random_int(150000, 350000));
        return ''; // this email from this address only; the admin can still sign in from anywhere else
    }

    $st = $pdo->prepare('SELECT id, email, password_hash, totp_secret FROM cp_admins WHERE email = ?');
    $st->execute([$email]);
    $admin = $st->fetch();

    $pwOk = password_verify($password, $admin ? $admin['password_hash'] : AUTH_DUMMY_HASH);
    if ($admin && $pwOk && (string)$admin['totp_secret'] === '') {
        // No 2-step codes yet: the password only opens the setup step (setup.php), which signs in once it is done
        rate_limit_clear($pdo, $failBucket);
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['setup_admin_id'] = (int)$admin['id'];
        $_SESSION['setup_email'] = (string)$admin['email'];
        $_SESSION['setup_at'] = time();
        $_SESSION['setup_secret'] = totp_generate_secret();
        $_SESSION['setup_pw'] = hash('sha256', (string)$admin['password_hash']); // a reset meanwhile ends this setup
        audit_log($pdo, (int)$admin['id'], 'setup_started', null, null, null);
        return 'setup';
    }
    $secret = $admin ? auth_totp_secret((string)$admin['totp_secret']) : null;
    $step = ($secret !== null && $secret !== '') ? totp_verify($secret, $code, time(), 1) : false;

    if ($admin && $pwOk && $step !== false) {
        // Single-use codes: the step must be newer than the last one accepted. A plain secret is sealed in the same write.
        $up = $pdo->prepare(
            'UPDATE cp_admins SET totp_last_step = ?, totp_secret = ?, failed_count = 0, locked_until = NULL, last_login_at = NOW()
             WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $storeNow = auth_totp_store_value((string)$admin['totp_secret'], $secret);
        $up->execute([$step, $storeNow, (int)$admin['id'], $step]);
        if ($up->rowCount() === 1) {
            rate_limit_clear($pdo, $failBucket);
            auth_start_session((int)$admin['id'], (string)$admin['password_hash'], $storeNow);
            audit_log($pdo, (int)$admin['id'], 'login_ok', null, null, null);
            return 'ok';
        }
    }

    rate_limit_add($pdo, $failBucket, AUTH_LOCK_MINUTES * 60);
    audit_log($pdo, $admin ? (int)$admin['id'] : null, 'login_failed', null, null, null);
    usleep(random_int(150000, 350000));
    return '';
}

/** The setup session from a first sign-in, or null when there is none or it is older than AUTH_SETUP_SECONDS. */
function auth_setup_current(): ?array
{
    $id = (int)($_SESSION['setup_admin_id'] ?? 0);
    $secret = (string)($_SESSION['setup_secret'] ?? '');
    if ($id <= 0 || $secret === '' || time() - (int)($_SESSION['setup_at'] ?? 0) > AUTH_SETUP_SECONDS) {
        return null;
    }
    return ['id' => $id, 'email' => (string)($_SESSION['setup_email'] ?? ''), 'secret' => $secret, 'pw' => (string)($_SESSION['setup_pw'] ?? '')];
}

/** A new password's problem in words, or '' when it is acceptable. */
function auth_password_problem(string $pw, string $repeat, string $email = ''): string
{
    if ($pw !== $repeat) {
        return 'The two passwords are different.';
    }
    if (strlen($pw) < AUTH_MIN_PASSWORD) {
        return 'Use at least ' . AUTH_MIN_PASSWORD . ' characters.';
    }
    if (strlen($pw) > 1024) {
        return 'That password is too long.';
    }
    $name = $email !== '' ? (string)strstr($email, '@', true) : '';
    if (strlen($name) >= 4 && stripos($pw, $name) !== false) {
        return 'Do not use your email name in the password.';
    }
    return '';
}

function auth_hash_password(string $pw): string
{
    return password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]); // cost 10: AUTH_DUMMY_HASH timing must match
}

/**
 * Finishes a first sign-in: the code from the newly added authenticator entry proves it works, and the temporary
 * password is replaced. Then signs in. Returns '' on success or the problem in words.
 */
function auth_setup_finish(PDO $pdo, string $code, string $pw, string $repeat): string
{
    $s = auth_setup_current();
    if ($s === null) {
        return 'This setup has expired. Sign in again with your email and temporary password.';
    }
    $failBucket = auth_fail_bucket(strtolower($s['email']), client_ip());
    if (rate_limit_count($pdo, $failBucket, AUTH_LOCK_MINUTES * 60) >= AUTH_MAX_FAILURES) {
        return 'Too many wrong tries from here. Wait 15 minutes and sign in again.';
    }
    if (($p = auth_password_problem($pw, $repeat, $s['email'])) !== '') {
        return $p;
    }
    $step = totp_verify($s['secret'], $code, time(), 1);
    if ($step === false) {
        rate_limit_add($pdo, $failBucket, AUTH_LOCK_MINUTES * 60);
        return "That code is not right. Check your phone's clock is automatic and type the newest code.";
    }
    $st = $pdo->prepare('SELECT id, password_hash, totp_secret FROM cp_admins WHERE id = ?');
    $st->execute([$s['id']]);
    $row = $st->fetch();
    if (!$row || (string)$row['totp_secret'] !== '' || $s['pw'] === '' || !hash_equals($s['pw'], hash('sha256', (string)$row['password_hash']))) {
        // already set up, removed, or reset since this setup started (a new temporary password): this setup is void
        unset($_SESSION['setup_admin_id'], $_SESSION['setup_email'], $_SESSION['setup_at'], $_SESSION['setup_secret'], $_SESSION['setup_pw']);
        return 'This setup is no longer valid. Sign in again with your email and the newest temporary password.';
    }
    if (password_verify($pw, (string)$row['password_hash'])) {
        return 'Choose a new password, not the temporary one you were given.';
    }
    $key = keys_secret_box_key();
    $stored = $key !== null ? (totp_seal($s['secret'], $key) ?? $s['secret']) : $s['secret'];
    $hash = auth_hash_password($pw);
    $up = $pdo->prepare("UPDATE cp_admins SET password_hash = ?, totp_secret = ?, totp_last_step = ?, failed_count = 0, locked_until = NULL, last_login_at = NOW()
        WHERE id = ? AND totp_secret = '' AND password_hash = ?");
    $up->execute([$hash, $stored, $step, $s['id'], (string)$row['password_hash']]);
    if ($up->rowCount() !== 1) {
        return 'This account is already set up. Sign in again.';
    }
    rate_limit_clear($pdo, $failBucket);
    auth_start_session($s['id'], $hash, $stored);
    audit_log($pdo, $s['id'], 'setup_done', null, null, ['codes' => $stored !== $s['secret'] ? 'encrypted' : 'plain (no signing key yet)']);
    return '';
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
    $step = ($secret !== null && $secret !== '') ? totp_verify($secret, $code, time(), 1) : false;
    if ($pwOk && $step !== false) {
        $storeNow = auth_totp_store_value((string)$admin['totp_secret'], $secret);
        $up = $pdo->prepare('UPDATE cp_admins SET totp_last_step = ?, totp_secret = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $up->execute([$step, $storeNow, $adminId, $step]);
        if ($up->rowCount() === 1) {
            if ((int)($_SESSION['admin_id'] ?? 0) === $adminId) {
                $_SESSION['fp'] = auth_fp((string)$admin['password_hash'], $storeNow); // sealing the secret is not a reset
            }
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
    $st = $pdo->prepare('SELECT id, email, password_hash, totp_secret FROM cp_admins WHERE id = ?');
    $st->execute([(int)$_SESSION['admin_id']]);
    $admin = $st->fetch();
    // Removed, reset, password or codes changed since this sign-in (or a session from before fingerprints): signed out
    if (!$admin || (string)$admin['totp_secret'] === '' || !is_string($_SESSION['fp'] ?? null)
        || !hash_equals(auth_fp((string)$admin['password_hash'], (string)$admin['totp_secret']), $_SESSION['fp'])) {
        auth_destroy_session();
        session_boot();
        return null;
    }
    $_SESSION['last'] = $now;
    return ['id' => (int)$admin['id'], 'email' => (string)$admin['email']];
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
