<?php
// Output helpers. Everything user-derived goes through e().

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view_head(string $title, bool $nav = true): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' - Licence panel</title>'
        . '<link rel="stylesheet" href="assets/app.css"></head><body>';
    if ($nav) {
        echo '<header class="top"><strong class="brand">Licence panel</strong><nav>'
            . '<a href="index.php">Hotels</a><a href="tenant.php">Add hotel</a><a href="audit.php">Audit log</a></nav>'
            . '<form method="post" action="logout.php" class="inline">' . csrf_field()
            . '<button type="submit" class="link">Sign out</button></form></header>';
    }
    echo '<main>';
}

function view_foot(): void
{
    echo '</main></body></html>';
}

function view_flash(): void
{
    foreach (flash_take() as $f) {
        echo '<p class="flash flash-' . e($f[0]) . '">' . e($f[1]) . '</p>';
    }
}

function view_pill(string $status, bool $override = false): string
{
    $labels = ['active' => 'Active', 'grace' => 'Grace', 'read_only' => 'Read only', 'suspended' => 'Suspended'];
    $label = $labels[$status] ?? $status;
    return '<span class="pill pill-' . e($status) . '">' . e($label) . '</span>'
        . ($override ? ' <span class="tag">override</span>' : '');
}

// Column header with a short plain explanation underneath.
function view_th(string $name, string $help): string
{
    return '<th scope="col">' . e($name) . '<small>' . e($help) . '</small></th>';
}
