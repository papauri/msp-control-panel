<?php
// The Assistant (AI) package for one business (owner 2026-11-11, app/ai.php): its subscription, its AI provider key and
// models, a test run from the panel, and sending the tested set-up to the hotel. The hotel's own administrators may change
// it afterwards in Assistant Settings; this page shows where the hotel now differs from what was sent.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$tenant = $id > 0 ? tenant_find($pdo, $id) : null;
if (!$tenant) {
    flash_set('error', 'Choose a business first.');
    header('Location: index.php');
    exit;
}
$tid = (int)$tenant['id'];

/** Back to this page; optionally nudge the hotel to check in now (after the redirect is sent, so nobody waits). */
function ai_back(int $tenantId, bool $nudge = false, string $anchor = ''): void
{
    header('Location: ai.php?id=' . $tenantId . ($anchor !== '' ? '#' . $anchor : ''));
    if ($nudge) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        tenant_nudge(db(), $tenantId);
    }
    exit;
}

$plans = billing_catalog($pdo, true);
$planIds = array_map(static fn($p) => (int)$p['id'], $plans);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    if ($action === 'subscription') {
        [$err, $clean] = ai_validate_subscription($_POST, $planIds);
        if ($err !== '') {
            flash_set('error', $err);
        } else {
            ai_save_subscription($pdo, $tid, $clean, $adminId);
            flash_set('ok', 'Subscription saved. The monthly aim reaches the hotel with the next set-up you send.');
        }
        ai_back($tid, false, 'subscription');
    } elseif ($action === 'licence_on' || $action === 'licence_off') {
        try {
            ai_set_licensed($pdo, $tid, $action === 'licence_on', $adminId);
        } catch (RuntimeException $ex) {
            flash_set('error', $ex->getMessage());
            ai_back($tid, false, 'subscription');
        }
        flash_set('ok', $action === 'licence_on'
            ? 'The Assistant add-on is now in this business\'s licence. The hotel picks it up at its next check-in (asked for now).'
            : 'The Assistant add-on was taken out of this business\'s licence. The hotel switches the Assistant off at its next check-in (asked for now). Its chats and settings are kept.');
        ai_back($tid, true, 'subscription');
    } elseif ($action === 'save_test') {
        @set_time_limit(150); // a list and up to three short test replies
        $err = ai_save_setup($pdo, $tid, $s('provider'), $s('api_key'), ['primary' => $s('model_primary'), 'fast' => $s('model_fast'), 'fallback' => $s('model_fallback')], $adminId);
        unset($_POST['api_key']);
        if ($err !== '') {
            flash_set('error', $err);
            ai_back($tid, false, 'setup');
        }
        $r = ai_test_saved($pdo, $tid, $adminId);
        flash_set($r['ok'] ? 'ok' : 'error', trim($r['message'] . ' ' . implode(' ', ai_latest_notes($r))));
        ai_back($tid, false, 'setup');
    } elseif ($action === 'forget_key') {
        ai_forget_key($pdo, $tid, $adminId);
        flash_set('ok', 'The key was removed from the panel. The hotel keeps the key it already has.');
        ai_back($tid, false, 'setup');
    } elseif ($action === 'deploy') {
        if (!billing_once_consume('ai_' . $tid, $s('once'))) {
            flash_set('info', 'This was already sent (the form was sent twice). See below.');
            ai_back($tid, false, 'deploy');
        }
        [$err, $cmd] = ai_deploy($pdo, $tenant, $s('switch_on') === '1', $adminId);
        if ($err !== '') {
            flash_set('error', $err);
            ai_back($tid, false, 'deploy');
        }
        flash_set('ok', 'Sent to the hotel. It tests the key again itself, then sets it up, at its next check-in (asked for now, usually within a minute). '
            . 'It shows as Done below once the hotel confirms it.');
        ai_back($tid, true, 'deploy');
    } elseif ($action === 'cancel') {
        $ok = ctype_digit($s('command')) && hms_cancel($pdo, $tid, (int)$s('command'), $adminId);
        flash_set($ok ? 'ok' : 'error', $ok ? 'Cancelled. If the hotel had already set it up, its next report says so.' : 'That set-up is already finished.');
        ai_back($tid, false, 'deploy');
    } elseif ($action === 'refresh') {
        hms_request_list($pdo, $tid, $adminId);
        flash_set('ok', 'Asked the hotel for a fresh report. It arrives at its next check-in (usually within a minute).');
        ai_back($tid, true, 'hotel');
    }
    ai_back($tid);
}

