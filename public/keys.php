<?php
// Signing key: create the panel's signing key once from the browser and show the public key
// that every business's system needs. The private key is never shown or downloaded here.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
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
    <h2>Back up the private key</h2>
    <p class="muted">The private key stays on the server at <code><?= e(keys_private_path()) ?></code>. Download it once with cPanel File Manager and keep it offline (USB stick or password manager). If it is lost, every business needs an update before check-ins work again. It is never replaced from this page.</p>
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
