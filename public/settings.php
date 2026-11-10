<?php
// Billing settings (owner 2026-11-09): company profile printed on every invoice, quotation, statement, receipt and email;
// numbering, due days, tax, reminders, signature; saved items; test email and SMTP status. Contract: BILLING.md.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$errors = [];
$form = null;
$itemErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    try {
        if ($action === 'save_settings') {
            $in = [];
            foreach (array_keys(billing_setting_defaults()) as $k) {
                if (isset($_POST[$k]) && is_string($_POST[$k])) {
                    $in[$k] = $_POST[$k];
                }
            }
            unset($in['auto_reminders']); // the reminder mode (off / approve / auto) is set on automations.php
            [$errors, $clean] = billing_settings_validate($in);
            if (!$errors) {
                billing_settings_save($pdo, $clean, $adminId);
                flash_set('ok', 'Settings saved.');
                header('Location: settings.php');
                exit;
            }
            $form = $in;
        } elseif ($action === 'save_item') {
            $itemId = ctype_digit($s('item_id')) ? (int)$s('item_id') : 0;
            [$itemErrors, $c] = billing_catalog_validate($_POST);
            if (!$itemErrors) {
                $ok = billing_catalog_save($pdo, $itemId, $c, $adminId);
                flash_set($ok ? 'ok' : 'error', $ok ? ($itemId ? 'Saved item updated.' : 'Saved item added.') : 'That saved item no longer exists.');
                header('Location: settings.php#items');
                exit;
            }
        } elseif ($action === 'test_email') {
            if (!billing_has_mail() || !function_exists('billing_send_test')) {
                flash_set('error', 'Sending email is not available yet.');
            } else {
                $r = billing_send_test($pdo, [$admin['email']], $adminId);
                flash_set(!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok'])
                    ? 'Test email sent to ' . implode(', ', (array)($r['to'] ?? [])) . (!empty($r['test']) ? ' (the test address: MAIL_TEST_TO is set).' : '.')
                    : 'Test email failed: ' . (string)($r['error'] ?? 'unknown error'));
            }
            header('Location: settings.php#email');
            exit;
        } else {
            http_response_code(400);
            echo 'Invalid request.';
            exit;
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('settings.php: ' . $ex->getMessage());
        $errors[] = 'Could not save. Nothing was changed.';
    }
}

billing_seed_catalog($pdo, $adminId); // first use only: fills the saved items with the usual lines (locked, audited, once)
billing_seed_plans($pdo, $adminId);   // Part 3, first use only: the usual plans at price 0 when no item is a plan yet
$set = billing_settings_all();
$v = static function (string $k) use ($form, $set): string {
    return is_array($form) && array_key_exists($k, $form) ? (string)$form[$k] : (string)($set[$k] ?? '');
};
$text = static fn(string $k, string $label, int $max = 190, string $type = 'text'): string =>
    '<label>' . e($label) . '<input type="' . e($type) . '" name="' . e($k) . '" maxlength="' . $max . '" value="' . e($v($k)) . '"></label>';
$area = static fn(string $k, string $label, int $rows, int $max): string =>
    '<label>' . e($label) . '<textarea name="' . e($k) . '" rows="' . $rows . '" maxlength="' . $max . '">' . e($v($k)) . '</textarea></label>';

view_head('Settings');
echo '<h1>Settings</h1>';
view_flash();
foreach (array_merge($errors, $itemErrors) as $m) {
    echo '<p class="flash flash-error">' . e($m) . '</p>';
}
echo '<p class="muted">These details are printed on every invoice, quotation, statement and receipt, and on the emails that carry them.</p>';