$cfg = ai_config($pdo, $tid);
$state = ai_state($cfg);
$licensed = ai_licensed($tenant);
$listed = ai_listed_models($cfg);
$test = $cfg && !empty($cfg['test_json']) ? json_decode((string)$cfg['test_json'], true) : null;
$commands = ai_commands($pdo, $tid, 8);
$deployed = $cfg && !empty($cfg['deployed_json']) ? json_decode((string)$cfg['deployed_json'], true) : null;
$lastCmd = $commands[0] ?? null;
$hasKey = $cfg && !empty($cfg['key_sealed']);
$provider = (string)($cfg['provider'] ?? '');
$when = static fn(?string $dt): string => $dt ? e(billing_date(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5)) : view_empty();
$usd = static fn(float $v): string => 'US$' . number_format($v, $v < 10 ? 2 : 0);
$dots = "\u{2022}\u{2022}\u{2022}\u{2022}";
$deployProblem = ai_deploy_problem($cfg, $hasKey ? 'x' : null, ai_current_hash($cfg), false, $licensed, time());

view_head('Assistant: ' . $tenant['name']);
view_flash();
echo '<h1>Assistant (AI): ' . e($tenant['name']) . '</h1>'
    . '<p class="muted"><a href="tenant.php?id=' . $tid . '">&larr; Back to the business</a></p>'
    . '<p class="muted">Set up this business\'s Assistant here: its AI provider key and models. The panel tests them first; only a set-up that passed '
    . 'can be sent. The hotel tests the key again itself before it changes anything. Its own administrators may change the Assistant afterwards '
    . '(Assistant Settings in the HMS); nothing here overwrites that until you send again.</p>';

// ---- Subscription
$plan = null;
foreach ($plans as $p) {
    if ((int)$p['id'] === (int)($cfg['plan_item_id'] ?? 0)) {
        $plan = $p;
    }
}
$usage = $state['usage'] ?? null;
$aim = (float)($cfg['monthly_budget_usd'] ?? 5);
echo '<section class="card" id="subscription"><h2>Subscription</h2><dl class="facts">'
    . view_fact('Assistant add-on', $licensed ? '<span class="pill pill-active">In the licence</span>' : '<span class="pill pill-suspended">Not in the licence</span>')
    . view_fact('Plan', $plan ? e($plan['item']) . ' <span class="muted">(' . e(billing_money((float)$plan['unit_price'], (string)$plan['currency'])) . ($plan['unit'] ? ' a ' . e($plan['unit']) : '') . ')</span>' : view_empty())
    . view_fact('Provider paid by', $cfg ? e((string)(AI_PAYERS[$cfg['key_payer']] ?? $cfg['key_payer'])) : e(AI_PAYERS['msp']))
    . view_fact('Monthly aim', e($usd($aim)))
    . view_fact('This month at the hotel', $usage
        ? e($usd((float)$usage['cost_usd'])) . ' <span class="muted">(' . e(number_format($usage['requests'])) . ' questions, '
            . e(number_format(($usage['tokens_in'] + $usage['tokens_out']) / 1000)) . 'k tokens' . ($aim > 0 ? ', ' . e((string)round(100 * $usage['cost_usd'] / $aim)) . '% of the aim' : '') . ')</span>'
        : view_empty())
    . '</dl><p class="muted facts-note">The add-on in the licence is what lets the hotel use the Assistant at all: take it out and the hotel switches the Assistant off at its next check-in '
    . '(chats and settings are kept). The monthly aim is shown to the hotel as a target, never a hard stop. Usage is the hotel\'s own estimate from its last report.</p>'
    . '<div class="tools">'
    . '<form method="post" action="ai.php?id=' . $tid . '" class="tool' . ($licensed ? ' tool-warn' : '') . '"' . ($licensed ? ' data-confirm="Take the Assistant add-on out of this business\'s licence? The hotel switches the Assistant off at its next check-in."' : '') . '>' . csrf_field()
    . '<input type="hidden" name="action" value="' . ($licensed ? 'licence_off' : 'licence_on') . '"><h3>' . ($licensed ? 'Stop the add-on' : 'Start the add-on') . '</h3>'
    . '<p class="muted">' . ($licensed ? 'Takes Assistant (add-on) out of the licensed modules.' : 'Adds Assistant (add-on) to the licensed modules, the same as ticking it on the business page.') . '</p>'
    . '<div class="tool-fields"></div><button type="submit"' . ($licensed ? ' class="danger"' : '') . '>' . ($licensed ? 'Stop the Assistant add-on' : 'Start the Assistant add-on') . '</button></form>'
    . '<form method="post" action="ai.php?id=' . $tid . '" class="tool tool-wide">' . csrf_field() . '<input type="hidden" name="action" value="subscription">'
    . '<h3>Package</h3><p class="muted">How the Assistant is sold to this business. Bill it with a recurring invoice on the Billing pages; add an "Assistant add-on" plan on the Catalog page first if there is none.</p>'
    . '<div class="tool-fields bill-grid">'
    . '<label>Plan<small class="hint">From the Catalog</small><select name="plan_item_id"><option value="">None</option>';
