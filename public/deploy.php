<?php
// Deployments (owner 2026-11-23): every commit pushed to GitHub, which one each live copy runs, and "Update from
// Remote". No cPanel credentials: the button records a request (one click, no password or code, owner 2026-11-23; audited) and a cron job on
// that server runs the pull and reports back (app/deploy.php). The panel only pulls; it never pushes code.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo); // every panel user is an admin: there are no roles (app/users.php)
$me = (int)$admin['id'];

$tenantRows = $pdo->query('SELECT id, name FROM cp_tenants ORDER BY name')->fetchAll();
$tenantIds = array_map(static fn($r) => (int)$r['id'], $tenantRows);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $id = ctype_digit($s('id')) ? (int)$s('id') : 0;

    if ($action === 'save_target') {
        [$clean, $errs] = deploy_validate_target($_POST, $tenantIds);
        $problem = $errs ? implode(' ', $errs) : '';
        $newId = 0;
        if ($problem === '') {
            [$newId, $problem] = deploy_save_target($pdo, $id, $clean, $me);
        }
        if ($problem !== '') {
            flash_set('error', $problem);
            header('Location: deploy.php' . ($id > 0 ? '?edit=' . $id : '?add=1') . '#target-form');
            exit;
        }
        flash_set('ok', $id > 0 ? 'Live copy saved.' : 'Live copy added.');
        header('Location: deploy.php?id=' . $newId);
        exit;
    }
    if ($action === 'save_github') {
        $problem = deploy_save_github_token($pdo, $s('github_token'), $me);
        if ($problem === '') {
            deploy_cache_clear(deploy_cache_dir());
        }
        flash_set($problem === '' ? 'ok' : 'error', $problem === '' ? 'GitHub token saved.' : $problem);
        header('Location: deploy.php#github');
        exit;
    }
    if ($action === 'refresh') {
        $t = $id > 0 ? deploy_target($pdo, $id) : null;
        if ($t) {
            deploy_cache_clear(deploy_cache_dir(), (string)$t['github_repo'], (string)$t['branch']);
        }
        header('Location: deploy.php' . ($t ? '?id=' . (int)$t['id'] . '#commits' : ''));
        exit;
    }
    if ($action === 'request_update') {
        $t = $id > 0 ? deploy_target($pdo, $id) : null;
        if (!$t) {
            flash_set('error', 'That live copy no longer exists.');
            header('Location: deploy.php');
            exit;
        }
        // One click, no password or code (owner 2026-11-23): it can only pull the branch already on GitHub. Signed-in
        // panel user + CSRF token (checked above); written to the audit log by deploy_request_update().
        $reqId = deploy_request_update($pdo, (int)$t['id'], $me, time());
        flash_set('ok', (string)$t['name'] . ': Update from Remote asked for (request ' . $reqId . '). '
            . ($t['tenant_id'] === null ? 'The cron job runs it at its next run (every 1 to 5 minutes).' : 'The site was told now; its cron job runs it at its next run (every 1 to 5 minutes).'));
        header('Location: deploy.php?id=' . (int)$t['id']);
        // A business hears of the request now, not at its next hourly check-in (owner 2026-11-23): nudge it after the
        // redirect is sent, so nobody waits. Best effort; the request still goes with its next check-in either way.
        if ($t['tenant_id'] !== null) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            tenant_nudge($pdo, (int)$t['tenant_id']);
        }
        exit;
    }
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// ---------------------------------------------------------------------------------------------------- read
$now = time();
$targets = deploy_targets($pdo);
$settings = deploy_settings($pdo);
$ghToken = deploy_github_token($pdo);
$hasBox = deploy_box_key() !== null;
$cacheDir = deploy_cache_dir();
$ownHead = deploy_git_head(APP_ROOT . '/.git'); // this panel's own live commit, read now

