<?php
// Output helpers. Everything user-derived goes through e().

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Which sidebar link is the current one. Pages that belong to a section light up that section's link.
function view_nav_current(): string
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'tenant.php') {
        // tenant.php with an id is one business (Businesses); without one it is the add form.
        return (isset($_GET['id']) || isset($_POST['id'])) ? 'index.php' : 'tenant.php';
    }
    $parent = [
        'staff.php' => 'index.php', 'ai.php' => 'index.php',
        'client.php' => 'clients.php',
        'document.php' => 'invoices.php', 'recurring.php' => 'invoices.php', 'statement.php' => 'invoices.php',
    ];
    return $parent[$script] ?? $script;
}

// One sidebar link; $current is the href of the active page.
function view_nav_link(string $href, string $label, string $current, string $extra = ''): string
{
    $on = $href === $current;
    return '<a class="side-link' . ($on ? ' active' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . e($label) . $extra . '</a>';
}

function view_head(string $title, bool $nav = true): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' - Licence panel</title>'
        . '<link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head><body>';
    view_shell_state($nav);
    if ($nav) {
        // Outbox: emails waiting for approval (one cheap count; the nav still renders if it fails).
        $waiting = 0;
        try {
            $waiting = (int)db()->query("SELECT COUNT(*) FROM cp_outbox WHERE status = 'pending'")->fetchColumn();
        } catch (Throwable $ex) {
            $waiting = 0;
        }
        $cur = view_nav_current();
        $badge = $waiting > 0
            ? '<span class="side-badge" title="' . $waiting . ' waiting for approval">' . $waiting . '</span>'
            : '';
        $groups = [
            'Businesses' => [['index.php', 'Businesses'], ['tenant.php', 'Add business'], ['clients.php', 'Clients']],
            'Billing' => [['invoices.php', 'Billing'], ['reports.php', 'Reports'], ['outbox.php', 'Outbox'], ['automations.php', 'Automations']],
            'Operations' => [['maintenance.php', 'Maintenance'], ['deploy.php', 'Deployments'], ['backups.php', 'Backups'], ['audit.php', 'Audit log']],
            'Settings' => [['users.php', 'Users'], ['settings.php', 'Settings'], ['keys.php', 'Signing key'], ['account.php', 'My account']],
            'Help' => [['guide.php', 'Guide'], ['guide-sales.php', 'Sales guide']],
        ];
        $menu = '';
        foreach ($groups as $heading => $links) {
            $menu .= '<div class="side-group"><p class="side-h">' . e($heading) . '</p>';
            foreach ($links as [$href, $label]) {
                $menu .= view_nav_link($href, $label, $cur, $href === 'outbox.php' ? $badge : '');
            }
            $menu .= '</div>';
        }
        echo '<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Show the menu">'
            . '<div class="app">'
            . '<header class="mobilebar"><a class="side-brand" href="index.php"><span class="brand-mark">L</span>Licence panel</a>'
            . '<label for="nav-toggle" class="nav-btn">Menu</label></header>'
            . '<aside class="sidebar" aria-label="Main menu"><a class="side-brand" href="index.php"><span class="brand-mark">L</span>Licence panel</a>'
            . '<nav class="side-nav">' . $menu . '</nav>'
            . '<div class="side-foot"><form method="post" action="logout.php" class="inline">' . csrf_field()
            . '<button type="submit" class="link">Sign out</button></form></div></aside>';
    }
    echo '<main>';
}

// Remembers whether view_head opened the sidebar shell, so view_foot closes the same tags.
function view_shell_state(?bool $set = null): bool
{
    static $nav = false;
    if ($set !== null) {
        $nav = $set;
    }
    return $nav;
}

function view_foot(): void
{
    echo view_shell_state() ? '</main></div></body></html>' : '</main></body></html>';
}

// Page header: the title and one line of description on the left, the page's main action on the right.
// All three arguments are HTML that is already escaped (use e() for anything user-derived).
function view_page_head(string $titleHtml, string $descHtml = '', string $actionsHtml = ''): string
{
    return '<div class="page-head"><div><h1>' . $titleHtml . '</h1>'
        . ($descHtml !== '' ? '<p class="page-desc">' . $descHtml . '</p>' : '') . '</div>'
        . ($actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '') . '</div>';
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

// Free-trial words ("Free trial – 12 days left" / "Trial ended") as a pill (Part 3). '' gives ''.
function view_trial_tag(string $words): string
{
    if ($words === '') {
        return '';
    }
    return '<span class="pill ' . ($words === 'Trial ended' ? 'pill-trial-ended' : 'pill-trial') . '">' . e($words) . '</span>';
}

// Column header: the name and a small "?" badge that holds the help text.
function view_th(string $name, string $help, string $short = ''): string
{
    // The "?" badge carries the full help (hover, focus or tap); the same text stays as the header tooltip.
    // $short is no longer shown as a second line: the badge replaces it.
    return '<th scope="col" title="' . e($help) . '">' . e($name)
        . '<span class="q" tabindex="0" role="note" data-help="' . e($help) . '" aria-label="' . e($help) . '">?</span></th>';
}

// One muted dash for any empty value, on every table.
// One fact in a <dl class="facts"> strip: a small grey name over its value ($html is already escaped).
function view_fact(string $name, string $html): string
{
    return '<div><dt>' . e($name) . '</dt><dd>' . $html . '</dd></div>';
}

function view_empty(): string
{
    return '<span class="muted" title="Nothing recorded">&ndash;</span>';
}

// Cell text kept on one line, cut with an ellipsis, full value in the tooltip.
function view_trunc($v): string
{
    return '<span class="trunc" title="' . e($v) . '">' . e($v) . '</span>';
}