foreach ($plans as $p) {
    echo '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === (int)($cfg['plan_item_id'] ?? 0) ? ' selected' : '') . '>' . e($p['item'] . ' - ' . billing_money((float)$p['unit_price'], (string)$p['currency'])) . '</option>';
}
echo '</select></label>'
    . '<label>Provider paid by<small class="hint">Whose account the key bills</small><select name="key_payer">';
foreach (AI_PAYERS as $k => $l) {
    echo '<option value="' . e($k) . '"' . ($k === (string)($cfg['key_payer'] ?? 'msp') ? ' selected' : '') . '>' . e($l) . '</option>';
}
echo '</select></label>'
    . '<label>Monthly aim (US$)<small class="hint">Expected AI spend a month</small><input type="text" name="monthly_budget_usd" inputmode="decimal" required value="' . e(rtrim(rtrim(number_format($aim, 2, '.', ''), '0'), '.')) . '"></label>'
    . '<label>Notes<small class="hint">Only seen here</small><input type="text" name="notes" maxlength="255" value="' . e((string)($cfg['notes'] ?? '')) . '"></label>'
    . '</div><button type="submit" class="secondary">Save package</button></form></div></section>';

// ---- Provider key and models
$opts = static function (string $role) use ($listed, $cfg): string {
    $sel = (string)($cfg['model_' . $role] ?? '');
    $h = '<option value="">' . ($role === 'primary' ? 'Choose&hellip;' : 'None') . '</option>';
    foreach ($listed ?? [] as $m) {
        $h .= '<option value="' . e($m['model_id']) . '"' . ($m['model_id'] === $sel ? ' selected' : '') . '>' . e($m['label'] !== $m['model_id'] ? $m['label'] . ' (' . $m['model_id'] . ')' : $m['model_id']) . '</option>';
    }
    return $h;
};
echo '<section class="card" id="setup"><h2>Provider key and models</h2>'
    . '<form method="post" action="ai.php?id=' . $tid . '" autocomplete="off">' . csrf_field() . '<input type="hidden" name="action" value="save_test">'
    . '<div class="bill-grid">'
    . '<label>Provider<small class="hint">The AI company</small><select name="provider" required>';
foreach (ai_providers() as $k => $p) {
    echo '<option value="' . e($k) . '"' . ($k === $provider ? ' selected' : '') . '>' . e($p['label']) . '</option>';
}
echo '</select><small>' . e(ai_providers()[$provider !== '' ? $provider : 'anthropic']['hint']) . '</small></label>'
    . '<label>API key<small class="hint">' . ($hasKey ? 'Saved: ' . e($dots . $cfg['key_last4']) . '. Leave empty to keep it' : 'Paste the key') . '</small>'
    . '<input type="password" name="api_key" maxlength="300" autocomplete="new-password" spellcheck="false"' . ($hasKey ? '' : ' required') . '></label>';
