<?php
// Recurring invoices (owner 2026-11-09, BILLING.md Part 2): schedules that make an invoice every N months for a client.
// The daily run (scripts/billing-daily.php) makes the invoice and sends it / puts it in the Outbox / leaves a draft per
// the schedule's setting. Here: the list, add / edit a schedule, pause it, or make the next invoice now (as a draft).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];
$today = status_today();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isNew = isset($_GET['new']);
$preClient = isset($_GET['client']) && ctype_digit((string)$_GET['client']) ? (int)$_GET['client'] : 0;
$errors = [];
$form = null;
$formLines = null;

function rec_posted_lines(): array
{
    $cols = ['qty' => 'line_qty', 'item' => 'line_item', 'description' => 'line_description', 'unit_price' => 'line_unit_price',
        'cost_price' => 'line_cost_price', 'catalog_item_id' => 'line_catalog_id'];
    $data = [];
    $n = 0;
    foreach ($cols as $k => $name) {
        $data[$k] = isset($_POST[$name]) && is_array($_POST[$name]) ? array_values($_POST[$name]) : [];
        $n = max($n, count($data[$k]));
    }
    $lines = [];
    for ($i = 0; $i < min($n, 200); $i++) {
        $l = [];
        foreach ($cols as $k => $name) {
            $v = $data[$k][$i] ?? '';
            $l[$k] = is_string($v) ? $v : '';
        }
        $lines[] = $l;
    }
    return $lines;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $postId = ctype_digit($s('id')) ? (int)$s('id') : 0;
    try {
        if ($action === 'save' && $postId === 0 && !billing_once_consume('rec_new', $s('once'))) {
            // a double-click on Save would add the same schedule twice (and bill the client twice every period)
            flash_set('info', 'That schedule was already added.');
            header('Location: recurring.php');
            exit;
        }
        if ($action === 'run_now' && $postId > 0 && !billing_once_consume('rec_run_' . $postId, $s('once'))) {
            flash_set('info', 'That invoice was already made.');
            header('Location: recurring.php?id=' . $postId);
            exit;
        }
        if ($action === 'save') {
            $in = $_POST;
            $in['is_active'] = $s('is_active') === '1' ? '1' : '0';
            $lines = rec_posted_lines();
            [$errors, $r] = recurring_validate($in, $lines, billing_setting('default_currency'), billing_setting('invoice_due_days'));
            // A past date would make the daily run catch up one invoice a day (each sent, if set to send automatically).
            $prevNext = $postId > 0 ? (string)(recurring_find($pdo, $postId)['next_date'] ?? '') : '';
            if (!$errors && $r['next_date'] < $today && $r['next_date'] !== $prevNext) {
                $errors[] = 'The next invoice date cannot be in the past. Use today or later; to bill now, save it for today, or use "Make the next invoice now".';
            }
            if (!$errors) {
                $r['id'] = $postId;
                $newId = recurring_save($pdo, $r, $adminId);
                flash_set('ok', $postId > 0 ? 'Schedule saved.' : 'Recurring invoice added. The first invoice is made on ' . billing_date($r['next_date']) . '.');
                header('Location: recurring.php?id=' . $newId);
                exit;
            }
            $form = $_POST;
            $formLines = $lines;
        } elseif ($action === 'run_now' && $postId > 0) {
            $r = recurring_find($pdo, $postId);
            // "Make it now": the next invoice is made today as a draft, whatever its date, and the schedule moves on.
            $res = $r ? recurring_run_one($pdo, $postId, $adminId, $today, true) : ['ok' => false, 'error' => 'That schedule no longer exists.'];
            if (!empty($res['ok'])) {
                flash_set('ok', 'Draft invoice made. Check it, then send it. Next one: ' . billing_date((string)$res['next_date']) . '.');
                header('Location: document.php?id=' . (int)$res['document_id']);
                exit;
            }
            flash_set('error', (string)$res['error']);
            header('Location: recurring.php?id=' . $postId);
            exit;
        } else {
            http_response_code(400);
            echo 'Invalid request.';
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $ex->getMessage();
        $form = $_POST;
        $formLines = rec_posted_lines();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('recurring.php: ' . $ex->getMessage());
        $errors[] = 'Could not save. Nothing was changed.';
        $form = $_POST;
        $formLines = rec_posted_lines();
    }
    $id = $postId;
    $isNew = $postId === 0;
}

$rec = $id > 0 ? recurring_find($pdo, $id) : null;
if ($id > 0 && !$rec) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Schedule not found. <a href="recurring.php">Back to recurring invoices</a></p>';
    view_foot();
    exit;
}
$modes = recurring_send_modes();

