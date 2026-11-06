<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];
$today = status_today();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$form = null; // sticky values after a failed save

function back_to(int $tenantId): void
{
    header('Location: tenant.php' . ($tenantId > 0 ? '?id=' . $tenantId : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $postId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        if ($action === 'save') {
            [$errors, $clean] = tenant_validate($_POST, $postId === 0);
            $form = $clean;
            $form['monthly_fee'] = $_POST['monthly_fee'] ?? '';
            if (!$errors) {
                $pdo->beginTransaction();
                if ($postId === 0) {
                    $key = tenant_new_api_key();
                    $st = $pdo->prepare(
                        'INSERT INTO cp_tenants (slug, name, business_type, modules, domain, plan, monthly_fee, currency, paid_until, grace_days, readonly_days, api_key_hash, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $clean['slug'], $clean['name'], $clean['business_type'], $clean['modules_json'], $clean['domain'], $clean['plan'], $clean['monthly_fee'],
                        $clean['currency'], $clean['paid_until'], $clean['grace_days'], $clean['readonly_days'],
                        password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]), $clean['notes'],
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $adminId, 'tenant_create', $newId, null, tenant_find($pdo, $newId));
                    $pdo->commit();
                    $_SESSION['new_key'] = [$newId, $key];
                    flash_set('ok', 'Business added. Copy the API key below now: it is shown only once.');
                    back_to($newId);
                }
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $st = $pdo->prepare(
                    'UPDATE cp_tenants SET name=?, business_type=?, modules=?, domain=?, plan=?, monthly_fee=?, currency=?, paid_until=?,
                        grace_days=?, readonly_days=?, notes=? WHERE id=?'
                );
                $st->execute([
                    $clean['name'], $clean['business_type'], $clean['modules_json'], $clean['domain'], $clean['plan'], $clean['monthly_fee'], $clean['currency'],
                    $clean['paid_until'], $clean['grace_days'], $clean['readonly_days'], $clean['notes'], $postId,
                ]);
                audit_log($pdo, $adminId, 'tenant_update', $postId, $before, tenant_find($pdo, $postId));
                $pdo->commit();
                flash_set('ok', 'Saved.');
                back_to($postId);
            }
        } elseif ($action === 'payment' && $postId > 0) {
            $months = isset($_POST['months']) && ctype_digit((string)$_POST['months']) ? (int)$_POST['months'] : 0;
            $amount = isset($_POST['amount']) && is_string($_POST['amount']) ? trim($_POST['amount']) : '';
            $ref = isset($_POST['reference']) && is_string($_POST['reference']) ? trim($_POST['reference']) : '';
            if ($months < 1 || $months > 60) {
                $errors[] = 'Months must be a whole number from 1 to 60.';
            }
            if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount)) {
                $errors[] = 'Enter the amount received as a number.';
            }
            if (mb_strlen($ref) < 3 || mb_strlen($ref) > 190) {
                $errors[] = 'A payment reference of 3 to 190 characters is required (receipt, transfer or cheque number).';
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $base = max($today, $before['paid_until']);
                $newDate = status_add_months($base, $months);
                $pdo->prepare('UPDATE cp_tenants SET paid_until = ? WHERE id = ?')->execute([$newDate, $postId]);
                $pdo->prepare(
                    'INSERT INTO cp_payments (tenant_id, admin_id, amount, currency, months, reference, paid_until_before, paid_until_after)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$postId, $adminId, $amount, $before['currency'], $months, $ref, $before['paid_until'], $newDate]);
                $after = tenant_find($pdo, $postId);
                $after['_payment'] = ['amount' => $amount, 'currency' => $before['currency'], 'months' => $months, 'reference' => $ref];
                audit_log($pdo, $adminId, 'payment_recorded', $postId, $before, $after);
                $pdo->commit();
                flash_set('ok', 'Payment recorded. Paid until is now ' . $newDate . '.');
                back_to($postId);
            }
        } elseif ($action === 'override_set' && $postId > 0) {
            $ov = isset($_POST['override']) && is_string($_POST['override']) ? $_POST['override'] : '';
            $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? trim($_POST['reason']) : '';
            if (!in_array($ov, status_list(), true)) {
                $errors[] = 'Choose a status for the override.';
            }
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
                $errors[] = 'A reason of 5 to 255 characters is required for an override.';
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $pdo->prepare('UPDATE cp_tenants SET status_override = ?, override_reason = ? WHERE id = ?')
                    ->execute([$ov, $reason, $postId]);
                audit_log($pdo, $adminId, 'override_set', $postId, $before, tenant_find($pdo, $postId));
                $pdo->commit();
                flash_set('ok', 'Override set. The business picks it up at its next check-in.');
                back_to($postId);
            }
        } elseif ($action === 'override_clear' && $postId > 0) {
            $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? trim($_POST['reason']) : '';
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
                $errors[] = 'A reason of 5 to 255 characters is required to clear an override.';
            } else {
                $pdo->beginTransaction();
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $pdo->prepare('UPDATE cp_tenants SET status_override = NULL, override_reason = NULL WHERE id = ?')->execute([$postId]);
                $after = tenant_find($pdo, $postId);
                $after['_reason'] = $reason;
                audit_log($pdo, $adminId, 'override_cleared', $postId, $before, $after);
                $pdo->commit();
                flash_set('ok', 'Override cleared. Status now follows the paid-until date.');
                back_to($postId);
            }
        } elseif ($action === 'regen_key' && $postId > 0) {
            $pdo->beginTransaction();
            $before = tenant_find($pdo, $postId, true);
            if (!$before) {
                $pdo->rollBack();
                back_to(0);
            }
            $key = tenant_new_api_key();
            $pdo->prepare('UPDATE cp_tenants SET api_key_hash = ? WHERE id = ?')
                ->execute([password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]), $postId]);
            audit_log($pdo, $adminId, 'api_key_regenerated', $postId, ['key_set' => $before['api_key_hash'] !== null], ['key_set' => true]);
            $pdo->commit();
            $_SESSION['new_key'] = [$postId, $key];
            flash_set('ok', 'New API key created. The old key no longer works. Copy the new key now: it is shown only once.');
            back_to($postId);
        } else {
            http_response_code(400);
            echo 'Invalid request.';
            exit;
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex->getCode() === '23000') {
            $errors[] = 'That short name is already used by another business.';
        } else {
            error_log('tenant.php: ' . $ex->getMessage());
            $errors[] = 'Could not save. Nothing was changed.';
        }
    }
    if ($postId > 0) {
        $id = $postId;
    }
}