echo '<form method="post" action="settings.php">' . csrf_field() . '<input type="hidden" name="action" value="save_settings">'
    . '<section class="card"><h2>Company</h2><div class="bill-grid">'
    . $text('company_name', 'Company name')
    . $area('company_address', 'Address (one line per row)', 3, 1000)
    . $area('company_emails', 'Emails (one per row)', 2, 1000)
    . $text('company_phone', 'Phone', 60)
    . $text('company_website', 'Website', 190, 'url')
    . $text('salesperson', 'Salesperson (default on new documents)', 120)
    . $text('brand_color', 'Brand red (titles, table header)', 7)
    . $text('brand_navy', 'Brand navy (logo colour)', 7)
    . '</div></section>'
    . '<section class="card"><h2>Payment details</h2>'
    . '<p class="muted">Printed in bold under the totals of every invoice and in emails. Lines of dashes are kept as separators.</p>'
    . $area('payment_details', 'Payment details', 14, 4000)
    . $text('thank_you', 'Thank-you line', 500)
    . '</section>'
    . '<section class="card"><h2>Numbering, dates and tax</h2><div class="bill-grid">'
    . $text('invoice_next_number', 'Next invoice number', 40)
    . $text('quote_next_number', 'Next quotation number', 40)
    . $text('invoice_due_days', 'Invoice due after (days)', 3)
    . $text('quote_valid_days', 'Quotation valid for (days)', 3)
    . $text('default_currency', 'Default currency', 3)
    . $text('tax_rate', 'Tax rate on new documents (%)', 6)
    . $text('tax_label', 'Tax name (printed)', 20)
    . '</div><p class="muted">Numbers count up from the trailing digits: 170134, 170135… or Q-1001, Q-1002… A number already used is skipped.</p></section>'
    . '<section class="card"><h2>Reminders</h2>'
    . $text('reminder_days', 'Reminder days', 60)
    . '<p class="muted">Days before the due date, then 0 for the due date itself, then days after it. '
    . 'For example <code>3,0,7,14</code> reminds 3 days before, on the day, and 7 and 14 days after. Without a 0, every number counts as days after the due date. '
    . 'Only sent invoices with a balance get reminders, at most one a day.</p>'
    . '<p class="muted">Whether reminders go out automatically, wait in the Outbox, or are off is set on <a href="automations.php">Automations</a>.</p>'
    . '</section>'
    . '<section class="card" id="trials"><h2>Free trials and plans</h2><div class="bill-grid">'
    . $text('trial_grace_days', 'After a trial: grace days (everything works)', 3)
    . $text('trial_readonly_days', 'After a trial: view-only days, then paused', 3)
    . $text('trial_reminder_days', 'Trial reminder days before the end', 30)
    . $text('default_grace_days', 'Normal grace days (after converting)', 3)
    . $text('default_readonly_days', 'Normal read-only days (after converting)', 3)
    . $text('annual_discount_pct', 'Annual discount for yearly billing (%)', 6)
    . '</div><p class="muted">A trial sets paid until to its last day; after it the business gets the grace and view-only days above, then the staff area pauses '
    . '(data is never deleted). Reminder days: for example <code>7,3,1</code> emails 7, 3 and 1 days before the end. Converting a trial restores the normal days. '
    . 'The annual discount is shown as a discount line when a monthly plan is billed once a year. Which trial emails go out is set on <a href="automations.php">Automations</a>.</p></section>'
    . '<section class="card"><h2>Email signature</h2>' . $area('email_signature', 'Signature (under every email)', 4, 1000) . '</section>'
    . '<p><button type="submit">Save settings</button></p></form>';

// Saved items
$items = billing_catalog($pdo, false);
echo '<section class="card" id="items"><h2>Saved items</h2>'
    . '<p class="muted">Products and services you sell often (hosting, hardware, software…). Pick them on an invoice or quotation with "Saved item". '
    . 'The cost price is what one costs you: it is copied to the invoice line and gives the profit (never printed). Untick Active to hide one without losing it.</p>';
