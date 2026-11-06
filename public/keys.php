<?php
// Signing key: create the panel's signing key once from the browser, show the public key every business's
// system needs, and (owner 2026-11-07) download an offline backup of the private key and the panel's settings
// file — only after re-entering the password AND a fresh 6-digit code. The private key is never shown on screen.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'backup') {
        $pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $code = isset($_POST['code']) && is_string($_POST['code']) ? preg_replace('/\D/', '', $_POST['code']) : '';
        $privPath = keys_private_path();
        if (!auth_reconfirm($pdo, (int)$admin['id'], $pw, (string)$code)) {
            flash_set('error', 'Password or code not accepted. Nothing was downloaded. Check your phone\'s clock is automatic and try a new code.');
        } elseif (!is_file($privPath) || !is_readable($privPath)) {
            flash_set('error', 'There is no readable signing key to back up.');
        } else {
            $envPath = APP_ROOT . '/.env';
            $out = "MSP licence panel - OFFLINE BACKUP - " . date('Y-m-d H:i') . "\n"
                . "Keep this file offline (USB stick or password manager). Never email it or upload it anywhere.\n"
                . "To restore: put section 1 back as " . $privPath . " and section 3 back as " . $envPath . " (cPanel File Manager).\n\n"
                . "===== 1. PRIVATE KEY (licence-private.pem) =====\n" . (string)file_get_contents($privPath) . "\n"
                . "===== 2. PUBLIC KEY (licence-public.pem, not secret) =====\n" . (string)keys_public_pem($privPath) . "\n"
                . "===== 3. PANEL SETTINGS FILE (.env) =====\n" . (is_readable($envPath) ? (string)file_get_contents($envPath) : "(not found)\n");
            audit_log($pdo, (int)$admin['id'], 'key_backup_downloaded', null, null, ['includes' => 'private key, public key, .env']);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="licence-panel-backup-' . date('Ymd') . '.txt"');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            echo $out;
            exit;
        }
        header('Location: keys.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'create') {
        [$ok, $msg] = keys_create();
        if ($ok) {
            audit_log($pdo, (int)$admin['id'], 'signing_key_created', null, null, ['path' => keys_private_path()]);
        }
        flash_set($ok ? 'ok' : 'error', $msg);
    }
    header('Location: keys.php');
    exit;
}

$public = keys_public_pem();
$exists = is_file(keys_private_path());

view_head('Signing key');
?>
<h1>Signing key</h1>
<?php view_flash(); ?>
<div class="card">
<?php if ($public !== null): ?>
    <p><?= view_pill('active') ?> The panel has a signing key. Businesses' daily check-ins are answered and signed with it.</p>
    <h2>Public key</h2>
    <p class="muted">Not secret. Every business's system holds this so it can tell a real answer from a fake one. Copy all of it, including the BEGIN and END lines.</p>
    <pre><?= e($public) ?></pre>
    <h2>Download a backup</h2>
    <p class="muted">One file with the private key, the public key and this panel's settings file (.env). Keep it offline (USB stick or password manager); never email it. If the key is ever lost, this file brings the panel back without touching any business. Enter your password and a new 6-digit code to download.</p>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="backup">
        <label>Your password <input type="password" name="password" required autocomplete="current-password"></label>
        <label>6-digit code <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"></label>
        <button type="submit">Download backup</button>
    </form>
    <p class="muted">The private key itself stays on the server at <code><?= e(keys_private_path()) ?></code> and is never shown on screen or replaced from this page.</p>
<?php elseif ($exists): ?>
    <p class="flash flash-error">A key file exists at <code><?= e(keys_private_path()) ?></code> but cannot be read. Check its permissions (600, owned by your cPanel user).</p>
<?php else: ?>
    <p>The panel has no signing key yet, so businesses' check-ins are answered with "unavailable".</p>
    <p class="muted">Creating it is a one-time step. The key is written to <code><?= e(keys_private_path()) ?></code>, outside the public folder, and is never replaced afterwards.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit">Create signing key</button>
    </form>
<?php endif; ?>
</div>
<?php
view_foot();