$tenant = $id > 0 ? tenant_find($pdo, $id) : null;
if ($id > 0 && !$tenant) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Business not found. <a href="index.php">Back to businesses</a></p>';
    view_foot();
    exit;
}

// One-time API key display (consumed on first render).
$shownKey = null;
if ($tenant && isset($_SESSION['new_key']) && (int)$_SESSION['new_key'][0] === (int)$tenant['id']) {
    $shownKey = $_SESSION['new_key'][1];
}
unset($_SESSION['new_key']);

$v = function (string $k, $default = '') use ($form, $tenant) {
    if (is_array($form) && array_key_exists($k, $form)) {
        return $form[$k];
    }
    if ($tenant && array_key_exists($k, $tenant)) {
        return $tenant[$k];
    }
    return $default;
};

view_head($tenant ? $tenant['name'] : 'Add business');
view_flash();
foreach ($errors as $m) {
    echo '<p class="flash flash-error">' . e($m) . '</p>';
}

echo '<h1>' . ($tenant ? e($tenant['name']) : 'Add business') . '</h1>';

if ($tenant) {
    $computed = status_compute($tenant, $today);
    $sched = status_schedule($tenant['paid_until'], (int)$tenant['grace_days'], (int)$tenant['readonly_days'], $today);
    echo '<section class="card"><h2>Status now</h2><p>' . view_pill($computed, !empty($tenant['status_override'])) . '</p>';
    if (!empty($tenant['status_override'])) {
        echo '<p class="muted">Set by hand: ' . e($tenant['override_reason']) . '. Without the override the date would give: '
            . e($sched) . '.</p>';
    } else {
        $graceEnd = status_add_days($tenant['paid_until'], (int)$tenant['grace_days']);
        $roEnd = status_add_days($graceEnd, (int)$tenant['readonly_days']);
        echo '<p class="muted">Active until ' . e($tenant['paid_until']) . ', grace until ' . e($graceEnd)
            . ', read only until ' . e($roEnd) . ', suspended after that.</p>';
    }
    echo '</section>';

    if ($shownKey !== null) {
        echo '<section class="card key"><h2>API key (shown once)</h2>'
            . '<p>Copy this into the customer system now. It cannot be shown again; if lost, generate a new one.</p>'
            . '<p><code class="keybox">' . e($shownKey) . '</code></p>'
            . '<p class="muted">Short name to use with it: <code>' . e($tenant['slug']) . '</code></p></section>';
    }

    echo '<section class="card"><h2>Record payment</h2>'
        . '<p class="muted">Extends "paid until" from today or the current paid-until date, whichever is later.</p>'
        . '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '">' . csrf_field()
        . '<input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
        . '<label>Months paid<input type="number" name="months" min="1" max="60" value="1" required></label>'
        . '<label>Amount received (' . e($tenant['currency']) . ')<input type="text" name="amount" inputmode="decimal" required'
        . ' value="' . e($tenant['monthly_fee']) . '"></label>'
        . '<label>Reference (receipt, transfer or cheque number)<input type="text" name="reference" required minlength="3" maxlength="190"></label>'
        . '<button type="submit">Record payment</button></form></section>';

    echo '<section class="card"><h2>Status override</h2>'
        . '<p class="muted">Forces a status regardless of the date, for example to keep a friendly customer active or to lock one at once. '
        . 'An "active" override is lifted if the business cannot reach this panel for over 35 days and the date has passed.</p>';
    echo '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '">' . csrf_field()
        . '<input type="hidden" name="action" value="override_set"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
        . '<label>Force status<select name="override" required>';
    foreach (status_list() as $s) {
        echo '<option value="' . e($s) . '"' . ($tenant['status_override'] === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    echo '</select></label><label>Reason (required)<input type="text" name="reason" required minlength="5" maxlength="255"></label>'
        . '<button type="submit">Set override</button></form>';
    if (!empty($tenant['status_override'])) {
        echo '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '" class="spaced">' . csrf_field()
            . '<input type="hidden" name="action" value="override_clear"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
            . '<label>Reason for clearing (required)<input type="text" name="reason" required minlength="5" maxlength="255"></label>'
            . '<button type="submit" class="secondary">Clear override</button></form>';
    }
    echo '</section>';
}

// Details form (add or edit).
echo '<section class="card"><h2>' . ($tenant ? 'Details' : 'New business') . '</h2>'
    . '<form method="post" action="tenant.php' . ($tenant ? '?id=' . (int)$tenant['id'] : '') . '">' . csrf_field()
    . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . ($tenant ? (int)$tenant['id'] : 0) . '">';
if ($tenant) {
    echo '<label>Short name (identifier, cannot change)<input type="text" value="' . e($tenant['slug']) . '" disabled></label>';
} else {
    echo '<label>Short name (lowercase, used by the customer system to identify itself)'
        . '<input type="text" name="slug" required maxlength="63" pattern="[a-z0-9][a-z0-9\-]{1,62}" value="' . e($v('slug')) . '"></label>';
}
$selType = (string)$v('business_type', 'full_hotel');
if (!isset(catalog_business_types()[$selType])) {
    $selType = 'custom';
}
$selMods = is_array($form) && isset($form['modules']) ? $form['modules']
    : ($tenant ? catalog_tenant_modules($tenant) : catalog_clean_modules(catalog_business_types()[$selType][1]));
echo '<label>Business name<input type="text" name="name" required maxlength="190" value="' . e($v('name')) . '"></label>'
    . '<label>Business type (ticks the usual modules; change them below if needed)<select name="business_type" id="business_type" required>';
foreach (catalog_business_types() as $tk => $tv) {
    echo '<option value="' . e($tk) . '" data-modules="' . e(implode(',', $tv[1])) . '"' . ($selType === $tk ? ' selected' : '') . '>' . e($tv[0]) . '</option>';
}
echo '</select></label><fieldset class="modules" id="modules"><legend>Licensed modules (what this business may use)</legend>';
foreach (catalog_modules() as $mk => $ml) {
    echo '<label class="check"><input type="checkbox" name="modules[]" value="' . e($mk) . '"' . (in_array($mk, $selMods, true) ? ' checked' : '') . '> ' . e($ml) . '</label>';
}
echo '</fieldset>';
echo '<label>Website domain (must match what the customer system reports)<input type="text" name="domain" required maxlength="255" value="' . e($v('domain')) . '"></label>'
    . '<label>Plan<input type="text" name="plan" maxlength="60" value="' . e($v('plan')) . '"></label>'
    . '<label>Monthly fee<input type="text" name="monthly_fee" inputmode="decimal" required value="' . e($v('monthly_fee', '0')) . '"></label>'
    . '<label>Currency<input type="text" name="currency" required maxlength="3" value="' . e($v('currency', 'MWK')) . '"></label>'
    . '<label>Paid until (YYYY-MM-DD; use Record payment to extend)<input type="date" name="paid_until" required value="' . e($v('paid_until')) . '"></label>'
    . '<label>Grace days (banner shown, everything works)<input type="number" name="grace_days" min="0" max="365" required value="' . e($v('grace_days', '14')) . '"></label>'
    . '<label>Read-only days (view, check-out and export only)<input type="number" name="readonly_days" min="0" max="365" required value="' . e($v('readonly_days', '14')) . '"></label>'
    . '<label>Notes<textarea name="notes" rows="3" maxlength="2000">' . e($v('notes')) . '</textarea></label>'
    . '<button type="submit">' . ($tenant ? 'Save details' : 'Add business') . '</button></form></section>';

if ($tenant) {
    echo '<section class="card"><h2>API key</h2>'
        . '<p class="muted">' . ($tenant['api_key_hash'] ? 'A key is set. It cannot be displayed.' : 'No key set; the business cannot check in.')
        . ' Generating a new key stops the old one working immediately; update the customer system straight after.</p>'
        . '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '">' . csrf_field()
        . '<input type="hidden" name="action" value="regen_key"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
        . '<button type="submit" class="danger">Generate new API key</button></form></section>';

    $pay = $pdo->prepare('SELECT * FROM cp_payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 10');
    $pay->execute([(int)$tenant['id']]);
    $payments = $pay->fetchAll();
    echo '<section class="card"><h2>Recent payments</h2>';
    if (!$payments) {
        echo '<p class="muted">None recorded yet.</p>';
    } else {
        echo '<div class="scroll"><table><thead><tr>'
            . view_th('Recorded', 'When the payment was entered here.', 'When entered')
            . view_th('Amount', 'Money received.')
            . view_th('Months', 'How many months it covered.')
            . view_th('Paid until', 'The paid-until date before and after this payment.', 'Before and after')
            . view_th('Reference', 'Receipt, transfer or cheque number you entered.', 'Receipt or transfer no.')
            . '</tr></thead><tbody>';
        foreach ($payments as $p) {
            echo '<tr><td data-label="Recorded" class="nw">' . e($p['created_at']) . '</td>'
                . '<td data-label="Amount" class="nw">' . e($p['amount']) . ' ' . e($p['currency']) . '</td>'
                . '<td data-label="Months">' . (int)$p['months'] . '</td>'
                . '<td data-label="Paid until" class="nw">' . e($p['paid_until_before']) . ' to ' . e($p['paid_until_after']) . '</td>'
                . '<td data-label="Reference">' . (($p['reference'] ?? '') !== '' ? e($p['reference']) : view_empty()) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    $ck = $pdo->prepare('SELECT * FROM cp_licence_checks WHERE tenant_id = ? ORDER BY id DESC LIMIT 25');
    $ck->execute([(int)$tenant['id']]);
    $checks = $ck->fetchAll();
    echo '<section class="card"><h2>Recent check-ins</h2>';
    if (!$checks) {
        echo '<p class="muted">No check-ins yet.</p>';
    } else {
        echo '<div class="scroll"><table><thead><tr>'
            . view_th('When', 'Time the customer system contacted this panel.', 'Time of contact')
            . view_th('Result', 'The status it was given, or "denied" if the key or domain was wrong.', 'Status given')
            . view_th('Domain reported', 'The website address the customer system said it was running on.', 'Address it reported')
            . view_th('Version', 'Software version reported.')
            . view_th('IP', 'Address the request came from.', 'Request address')
            . '</tr></thead><tbody>';
        foreach ($checks as $c) {
            echo '<tr><td data-label="When" class="nw">' . e($c['checked_at']) . '</td>'
                . '<td data-label="Result">' . e($c['result_status']) . '</td>'
                . '<td data-label="Domain reported">' . (($c['domain_reported'] ?? '') !== '' ? view_trunc($c['domain_reported']) : view_empty()) . '</td>'
                . '<td data-label="Version" class="nw">' . (($c['pms_version'] ?? '') !== '' ? e($c['pms_version']) : view_empty()) . '</td>'
                . '<td data-label="IP" class="nw">' . (($c['ip'] ?? '') !== '' ? e($c['ip']) : view_empty()) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
view_foot();