// List
if (!$rec && !$isNew) {
    $all = recurring_list($pdo);
    view_head('Recurring invoices');
    echo '<p class="muted"><a href="invoices.php">Billing</a></p><h1>Recurring invoices</h1>';
    view_flash();
    echo '<p class="bill-actions"><a class="button" href="recurring.php?new=1">New recurring invoice</a></p>'
        . '<p class="muted">Each schedule makes an invoice on its date (the daily run), then sends it, waits in the Outbox, or stays a draft, as you choose.</p>';
    if (!$all) {
        echo '<p class="muted">No recurring invoices yet.</p>';
    } else {
        echo '<div class="scroll"><table class="list"><thead><tr>' . view_th('Client', 'Who is billed.') . view_th('Title', 'What is billed.')
            . view_th('Every', 'How often.') . view_th('Next invoice', 'Date of the next invoice.') . view_th('Amount', 'Total of the lines, before tax.')
            . view_th('Then', 'What happens to the invoice.') . view_th('Status', 'Active or paused.') . '</tr></thead><tbody>';
        foreach ($all as $r) {
            $tot = billing_calc_totals(recurring_items($r), 0, 0);
            $due = recurring_is_due($r, $today);
            echo '<tr><td data-label="Client" class="col-name"><a href="client.php?id=' . (int)$r['client_id'] . '">' . e($r['client_name']) . '</a></td>'
                . '<td data-label="Title"><a href="recurring.php?id=' . (int)$r['id'] . '">' . e($r['title']) . '</a></td>'
                . '<td data-label="Every" class="nw">' . (int)$r['interval_months'] . ' month' . ((int)$r['interval_months'] === 1 ? '' : 's') . '</td>'
                . '<td data-label="Next invoice" class="nw">' . e(billing_date($r['next_date'])) . ($due ? ' <span class="pill pill-grace">Due</span>' : '') . '</td>'
                . '<td data-label="Amount" class="nw num">' . e(billing_money($tot['subtotal'], $r['currency'])) . '</td>'
                . '<td data-label="Then">' . e($modes[$r['send_mode']] ?? $r['send_mode']) . '</td>'
                . '<td data-label="Status">' . ((int)$r['is_active'] === 1 ? '<span class="pill pill-active">Active</span>' : '<span class="pill pill-suspended">Paused</span>') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    view_foot();
    exit;
}

// Editor
if (!$rec) {
    $pc = $preClient > 0 ? client_find($pdo, $preClient) : null;
    $rec = ['id' => 0, 'client_id' => $pc ? (int)$pc['id'] : 0, 'title' => '', 'interval_months' => 1, 'next_date' => $today, 'end_date' => null,
        'currency' => $pc ? $pc['currency'] : billing_setting('default_currency'), 'due_days' => billing_setting('invoice_due_days'),
        'extend_months' => 0, 'items_json' => '[]', 'notes' => '', 'send_mode' => 'approve', 'is_active' => 1, 'last_document_id' => null, 'last_run_at' => null];
}
$rid = (int)$rec['id'];
$fv = static function (string $k) use ($form, $rec): string {
    if (is_array($form) && array_key_exists($k, $form) && is_string($form[$k])) {
        return $form[$k];
    }
    return ($rec[$k] ?? null) === null ? '' : (string)$rec[$k];
};
$cur = $fv('currency');
$lines = $formLines;
if ($lines === null) {
    $lines = [];
    foreach (recurring_items($rec) as $it) {
        $lines[] = ['qty' => rtrim(rtrim(number_format((float)($it['qty'] ?? 1), 2, '.', ''), '0'), '.'), 'item' => (string)($it['item'] ?? ''),
            'description' => (string)($it['description'] ?? ''), 'unit_price' => number_format((float)($it['unit_price'] ?? 0), 2, '.', ''),
            'cost_price' => isset($it['cost_price']) && $it['cost_price'] !== null ? number_format((float)$it['cost_price'], 2, '.', '') : '',
            'catalog_item_id' => !empty($it['catalog_item_id']) ? (string)(int)$it['catalog_item_id'] : ''];
    }
}
if (!$lines) {
    $lines[] = ['qty' => '1', 'item' => '', 'description' => '', 'unit_price' => '', 'cost_price' => '', 'catalog_item_id' => ''];
}
$lineRow = static function (array $l) use ($cur): string {
    $l += ['cost_price' => '', 'catalog_item_id' => ''];
    $total = (preg_match('/^\d+(\.\d+)?$/', (string)$l['qty']) && preg_match('/^-?\d+(\.\d+)?$/', (string)$l['unit_price']))
        ? billing_money(billing_line_total((float)$l['qty'], (float)$l['unit_price']), $cur) : '';
    return '<tr class="line"><td data-label="Qty"><input type="text" name="line_qty[]" inputmode="decimal" class="in-qty" required value="' . e($l['qty']) . '"></td>'
        . '<td data-label="Item"><input type="text" name="line_item[]" maxlength="190" class="in-item" value="' . e($l['item']) . '">'
        . '<input type="hidden" name="line_catalog_id[]" class="in-cat" value="' . e($l['catalog_item_id']) . '"></td>'
        . '<td data-label="Description"><textarea name="line_description[]" rows="2" maxlength="2000" class="in-desc">' . e($l['description']) . '</textarea></td>'
        . '<td data-label="Unit price"><input type="text" name="line_unit_price[]" inputmode="decimal" class="in-price" value="' . e($l['unit_price']) . '"></td>'
        . '<td data-label="Cost (yours)"><input type="text" name="line_cost_price[]" inputmode="decimal" class="in-cost" value="' . e($l['cost_price']) . '"></td>'
        . '<td data-label="Total" class="num nw line-total">' . e($total) . '</td>'
        . '<td data-label=""><button type="button" class="secondary js-remove-line" title="Remove this line">Remove</button></td></tr>';
};

billing_seed_catalog($pdo, $adminId);
$catalog = billing_catalog($pdo);
$clients = client_options($pdo, (int)$rec['client_id']);
$tenantRows = [];
foreach ($pdo->query('SELECT id, name, plan, business_type, modules, domain, monthly_fee, currency FROM cp_tenants') as $tr) {
    $tenantRows[(int)$tr['id']] = $tr;
}
$client = (int)$rec['client_id'] > 0 ? client_find($pdo, (int)$rec['client_id']) : null;

view_head($rid ? 'Recurring: ' . $rec['title'] : 'New recurring invoice');
echo '<script src="assets/billing.js" defer></script>';
echo '<p class="muted"><a href="recurring.php">Recurring invoices</a>' . ($client ? ' &rsaquo; <a href="client.php?id=' . (int)$client['id'] . '">' . e($client['name']) . '</a>' : '') . '</p>';
echo '<h1>' . ($rid ? e($rec['title']) . ((int)$rec['is_active'] === 1 ? ' <span class="pill pill-active">Active</span>' : ' <span class="pill pill-suspended">Paused</span>') : 'New recurring invoice') . '</h1>';
view_flash();
foreach ($errors as $m) {
    echo '<p class="flash flash-error">' . e($m) . '</p>';
}

if ($rid) {
    echo '<section class="card"><div class="bill-stats">'
        . '<p><span class="muted">Next invoice</span><strong>' . e(billing_date($rec['next_date'])) . '</strong></p>'
        . '<p><span class="muted">Every</span><strong>' . (int)$rec['interval_months'] . ' month' . ((int)$rec['interval_months'] === 1 ? '' : 's') . '</strong></p>'
        . '<p><span class="muted">Last made</span><strong>' . ($rec['last_document_id'] ? '<a href="document.php?id=' . (int)$rec['last_document_id'] . '">' . e(billing_date(substr((string)$rec['last_run_at'], 0, 10))) . '</a>' : view_empty()) . '</strong></p>'
        . '</div>';
    if ((int)$rec['is_active'] === 1) {
        echo '<form method="post" action="recurring.php?id=' . $rid . '" class="inline js-once" data-confirm="Make the next invoice now, as a draft? The schedule then moves on to the following date.">'
            . csrf_field() . '<input type="hidden" name="action" value="run_now"><input type="hidden" name="id" value="' . $rid . '">'
            . '<input type="hidden" name="once" value="' . e(billing_once_issue('rec_run_' . $rid)) . '">'
            . '<button type="submit" class="secondary">Make the next invoice now (draft)</button></form>';
    }
    echo '</section>';
}

$selC = $fv('client_id');
echo '<form method="post" action="recurring.php' . ($rid ? '?id=' . $rid : '?new=1') . '" class="card bill-editor js-once" id="doc-form" data-currency="' . e($cur) . '">'
    . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . $rid . '">'
    . ($rid ? '' : '<input type="hidden" name="once" value="' . e(billing_once_issue('rec_new')) . '">')
    . '<div class="bill-grid"><label>Client<select name="client_id" id="client-pick" required><option value="">Choose…</option>';
foreach ($clients as $c) {
    $t = $c['tenant_id'] !== null ? ($tenantRows[(int)$c['tenant_id']] ?? null) : null;
    $sub = $t ? billing_subscription_lines($t, 1)[0] : null;
    echo '<option value="' . (int)$c['id'] . '"' . ((string)$c['id'] === $selC ? ' selected' : '') . ' data-currency="' . e($c['currency']) . '" data-hosted="' . ($t ? '1' : '0')
        . '" data-domain="' . e((string)($t['domain'] ?? '')) . '"'
        . ($sub ? ' data-sub-item="' . e($sub['item']) . '" data-sub-desc="' . e($sub['description']) . '" data-sub-price="' . e(number_format($sub['unit_price'], 2, '.', '')) . '"' : '')
        . '>' . e($c['name']) . ($t ? ' · hosted' : '') . '</option>';
}
echo '</select></label>'
    . '<label>Title (shown in the email: "your regular invoice for …")<input type="text" name="title" required maxlength="190" value="' . e($fv('title')) . '"></label>'
    . '<label>Every (months)<input type="number" name="interval_months" min="1" max="36" required value="' . e($fv('interval_months')) . '"><small class="muted">1 = monthly, 3 = quarterly, 12 = yearly.</small></label>'
    . '<label>Next invoice date<input type="date" name="next_date" required value="' . e($fv('next_date')) . '"><small class="muted">A date like the 31st moves to the last day in shorter months.</small></label>'
    . '<label>End date (optional)<input type="date" name="end_date" value="' . e($fv('end_date')) . '"></label>'
    . '<label>Currency<input type="text" name="currency" id="currency" required maxlength="3" value="' . e($cur) . '"></label>'
    . '<label>Invoice due after (days)<input type="number" name="due_days" min="0" max="365" value="' . e($fv('due_days')) . '"></label>'
    . '<label>When paid in full, extend the licence by (months)<input type="number" name="extend_months" id="extend_months" min="0" max="60" value="' . e($fv('extend_months') === '' ? '0' : $fv('extend_months')) . '"><small class="muted">Hosted businesses only.</small></label>'
    . '<label>Then<select name="send_mode">';
foreach ($modes as $k => $l) {
    echo '<option value="' . e($k) . '"' . ($fv('send_mode') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
}
$activeChecked = is_array($form) ? (($form['is_active'] ?? '') === '1') : ((int)$rec['is_active'] === 1);
echo '</select></label></div>'
    . '<h2>Lines</h2><div class="scroll"><table class="bill-lines"><thead><tr>'
    . view_th('Qty', 'Quantity.') . view_th('Item', 'Printed in the Item column.') . view_th('Description', 'Printed beside the item.')
    . view_th('Unit price', 'Price of one, before tax.') . view_th('Cost', 'What one costs you. Never printed.', 'Yours, never printed')
    . view_th('Total', 'Quantity times unit price.') . view_th('', 'Remove a line.', 'Remove') . '</tr></thead><tbody id="lines">';
foreach ($lines as $l) {
    echo $lineRow($l);
}
echo '</tbody></table></div><template id="line-template">' . $lineRow(['qty' => '1', 'item' => '', 'description' => '', 'unit_price' => '']) . '</template>'
    . '<div class="bill-add"><button type="button" class="secondary" id="add-line">Add line</button>';
if ($catalog) {
    $cats = billing_catalog_categories();
    echo '<label class="inline-label">Saved item<select id="catalog-pick"><option value="">Choose…</option>';
    foreach (billing_catalog_grouped($catalog) as $cat => $group) {
        echo '<optgroup label="' . e($cats[$cat] ?? $cat) . '">';
        foreach ($group as $c) {
            echo '<option value="' . (int)$c['id'] . '" data-item="' . e($c['item']) . '" data-desc="' . e((string)$c['description'])
                . '" data-price="' . e(number_format((float)$c['unit_price'], 2, '.', '')) . '" data-cost="' . e($c['cost_price'] !== null ? number_format((float)$c['cost_price'], 2, '.', '') : '')
                . '">' . e($c['item']) . ' (' . e(billing_money((float)$c['unit_price'], $c['currency'])) . ')</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></label><button type="button" class="secondary" id="add-catalog">Add saved item</button>';
}
echo '<label class="inline-label">Months<input type="number" id="sub-months" min="1" max="60" value="1"></label>'
    . '<button type="button" class="secondary" id="add-sub">Add licence subscription</button>'
    . '<span class="muted" id="sub-msg" hidden>Choose a hosted business as the client first.</span></div>'
    . '<div class="bill-totals"><p class="grand"><span>Total before tax</span><strong id="t-sub">' . e(billing_money(0, $cur)) . '</strong></p>'
    . '<p class="muted"><span>Profit (yours)</span><strong id="t-profit">' . view_empty() . '</strong></p></div>'
    . '<label>Notes (printed on each invoice)<textarea name="notes" rows="3" maxlength="2000">' . e($fv('notes')) . '</textarea></label>'
    . '<label class="check"><input type="checkbox" name="is_active" value="1"' . ($activeChecked ? ' checked' : '') . '> Active (untick to pause)</label>'
    . '<button type="submit">' . ($rid ? 'Save schedule' : 'Add recurring invoice') . '</button></form>';
view_foot();
