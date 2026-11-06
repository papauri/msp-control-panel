<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

$perPage = 100;
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, min(100000, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare(
    'SELECT a.*, ad.email AS admin_email, t.slug AS tenant_slug
     FROM cp_audit_log a
     LEFT JOIN cp_admins ad ON ad.id = a.admin_id
     LEFT JOIN cp_tenants t ON t.id = a.tenant_id
     ORDER BY a.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
);
$st->execute();
$rows = $st->fetchAll();

function audit_pretty(?string $json): string
{
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);
    return $d === null ? $json : (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

view_head('Audit log');
echo '<h1>Audit log</h1><p class="muted">Every change and sign-in, newest first.</p>';
echo '<div class="scroll"><table><thead><tr>'
    . view_th('When', 'Date and time of the action.')
    . view_th('Who', 'The admin who did it. Blank for failed sign-ins by an unknown email.')
    . view_th('Action', 'What was done.')
    . view_th('Business', 'The business affected, if any.')
    . view_th('Change', 'What the record looked like before and after. Secrets are never stored here.')
    . view_th('IP', 'The address the request came from.')
    . '</tr></thead><tbody>';
foreach ($rows as $r) {
    $b = audit_pretty($r['before_json']);
    $a = audit_pretty($r['after_json']);
    echo '<tr><td data-label="When">' . e($r['created_at']) . '</td>'
        . '<td data-label="Who">' . e($r['admin_email'] ?? '') . '</td>'
        . '<td data-label="Action">' . e($r['action']) . '</td>'
        . '<td data-label="Business">' . e($r['tenant_slug'] ?? '') . '</td><td data-label="Change">';
    if ($b !== '' || $a !== '') {
        echo '<details><summary>Show</summary>';
        if ($b !== '') {
            echo '<p class="muted">Before</p><pre>' . e($b) . '</pre>';
        }
        if ($a !== '') {
            echo '<p class="muted">After</p><pre>' . e($a) . '</pre>';
        }
        echo '</details>';
    }
    echo '</td><td data-label="IP">' . e($r['ip']) . '</td></tr>';
}
echo '</tbody></table></div><p class="pager">';
if ($page > 1) {
    echo '<a href="audit.php?page=' . ($page - 1) . '">Newer</a> ';
}
if (count($rows) === $perPage) {
    echo '<a href="audit.php?page=' . ($page + 1) . '">Older</a>';
}
echo '</p>';
view_foot();