if ($listed !== null) {
    foreach (AI_ROLES as $r => $label) {
        echo '<label>' . e($label) . ' model<small class="hint">' . e(AI_ROLE_HELP[$r]) . '</small><select name="model_' . e($r) . '">' . $opts($r) . '</select></label>';
    }
} else {
    echo '<p class="muted span-all">Save and test the key: the newest suitable models are then picked and tested for you.</p>';
}
echo '</div><p class="muted">Save and test lists the provider\'s chat models with this key (newest first), moves to the newest models, '
    . 'then sends each chosen model one short message ("Reply OK", at most 5 words back; a fraction of a cent). Nothing reaches the hotel until you send it below.</p>'
    . '<p class="muted">Always the newest models: with no models chosen, the newest are picked (Primary and Fallback mid-size, Fast a small one); '
    . 'a chosen model is moved to the newest of its family at every test, so an older version chosen here does not stay. '
    . 'Big models (Opus, Fable, Pro, Argon) are never picked for chat, Gemini 4 is never used, and previews or special models are only used when chosen by hand.</p>'
    . '<button type="submit">Save and test</button></form>';
if ($cfg && $cfg['test_ok'] !== null) {
    echo '<h3>Last test</h3><p>' . ((int)$cfg['test_ok'] === 1 ? '<span class="pill pill-active">Passed</span>' : '<span class="pill pill-suspended">Not passed</span>')
        . ' <span class="muted">' . $when((string)$cfg['test_at']) . '</span> ' . e((string)($test['message'] ?? '')) . '</p>';
    foreach ((array)($test['notes'] ?? []) as $note) {
        echo '<p class="muted">' . e((string)$note) . '</p>';
    }
    if (!empty($test['checks'])) {
        echo '<div class="scroll"><table><thead><tr>' . view_th('Role', 'What the model is used for.') . view_th('Model', 'The provider\'s model name.')
            . view_th('Result', 'What the model answered to the test.') . '</tr></thead><tbody>';
        foreach ($test['checks'] as $c) {
            echo '<tr><td data-label="Role">' . e(AI_ROLES[$c['role']] ?? $c['role']) . '</td><td data-label="Model"><code>' . e($c['model']) . '</code></td>'
                . '<td data-label="Result">' . (!empty($c['ok']) ? '<span class="pill pill-active">OK</span> ' : '<span class="pill pill-suspended">Failed</span> ') . e((string)$c['message']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
} elseif ($hasKey) {
    echo '<p class="muted">Not tested since the last change.</p>';
}
if ($hasKey) {
    echo '<form method="post" action="ai.php?id=' . $tid . '" class="spaced" data-confirm="Remove the saved key from the panel? The hotel keeps the key it already has.">' . csrf_field()
        . '<input type="hidden" name="action" value="forget_key"><button type="submit" class="secondary">Remove the key from the panel</button></form>';
}
echo '</section>';

// ---- Send to the hotel
echo '<section class="card" id="deploy"><h2>Send to the hotel</h2>';
if ($deployProblem === '') {
    echo '<form method="post" action="ai.php?id=' . $tid . '" class="js-once">' . csrf_field() . '<input type="hidden" name="action" value="deploy">'
        . '<input type="hidden" name="once" value="' . e(billing_once_issue('ai_' . $tid)) . '">'
        . '<p>Ready: ' . e(ai_provider_label($provider)) . ' key ' . e($dots . $cfg['key_last4']) . ', ';
    $bits = [];
    foreach (AI_ROLES as $r => $label) {
        if ((string)$cfg['model_' . $r] !== '') {
            $bits[] = e($label) . ' <code>' . e((string)$cfg['model_' . $r]) . '</code>';
        }
    }
    echo implode(', ', $bits) . ', aim ' . e($usd($aim)) . ' a month. Tested ' . $when((string)$cfg['test_at']) . '.</p>'
        . '<label class="check"><input type="checkbox" name="switch_on" value="1"' . ($licensed ? ' checked' : ' disabled') . '> Switch the Assistant on at the hotel'
        . ($licensed ? '' : ' <span class="muted">(start the add-on above first)</span>') . '</label>'
        . '<p class="muted">The hotel saves the key encrypted, chooses these models (one role each), sets the monthly aim'
        . ' and, if ticked, switches the Assistant on. If its own test of the key fails, it changes nothing and says why below.</p>'
        . '<button type="submit">Send to the hotel</button></form>';
} else {
    echo '<p class="muted">Not ready to send: ' . e($deployProblem) . '</p>';
}
if ($commands) {
    echo '<h3>Sent</h3><div class="scroll"><table><thead><tr>' . view_th('Asked', 'When it was sent from here.') . view_th('Set-up', 'What was sent.')
        . view_th('Where it is', 'Waiting, sent, or what the hotel answered.') . '<th></th></tr></thead><tbody>';
    foreach ($commands as $c) {
        $open = $c['done_at'] === null;
        $stateWords = hms_command_state($c);
        echo '<tr><td data-label="Asked" class="nw">' . $when((string)$c['created_at']) . '</td><td data-label="Set-up">' . e($c['summary']) . '</td>'
            . '<td data-label="Where it is">' . ($c['result'] === 'ok' ? '<span class="pill pill-active">' . e($stateWords) . '</span>'
                : ($c['result'] === 'failed' ? '<span class="pill pill-suspended">' . e($stateWords) . '</span>'
                : ($open ? '<span class="pill pill-grace">' . e($stateWords) . '</span>' : '<span class="muted">' . e($stateWords) . '</span>'))) . '</td>'
            . '<td data-label="">' . ($open ? '<form method="post" action="ai.php?id=' . $tid . '" class="inline">' . csrf_field()
                . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="command" value="' . (int)$c['id'] . '">'
                . '<button type="submit" class="secondary">Cancel</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// ---- At the hotel now
echo '<section class="card" id="hotel"><h2>At the hotel now</h2>';
if ($state === null) {
    echo '<p class="muted">The hotel has not reported its Assistant yet. That needs the HMS update from November 2026 and one check-in after it.</p>';
} else {
    $modelsWords = [];
    foreach (AI_ROLES as $r => $label) {
        $m = ai_state_model($state, $r);
        if ($m !== '') {
            $modelsWords[] = e($label) . ' <code>' . e($m) . '</code>';
        }
    }
    echo '<dl class="facts">'
        . view_fact('Assistant', !$state['ready'] ? '<span class="pill pill-suspended">Not installed</span>'
            : ($state['enabled'] ? '<span class="pill pill-active">On</span>' : '<span class="pill pill-grace">Off</span>'))
        . view_fact('Provider', $state['provider'] !== '' ? e(ai_provider_label($state['provider'])) : view_empty())
        . view_fact('Key', $state['key_last4'] !== '' ? e($dots . $state['key_last4']) . ($state['key_status'] !== '' ? ' <span class="muted">(' . e($state['key_status']) . ')</span>' : '') : view_empty())
        . view_fact('Models', $modelsWords ? implode(', ', $modelsWords) : view_empty())
        . view_fact('Monthly aim', e($usd($state['budget_usd'])))
        . view_fact('Reported', $when((string)($cfg['hms_state_at'] ?? '')))
        . '</dl>';
    if (!$state['enabled'] && $state['off_reason'] !== '') {
        echo '<p class="muted">Why it is off: ' . e($state['off_reason']) . '</p>';
    }
    if (!$state['cloud_on']) {
        echo '<p class="flash flash-error">The hotel has switched cloud providers off in its Assistant Settings, so no set-up sent from here can be tested or used there until it switches them back on.</p>';
    }
    if ($state['last_test_message'] !== '') {
        echo '<p class="muted">The hotel\'s own last key test' . ($state['last_test_at'] !== '' ? ' (' . e($state['last_test_at']) . ')' : '') . ': ' . e($state['last_test_message']) . '</p>';
    }
    if (is_array($deployed) && $lastCmd && $lastCmd['result'] === 'ok') {
        $diff = ai_differences($deployed, $state);
        echo $diff ? '<p><strong>Changed at the hotel since it was sent from here</strong> (its administrators may do this):</p><ul><li>' . implode('</li><li>', array_map('e', $diff)) . '</li></ul>'
            . '<p class="muted">Nothing is changed back unless you send a set-up again.</p>'
            : '<p class="muted">Matches what was sent from here.</p>';
    }
}
echo '<form method="post" action="ai.php?id=' . $tid . '" class="spaced">' . csrf_field() . '<input type="hidden" name="action" value="refresh">'
    . '<button type="submit" class="secondary">Ask the hotel for a fresh report</button></form></section>';
echo '<script src="assets/billing.js" defer></script>';
view_foot();