$selId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$editId = isset($_GET['edit']) && ctype_digit((string)$_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = $editId > 0 ? deploy_target($pdo, $editId) : null;
$showForm = $editRow !== null || isset($_GET['add']) || !$targets;

$view = [];
foreach ($targets as $t) {
    $tid = (int)$t['id'];
    $gh = deploy_github_commits((string)$t['github_repo'], (string)$t['branch'], (string)$ghToken, $now, $cacheDir);
    $own = $t['tenant_id'] === null && $ownHead !== null;
    $liveSha = $own ? $ownHead : (string)($t['live_sha'] ?? '');
    $liveAt = $own ? $now : deploy_ts($t['live_sha_at']);
    $subject = '';
    foreach ($gh['ok'] ? $gh['commits'] : [] as $c) {
        if ($liveSha !== '' && $c['sha'] === $liveSha) {
            $subject = deploy_subject($c['message']);
            break;
        }
    }
    $view[$tid] = ['t' => $t, 'gh' => $gh, 'sha' => $liveSha, 'sha_at' => $liveAt, 'own' => $own, 'subject' => $subject,
        'behind' => deploy_behind($gh['ok'] ? $gh['commits'] : null, $liveSha), 'req' => deploy_request_state($t, $now)];
}
if ($selId === 0 || !isset($view[$selId])) {
    $selId = $targets ? (int)$targets[0]['id'] : 0;
}

$short = static fn(string $sha): string => $sha !== '' ? substr($sha, 0, 7) : '';
$when = static fn(int $ts): string => $ts > 0 ? e(date('Y-m-d H:i', $ts)) : view_empty();
$pillBehind = ['up_to_date' => 'active', 'behind' => 'grace', 'not_found' => 'read_only', 'unknown' => 'grace'];
$pillReq = ['none' => '', 'requested' => 'grace', 'picked_up' => 'grace', 'waiting' => 'read_only', 'lapsed' => 'suspended', 'done' => 'active', 'failed' => 'suspended'];
$fact = static fn(string $name, string $help, string $html): string => '<div title="' . e($help) . '"><dt>' . e($name) . ' <span class="muted">(?)</span></dt><dd>' . $html . '</dd></div>';
$reqHtml = static function (array $v) use ($pillReq): string {
    $r = $v['req'];
    if ($r['state'] === 'none') {
        return '<span class="muted">' . e($r['words']) . '</span>';
    }
    $h = '<span class="pill pill-' . e($pillReq[$r['state']]) . '">' . e($r['words']) . '</span>';
    if (in_array($r['state'], ['done', 'failed'], true) && (string)$v['t']['result_msg'] !== '') {
        $h .= ' ' . view_trunc((string)$v['t']['result_msg']);
    }
    return $h;
};

view_head('Deployments');
echo view_page_head('Deployments');
view_flash();
?>
<div class="card">
    <p>Each live copy below is a cPanel <strong>Git Version Control</strong> clone of a GitHub repository. This page lists the commits pushed to its branch, shows which one is live, and lets you ask that server to run <strong>Update from Remote</strong> (one click; written to the audit log).</p>
    <p class="muted">No cPanel password or token is kept here. Pressing the button only records the request: a cron job on that server picks it up within minutes, pulls the branch from GitHub and reports back. Nothing is ever pushed to GitHub from here. Setting up: <a href="guide.php#deployments">Guide, section 18</a>.</p>
    <?php if (!$hasBox): ?><p class="flash flash-error">This panel has no signing key, so the GitHub token cannot be saved (Signing key page).</p><?php endif; ?>
</div>

<?php if ($targets): ?>
<h2>Live copies</h2>
<div class="scroll"><table class="list"><thead><tr>
    <?= view_th('Live copy', 'This panel itself, or a business\'s site.', 'Panel or business') ?>
    <?= view_th('Repository', 'The GitHub repository and branch this copy follows.', 'GitHub, branch') ?>
    <?= view_th('Live commit', 'The commit checked out on that server. This panel reads its own now; a business reports it at each check-in.', 'On the server') ?>
    <?= view_th('Behind', 'How many commits on the branch are newer than the live one (GitHub list, at most 5 minutes old).', 'Newer commits') ?>
    <?= view_th('Update from Remote', 'The newest request from this page: Requested, Picked up (the server started), then Done or Failed with the server\'s message.', 'Last request') ?>
    <?= view_th('', 'Show this copy\'s commits, or edit it.', 'Open / edit') ?>
</tr></thead><tbody>
<?php foreach ($view as $tid => $v):
    $t = $v['t']; ?>
    <tr>
        <td data-label="Live copy"><a href="deploy.php?id=<?= (int)$tid ?>#commits"><?= e($t['name']) ?></a><br><small class="muted"><?= $t['tenant_id'] !== null ? e((string)($t['tenant_name'] ?? 'Business #' . $t['tenant_id'])) : 'This panel' ?></small></td>
        <td data-label="Repository" class="nw"><?= e($t['github_repo']) ?><br><small class="muted"><?= e($t['branch']) ?></small></td>
        <td data-label="Live commit"><?php if ($v['sha'] !== ''): ?><code><?= e($short($v['sha'])) ?></code> <?= $v['subject'] !== '' ? view_trunc($v['subject']) : '' ?><small class="muted"><?= $v['own'] ? 'read now' : 'reported ' . $when($v['sha_at']) ?></small>
            <?php else: ?><span class="muted">Not reported yet</span><?php endif; ?></td>
        <td data-label="Behind"><?php if (!$v['gh']['ok']): ?><span class="bill-overdue"><?= e($v['gh']['error']) ?></span>
            <?php else: ?><span class="pill pill-<?= e($pillBehind[$v['behind']['state']]) ?>"><?= e(deploy_behind_words($v['behind'], (string)$t['branch'])) ?></span><?php endif; ?></td>
        <td data-label="Update from Remote"><?= $reqHtml($v) ?></td>
        <td data-label="Open / edit" class="nw"><a href="deploy.php?id=<?= (int)$tid ?>#commits">Commits</a> &middot; <a href="deploy.php?edit=<?= (int)$tid ?>#target-form">Edit</a></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<p><a href="deploy.php?add=1#target-form">Add a live copy</a></p>
<?php endif; ?>

<?php if ($selId > 0):
    $v = $view[$selId];
    $t = $v['t'];
    $liveSha = $v['sha'];
    $commits = $v['gh']['ok'] ? $v['gh']['commits'] : [];
    $perOpts = ['10' => 10, '25' => 25, '50' => 50, 'all' => 0];
    $perKey = isset($_GET['per']) && is_string($_GET['per']) && isset($perOpts[$_GET['per']]) ? $_GET['per'] : '10';
    $per = $perOpts[$perKey];
    $total = count($commits);
    $pages = $per > 0 ? max(1, (int)ceil($total / $per)) : 1;
    $page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? min(max(1, (int)$_GET['page']), $pages) : 1;
    $rows = $per > 0 ? array_slice($commits, ($page - 1) * $per, $per) : $commits;
    $link = static fn(int $p, string $pk): string => 'deploy.php?id=' . $selId . '&per=' . $pk . '&page=' . $p . '#commits';
?>
<h2 id="commits" class="spaced"><?= e($t['name']) ?>: commits on <?= e($t['github_repo']) ?> (<?= e($t['branch']) ?>)</h2>
<?php if (count($targets) > 1): ?>
<div class="tabs"><?php foreach ($targets as $o): ?><a href="deploy.php?id=<?= (int)$o['id'] ?>#commits"<?= (int)$o['id'] === $selId ? ' class="active"' : '' ?>><?= e($o['name']) ?></a><?php endforeach; ?></div>
<?php endif; ?>
<dl class="facts">
    <?= $fact('Live commit', 'The commit checked out on that server (this panel: read now; a business: as reported at its last check-in).', $liveSha !== '' ? '<code>' . e($short($liveSha)) . '</code>' : view_empty()) ?>
    <?= $fact('Behind ' . $t['branch'], 'Commits on the branch newer than the live one.', $v['gh']['ok'] ? e(deploy_behind_words($v['behind'], (string)$t['branch'])) : view_empty()) ?>
    <?= $fact('Update from Remote', 'The newest request from this page and what the server reported.', $reqHtml($v)) ?>
    <?= $fact('Commits listed', 'The newest commits on the branch, at most ' . DEPLOY_GITHUB_PER_PAGE . '.', $v['gh']['ok'] ? e((string)$total) : view_empty()) ?>
    <?= $fact('GitHub list from', 'When the list was fetched from GitHub. It is kept for 5 minutes.', $v['gh']['ok'] ? $when((int)$v['gh']['fetched_at']) : view_empty()) ?>
</dl>
<?php if (in_array($v['req']['state'], ['done', 'failed'], true) && (string)$t['result_msg'] !== ''): ?>
    <p class="facts-note <?= $v['req']['state'] === 'failed' ? 'bill-overdue' : 'muted' ?>">Server's message: <?= e((string)$t['result_msg']) ?></p>
<?php endif; ?>
<div class="bill-actions">
    <form method="post" action="deploy.php" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="id" value="<?= (int)$selId ?>">
        <button type="submit" class="secondary">Ask GitHub again now</button></form>
</div>

<div class="tools stack">
<form method="post" action="deploy.php" class="tool tool-warn" autocomplete="off">
    <h3>Update from Remote</h3>
    <p class="muted">Asks the server to pull <strong><?= e($t['branch']) ?></strong> from GitHub (the same as the button in cPanel &gt; Git Version Control). This changes the live site. <?= $t['tenant_id'] === null ? 'The cron job runs it at its next run (every 1 to 5 minutes).' : 'The site is told straight away; its own cron job runs it at its next run (every 1 to 5 minutes).' ?> Written to the audit log.</p>
    <?= csrf_field() ?><input type="hidden" name="action" value="request_update"><input type="hidden" name="id" value="<?= (int)$selId ?>">
    <button type="submit">Update from Remote</button>
</form>
</div>

<?php if (!$v['gh']['ok']): ?>
    <p class="flash flash-error"><?= e($v['gh']['error']) ?></p>
<?php elseif (!$commits): ?>
    <p class="muted">No commits on this branch.</p>
<?php else: ?>
<form method="get" action="deploy.php" class="bill-filter">
    <input type="hidden" name="id" value="<?= (int)$selId ?>">
    <label>Per page <select name="per"><?php foreach (array_keys($perOpts) as $k): ?><option value="<?= e((string)$k) ?>"<?= (string)$k === $perKey ? ' selected' : '' ?>><?= $k === 'all' ? 'All' : e((string)$k) ?></option><?php endforeach; ?></select></label>
    <button type="submit" class="secondary">Show</button>
</form>
<div class="scroll"><table class="list"><thead><tr>
    <?= view_th('Commit', 'The commit\'s short id (first 7 characters). Opens it on GitHub (you must be signed in to GitHub).', 'Short id') ?>
    <?= view_th('Message', 'The first line of the commit (push) message. Open it to read the full message.', 'Push message') ?>
    <?= view_th('Author', 'Who wrote the commit (name in the commit, GitHub user in brackets).', 'Who') ?>
    <?= view_th('Date', 'When the commit was written (author date, this panel\'s time zone).', 'Written at') ?>
    <?= view_th('Live', 'Marks the commit checked out on the server. Rows above it are not live yet.', 'On the site') ?>
</tr></thead><tbody>
<?php foreach ($rows as $c):
    $isLive = $liveSha !== '' && $c['sha'] === $liveSha;
    $subject = deploy_subject($c['message']);
    $rest = trim((string)substr(ltrim($c['message']), strlen($subject))); ?>
    <tr>
        <td data-label="Commit" class="nw"><?php if ($c['url'] !== ''): ?><a href="<?= e($c['url']) ?>" target="_blank" rel="noopener noreferrer"><code><?= e($short($c['sha'])) ?></code></a><?php else: ?><code><?= e($short($c['sha'])) ?></code><?php endif; ?></td>
        <td data-label="Message"><?php if ($rest !== ''): ?><details><summary><?= e($subject) ?></summary><pre><?= e($rest) ?></pre></details><?php else: ?><?= e($subject) ?><?php endif; ?></td>
        <td data-label="Author" class="nw"><?= $c['author'] !== '' ? e($c['author']) : view_empty() ?><?= $c['login'] !== '' ? ' <small class="muted">(' . e($c['login']) . ')</small>' : '' ?></td>
        <td data-label="Date" class="nw"><?= $when((int)$c['date']) ?></td>
        <td data-label="Live"><?= $isLive ? '<span class="pill pill-active">Live</span>' : '' ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?>
<p class="pager"><?php if ($page > 1): ?><a href="<?= e($link($page - 1, $perKey)) ?>">&larr; Newer</a><?php endif; ?>
    <span class="muted">Page <?= (int)$page ?> of <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($link($page + 1, $perKey)) ?>">Older &rarr;</a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($showForm):
    $f = $editRow ?? ['name' => '', 'tenant_id' => null, 'github_repo' => '', 'branch' => 'main']; ?>
<h2 id="target-form" class="spaced"><?= $editRow ? 'Edit ' . e($editRow['name']) : 'Add a live copy' ?></h2>
<form method="post" action="deploy.php" class="card" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_target"><input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
    <div class="bill-grid">
        <label>Name <small class="hint">How it shows here, e.g. "Licence panel".</small><input type="text" name="name" required maxlength="80" value="<?= e($f['name']) ?>"></label>
        <label>Belongs to <small class="hint">This panel itself, or a business's site (one live copy each).</small><select name="tenant_id">
            <option value="">This panel</option>
            <?php foreach ($tenantRows as $tr): ?><option value="<?= (int)$tr['id'] ?>"<?= (int)($f['tenant_id'] ?? 0) === (int)$tr['id'] ? ' selected' : '' ?>><?= e($tr['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label>GitHub repository <small class="hint">owner/name, e.g. papauri/msp-control-panel.</small><input type="text" name="github_repo" required maxlength="140" value="<?= e($f['github_repo']) ?>"></label>
        <label>Branch <small class="hint">The branch cPanel has checked out. Usually main.</small><input type="text" name="branch" maxlength="100" value="<?= e($f['branch']) ?>"></label>
    </div>
    <button type="submit"><?= $editRow ? 'Save' : 'Add' ?></button>
    <?php if ($editRow): ?> <a href="deploy.php?id=<?= (int)$editRow['id'] ?>">Cancel</a><?php endif; ?>
</form>
<?php endif; ?>

<h2 id="github" class="spaced">GitHub access</h2>
<form method="post" action="deploy.php" class="card" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_github">
    <p class="muted">One read-only token for all repositories: a GitHub <strong>fine-grained</strong> token limited to these repositories, with <strong>Contents: Read</strong> and <strong>Metadata: Read</strong> only. It is used to list commits and nothing else.</p>
    <label>GitHub token <small class="hint"><?= !empty($settings['github_token_hint']) ? 'Saved: ' . e($settings['github_token_hint']) . ($ghToken === null ? ' (cannot be opened: paste it again)' : '') . '. Paste a new one to replace it.' : 'Not saved yet.' ?></small>
        <input type="password" name="github_token" required maxlength="255" autocomplete="new-password"></label>
    <button type="submit">Save token</button>
</form>
<?php
view_foot();