$itemForm = static function (?array $it): string {
    $id = $it ? (int)$it['id'] : 0;
    $cat = (string)($it['category'] ?? 'service');
    $opts = '';
    foreach (billing_catalog_categories() as $k => $l) {
        $opts .= '<option value="' . e($k) . '"' . ($cat === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return '<form method="post" action="settings.php#items" class="bill-item-form">' . csrf_field()
        . '<input type="hidden" name="action" value="save_item"><input type="hidden" name="item_id" value="' . $id . '">'
        . '<div class="bill-grid"><label>Category<select name="category">' . $opts . '</select></label>'
        . '<label>SKU / code<small class="hint">Optional</small><input type="text" name="sku" maxlength="60" value="' . e($it['sku'] ?? '') . '"></label></div>'
        . '<label>Item<input type="text" name="item" required maxlength="190" value="' . e($it['item'] ?? '') . '"></label>'
        . '<label>Description<textarea name="description" rows="2" maxlength="2000">' . e($it['description'] ?? '') . '</textarea></label>'
        . '<div class="bill-grid"><label>Unit price<input type="text" name="unit_price" inputmode="decimal" required value="' . e($it ? number_format((float)$it['unit_price'], 2, '.', '') : '') . '"></label>'
        . '<label>Unit<small class="hint">For example month, piece, hour</small><input type="text" name="unit" maxlength="20" value="' . e($it['unit'] ?? '') . '"></label>'
        . '<label>Cost price<small class="hint">Yours, optional</small><input type="text" name="cost_price" inputmode="decimal" value="' . e($it && $it['cost_price'] !== null ? number_format((float)$it['cost_price'], 2, '.', '') : '') . '"></label></div>'
        . '<label>Currency<input type="text" name="currency" required maxlength="3" value="' . e($it['currency'] ?? billing_setting('default_currency')) . '"></label>'
        . '<label>Plan<small class="hint">Bill this every…</small><select name="billing_interval"><option value="">Not a plan (one-off item)</option>'
        . implode('', array_map(static fn($m, $l) => '<option value="' . $m . '"' . ((int)($it['billing_interval'] ?? 0) === $m ? ' selected' : '') . '>' . e($l) . ' (' . e(plan_interval_words($m)) . ')</option>',
            array_keys(PLAN_INTERVALS), PLAN_INTERVALS)) . '</select></label>'
        . '<label>Order<input type="number" name="sort" min="0" max="9999" value="' . e((string)($it['sort'] ?? '0')) . '"></label>'
        . '<label class="check"><input type="checkbox" name="is_active" value="1"' . (!$it || (int)$it['is_active'] === 1 ? ' checked' : '') . '> Active</label>'
        . '<button type="submit">' . ($it ? 'Save item' : 'Add item') . '</button></form>';
};
if ($items) {
    $cats = billing_catalog_categories();
    echo '<div class="scroll"><table><thead><tr>' . view_th('Category', 'Kind of product or service.') . view_th('Item', 'Name printed in the Item column.')
        . view_th('SKU', 'Your product code.') . view_th('Description', 'Printed beside the item.')
        . view_th('Unit price', 'Price of one.') . view_th('Cost', 'What one costs you (never printed).')
        . view_th('Plan', 'A plan is billed every 1, 3 or 12 months: pick it with Subscribe on a client page, or when converting a free trial.', 'Billed every')
        . view_th('Active', 'Shown in the Saved item list.') . view_th('', 'Change this item.', 'Edit')
        . '</tr></thead><tbody>';
    foreach ($items as $it) {
        echo '<tr><td data-label="Category">' . e($cats[$it['category']] ?? $it['category']) . '</td><td data-label="Item">' . e($it['item']) . '</td>'
            . '<td data-label="SKU" class="nw">' . (($it['sku'] ?? '') !== '' ? e($it['sku']) : view_empty()) . '</td>'
            . '<td data-label="Description">' . (($it['description'] ?? '') !== '' ? view_trunc($it['description']) : view_empty()) . '</td>'
            . '<td data-label="Unit price" class="nw num">' . e(billing_money((float)$it['unit_price'], $it['currency'])) . (($it['unit'] ?? '') !== '' ? ' <span class="muted">/ ' . e($it['unit']) . '</span>' : '') . '</td>'
            . '<td data-label="Cost" class="nw num">' . ($it['cost_price'] !== null ? e(billing_money((float)$it['cost_price'], $it['currency'])) : view_empty()) . '</td>'
            . '<td data-label="Plan" class="nw">' . ($it['billing_interval'] !== null ? e(PLAN_INTERVALS[(int)$it['billing_interval']] ?? plan_interval_words((int)$it['billing_interval'])) : view_empty()) . '</td>'
            . '<td data-label="Active">' . ((int)$it['is_active'] === 1 ? '<span class="pill pill-active">Active</span>' : '<span class="pill pill-suspended">Hidden</span>') . '</td>'
            . '<td data-label="Edit"><details><summary>Edit</summary>' . $itemForm($it) . '</details></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '<details class="spaced"><summary>Add a saved item</summary>' . $itemForm(null) . '</details></section>';

// Email
echo '<section class="card" id="email"><h2>Email</h2>';
if (billing_has_mail() && function_exists('mail_status')) {
    $ms = mail_status();
    echo '<p>' . (!empty($ms['configured'])
            ? 'Sending through <strong>' . e((string)$ms['host']) . '</strong>' . ((string)($ms['user'] ?? '') !== '' ? ' as <strong>' . e((string)$ms['user']) . '</strong>' : '')
                . ' <span class="muted">(settings from ' . e((string)($ms['source'] ?? '')) . ')</span>'
            : '<span class="pill pill-suspended">Not set up</span> No mail server is configured.') . '</p>';
    if ((string)($ms['from_email'] ?? '') !== '') {
        echo '<p class="muted">From: ' . e(trim((string)($ms['from_name'] ?? '') . ' <' . (string)$ms['from_email'] . '>')) . '</p>';
    }
    $testTo = $ms['test_to'] ?? '';
    $testTo = is_array($testTo) ? implode(', ', $testTo) : (string)$testTo;
    if ($testTo !== '') {
        echo '<p class="flash flash-info">Test mode: every email goes only to ' . e($testTo) . ' (MAIL_TEST_TO), never to customers.</p>';
    }
    $pwOff = pwreset_off_reason();
    echo '<p>"Forgot your password?" emails: ' . ($pwOff === '' ? '<strong>on</strong> <span class="muted">(the link changes the password only; a 6-digit code is still needed)</span>'
            : '<strong>off</strong> <span class="muted">' . e($pwOff) . '</span>') . '</p>';
    if ($pwOff === '' && $testTo !== '') {
        echo '<p class="flash flash-info">While test mode is on, every reset link goes to ' . e($testTo) . ', not to the person who asked. Remove MAIL_TEST_TO before your users rely on it.</p>';
    }
    echo '<form method="post" action="settings.php#email">' . csrf_field() . '<input type="hidden" name="action" value="test_email">'
        . '<button type="submit" class="secondary">Send a test email</button> <span class="muted">to ' . e($testTo !== '' ? $testTo : $admin['email']) . '</span></form>';
} else {
    echo '<p class="muted">Sending email is not available yet.</p>';
}
$log = $pdo->query('SELECT * FROM cp_email_log ORDER BY id DESC LIMIT 15')->fetchAll();
if ($log) {
    echo '<h2 class="spaced">Recent emails</h2><div class="scroll"><table><thead><tr>' . view_th('When', 'Time it was sent.') . view_th('What', 'Kind of email.')
        . view_th('To', 'Who it went to.') . view_th('Subject', 'Email subject.') . view_th('Result', 'Sent, failed, or test.') . '</tr></thead><tbody>';
    foreach ($log as $l) {
        $pill = $l['status'] === 'sent' ? 'active' : ($l['status'] === 'test' ? 'grace' : 'suspended');
        echo '<tr><td data-label="When" class="nw">' . e($l['created_at']) . '</td><td data-label="What">'
            . ($l['document_id'] !== null ? '<a href="document.php?id=' . (int)$l['document_id'] . '">' . e($l['kind']) . '</a>' : e($l['kind'])) . '</td>'
            . '<td data-label="To">' . view_trunc($l['to_email']) . '</td><td data-label="Subject">' . view_trunc($l['subject']) . '</td>'
            . '<td data-label="Result"><span class="pill pill-' . e($pill) . '">' . e($l['status']) . '</span>'
            . (($l['error'] ?? '') !== '' ? ' <span class="muted">' . e($l['error']) . '</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
view_foot();
