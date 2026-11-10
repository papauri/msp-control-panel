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
    if ($tenantId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // After a change, nudge that business to check in now. The redirect is sent first so the owner never waits.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $postId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        if ($action === 'save') {
            $trialIn = $_POST;
            $trialDays = 0;
            if ($postId === 0) {
                // Part 3: a new business may start on a free trial; the trial then sets paid until and the step-down days.
                [$trialDays, $trialErr] = trial_days_from_form((string)($_POST['trial_preset'] ?? ''), (string)($_POST['trial_custom'] ?? ''));
                if ($trialErr !== '') {
                    $errors[] = $trialErr;
                }
                if ($trialDays > 0 && !tenant_valid_date(trim((string)($_POST['paid_until'] ?? '')))) {
                    $trialIn['paid_until'] = $today;
                }
            }
            [$valErrors, $clean] = tenant_validate($trialIn, $postId === 0);
            $errors = array_merge($errors, $valErrors);
            $form = $clean;
            $form['monthly_fee'] = $_POST['monthly_fee'] ?? '';
            if (!$errors) {
                $nul = static fn(string $v): ?string => $v === '' ? null : $v;
                $pdo->beginTransaction();
                if ($postId === 0) {
                    $key = tenant_new_api_key();
                    $st = $pdo->prepare(
                        'INSERT INTO cp_tenants (slug, name, business_type, modules, domain, plan, monthly_fee, currency, paid_until, grace_days, readonly_days, api_key_hash, api_key_hint, notes,
                            contact_name, billing_email, cc_emails, contact_phone, billing_address)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $clean['slug'], $clean['name'], $clean['business_type'], $clean['modules_json'], $clean['domain'], $clean['plan'], $clean['monthly_fee'],
                        $clean['currency'], $clean['paid_until'], $clean['grace_days'], $clean['readonly_days'],
                        password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]), tenant_key_hint($key), $clean['notes'],
                        $nul($clean['contact_name']), $nul($clean['billing_email']), $nul($clean['cc_emails']), $nul($clean['contact_phone']), $nul($clean['billing_address']),
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $adminId, 'tenant_create', $newId, null, tenant_find($pdo, $newId));
                    $newClient = client_sync_from_tenant($pdo, $newId, $adminId); // its client row (billing belongs to the client)
                    if ($newClient > 0) {
                        billing_event('client_created', ['client_id' => $newClient]);
                    }
                    if ($trialDays > 0) {
                        $trialErr = trial_start($pdo, $newId, $trialDays, $adminId, $today);
                        if ($trialErr !== '') {
                            throw new InvalidArgumentException($trialErr);
                        }
                    }
                    $pdo->commit();
                    billing_events_flush($pdo);
                    $_SESSION['new_key'] = [$newId, $key, time()]; // carried across the redirect only (new_key_purge)
                    flash_set('ok', 'Business added' . ($trialDays > 0 ? ' on a ' . $trialDays . '-day free trial' : '') . '. Copy the licence key (API key) below now: it is shown only once.');
                    back_to($newId);
                }
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $st = $pdo->prepare(
                    'UPDATE cp_tenants SET name=?, business_type=?, modules=?, domain=?, plan=?, monthly_fee=?, currency=?, paid_until=?,
                        grace_days=?, readonly_days=?, notes=?, contact_name=?, billing_email=?, cc_emails=?, contact_phone=?, billing_address=? WHERE id=?'
                );
                $st->execute([
                    $clean['name'], $clean['business_type'], $clean['modules_json'], $clean['domain'], $clean['plan'], $clean['monthly_fee'], $clean['currency'],
                    $clean['paid_until'], $clean['grace_days'], $clean['readonly_days'], $clean['notes'],
                    $nul($clean['contact_name']), $nul($clean['billing_email']), $nul($clean['cc_emails']), $nul($clean['contact_phone']), $nul($clean['billing_address']), $postId,
                ]);
                audit_log($pdo, $adminId, 'tenant_update', $postId, $before, tenant_find($pdo, $postId));
                client_sync_from_tenant($pdo, $postId, $adminId); // keep the client's name and billing contact in step
                $pdo->commit();
                flash_set('ok', 'Saved.');
                back_to($postId);
            }
        } elseif (($action === 'payment_void' || $action === 'payment_refund') && $postId > 0) {
            // A licence payment recorded on this page (not against an invoice: those are voided / refunded on the invoice).
            $str = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : '';
            $pay = ctype_digit($str('payment_id')) ? billing_payment($pdo, (int)$str('payment_id')) : null;
            if (!$pay || (int)$pay['tenant_id'] !== $postId || !empty($pay['document_id'])) {
                flash_set('error', 'That licence payment was not found on this business. A payment against an invoice is voided or refunded on the invoice.');
                back_to($postId);
            }
            if (!billing_once_consume('pay_fix_' . (int)$pay['id'], $str('once'))) {
                flash_set('info', 'That was already done (the form was sent twice).');
                back_to($postId);
            }
            if ($action === 'payment_void') {
                $r = billing_void_payment($pdo, (int)$pay['id'], $str('reason'), $adminId);
                $done = 'Payment voided. ';
            } elseif (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $str('amount'))) {
                $r = ['ok' => false, 'error' => 'Enter the amount given back as a number, for example 50000.'];
            } else {
                $r = billing_refund_payment($pdo, (int)$pay['id'], (float)$str('amount'), $str('method'), $str('reference'), $str('take_back') === '1', false, $adminId);
                $done = 'Refund recorded. ';
            }
            flash_set($r['ok'] ? 'ok' : 'error', $r['ok'] ? $done . ($r['words'] !== '' ? $r['words'] : 'Paid until was not changed.') : $r['error']);
            if ($r['ok']) {
                tenant_nudge($pdo, $postId);
            }
            back_to($postId);
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
            // Owner 2026-11-10: 0 is a free month, and only on a free trial. It lengthens the trial (still a trial, its
            // emails still go); no payment is recorded (a 0 payment would end the trial and send a receipt for nothing).
            $zero = !$errors && round((float)$amount, 2) <= BILLING_TOLERANCE;
            $tNow = $zero ? tenant_find($pdo, $postId) : null;
            if ($zero && (!$tNow || !trial_is_on($tNow))) {
                $errors[] = 'Enter the amount received. 0 (a free month) is only for a business on a free trial; this one pays.';
            }
            // One-time form token: a double click (or a resubmit) must not record the payment twice.
            $once = isset($_POST['once']) && is_string($_POST['once']) ? $_POST['once'] : '';
            if (!$errors && !billing_once_consume('tenant_pay_' . $postId, $once)) {
                flash_set('info', 'This payment was already recorded (the form was sent twice). Check Recent payments below.');
                back_to($postId);
            }
            if (!$errors && $zero) {
                $until = status_add_months(max((string)$tNow['trial_until'], $today), $months);
                $r = trial_change($pdo, $postId, 'date', $until, $adminId, $today, 'Free month' . ($months === 1 ? '' : 's') . ' (' . $months . '), ref ' . $ref);
                if ($r['error'] !== '') {
                    flash_set('error', 'No free time was given: ' . $r['error']);
                } else {
                    flash_set('ok', $months . ' free month' . ($months === 1 ? '' : 's') . ' added to the free trial: its last day is now '
                        . billing_date($r['until']) . '. No payment was recorded; the trial carries on.');
                }
                back_to($postId);
            }
            // An open invoice that extends the licence when paid would extend it a second time.
            $extInv = !$errors ? billing_open_extending_invoice($pdo, $postId) : null;
            if ($extInv) {
                flash_set('error', 'Invoice ' . $extInv['number'] . ' extends this licence by ' . (int)$extInv['extend_months']
                    . ' month(s) when it is paid. Record the payment on that invoice instead (Billing, invoice ' . $extInv['number'] . '), or void it first.');
                header('Location: document.php?id=' . (int)$extInv['id']);
                exit;
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $before = tenant_find($pdo, $postId, true);
                if (!$before) {
                    $pdo->rollBack();
                    back_to(0);
                }
                $payClient = client_for_tenant($pdo, $postId);
                $base = max($today, $before['paid_until']);
                $newDate = status_add_months($base, $months);
                $pdo->prepare('UPDATE cp_tenants SET paid_until = ? WHERE id = ?')->execute([$newDate, $postId]);
                if (trial_is_on($before)) {
                    // paying during a free trial ends the trial: a paying customer gets the normal step-down days, and no trial emails
                    $pdo->prepare('UPDATE cp_tenants SET trial_converted_at = ?, grace_days = ?, readonly_days = ? WHERE id = ?')
                        ->execute([date('Y-m-d H:i:s'), trial_setting_int('default_grace_days', 14), trial_setting_int('default_readonly_days', 14), $postId]);
                }
                $pdo->prepare(
                    'INSERT INTO cp_payments (tenant_id, client_id, admin_id, amount, currency, months, reference, paid_until_before, paid_until_after, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$postId, $payClient ? (int)$payClient['id'] : null, $adminId, $amount, $before['currency'], $months, $ref,
                    $before['paid_until'], $newDate, date('Y-m-d H:i:s')]);
                $after = tenant_find($pdo, $postId);
                $after['_payment'] = ['amount' => $amount, 'currency' => $before['currency'], 'months' => $months, 'reference' => $ref];
                $licPaymentId = (int)$pdo->lastInsertId();
                audit_log($pdo, $adminId, 'payment_recorded', $postId, $before, $after);
                // After the commit: automatic receipt / thank-you for a licence payment too (automation_event, guarded).
                billing_event('payment_recorded', ['payment_id' => $licPaymentId, 'document_id' => null, 'paid_in_full' => false]);
                $pdo->commit();
                billing_events_flush($pdo);
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
        } elseif ($action === 'go_live_send' && $postId > 0) {
            if (!billing_once_consume('go_live_' . $postId, (string)($_POST['once'] ?? ''))) {
                flash_set('info', 'This introduction email was already sent (the form was sent twice).');
                back_to($postId);
            }
            if (!billing_has_mail()) {
                flash_set('error', 'Sending email is not available.');
                back_to($postId);
            }
            $note = isset($_POST['message']) && is_string($_POST['message']) ? trim($_POST['message']) : '';
            $sent = billing_send($pdo, 'go_live', $postId, ['message' => mb_substr($note, 0, 2000)], $adminId);
            flash_set(!empty($sent['ok']) ? 'ok' : 'error', !empty($sent['ok'])
                ? 'Introduction email sent to ' . implode(', ', (array)$sent['to']) . (!empty($sent['test']) ? ' (test mode: it went only to the test address).' : '.')
                : 'The introduction email was NOT sent: ' . (string)$sent['error']);
            back_to($postId);
        } elseif ($action === 'trial_start' && $postId > 0) {
            [$days, $err] = trial_days_from_form((string)($_POST['trial_preset'] ?? ''), (string)($_POST['trial_custom'] ?? ''));
            $err = $err !== '' ? $err : ($days === 0 ? 'Choose the trial length.' : trial_start($pdo, $postId, $days, $adminId, $today));
            flash_set($err === '' ? 'ok' : 'error', $err === '' ? 'Free trial started: ' . $days . ' days. The business picks it up at its next check-in.' : $err);
            back_to($postId);
        } elseif ($action === 'trial_extend' && $postId > 0) {
            flash_set('info', 'The free trial form has changed. Nothing was changed: please choose again below.');
            back_to($postId);
        } elseif ($action === 'trial_change' && $postId > 0) {
            // Add days, take days off, set the whole length, or set the last day (owner 2026-11-10: it only ever added)
            if (!billing_once_consume('trial_change_' . $postId, (string)($_POST['once'] ?? ''))) {
                flash_set('info', 'This change was already made (the form was sent twice).');
                back_to($postId);
            }
            $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : '';
            $value = $mode === 'date' ? (string)($_POST['until'] ?? '') : (string)($_POST['days'] ?? '');
            $r = trial_change($pdo, $postId, $mode, is_string($value) ? $value : '', $adminId, $today);
            if ($r['error'] === '') {
                $left = billing_days_between($today, $r['until']);
                flash_set('ok', 'Free trial changed: the last day is now ' . billing_date($r['until']) . ' ('
                    . ($left > 0 ? $left . ' day' . ($left === 1 ? '' : 's') . ' after today' : ($left === 0 ? 'today' : 'ended: the step-down starts today')) . ').');
            } else {
                flash_set('error', $r['error']);
            }
            back_to($postId);
        } elseif ($action === 'trial_end' && $postId > 0) {
            $err = trial_end_now($pdo, $postId, $adminId, $today);
            flash_set($err === '' ? 'ok' : 'error', $err === '' ? 'Free trial ended. The step-down (view-only, then paused) starts today.' : $err);
            back_to($postId);
        } elseif ($action === 'trial_convert' && $postId > 0) {
            if (!billing_once_consume('trial_convert_' . $postId, (string)($_POST['once'] ?? ''))) {
                flash_set('info', 'This trial was already converted (the form was sent twice). See Billing below.');
                back_to($postId);
            }
            $planId = isset($_POST['plan_id']) && ctype_digit((string)$_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
            $mode = isset($_POST['send_mode']) && is_string($_POST['send_mode']) ? $_POST['send_mode'] : 'approve';
            $r = trial_convert($pdo, $postId, ['plan_id' => $planId, 'yearly' => ($_POST['yearly'] ?? '') === '1', 'send_mode' => $mode], $adminId, $today);
            if (!$r['ok']) {
                flash_set('error', $r['error']);
                back_to($postId);
            }
            $msg = 'Converted to a paid plan: recurring schedule created and the first invoice made.';
            if (($_POST['first_invoice'] ?? '') === 'send') {
                if (billing_has_mail()) {
                    $sent = billing_send($pdo, 'invoice', (int)$r['document_id'], [], $adminId);
                    $msg .= !empty($sent['ok']) ? ' The invoice was emailed to ' . implode(', ', (array)$sent['to']) . '.' : ' The invoice was NOT sent: ' . (string)$sent['error'] . ' It is saved as a draft.';
                } else {
                    $msg .= ' Sending email is not available, so the invoice is saved as a draft.';
                }
            } else {
                $msg .= ' The invoice is a draft: open it to check and send it.';
            }
            flash_set('ok', $msg . ' Paid until moves when the invoice is paid.');
            back_to($postId);
        } elseif ($action === 'regen_key' && $postId > 0) {
            $pdo->beginTransaction();
            $before = tenant_find($pdo, $postId, true);
            if (!$before) {
                $pdo->rollBack();
                back_to(0);
            }
            $key = tenant_new_api_key();
            $pdo->prepare('UPDATE cp_tenants SET api_key_hash = ?, api_key_hint = ? WHERE id = ?')
                ->execute([password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]), tenant_key_hint($key), $postId]);
            audit_log($pdo, $adminId, 'api_key_regenerated', $postId, ['key_set' => $before['api_key_hash'] !== null], ['key_set' => true]);
            $pdo->commit();
            $_SESSION['new_key'] = [$postId, $key, time()]; // carried across the redirect only (new_key_purge)
            flash_set('ok', 'New licence key (API key) created. The old key no longer works. Copy the new key now: it is shown only once.');
            back_to($postId);
        } else {
            http_response_code(400);
            echo 'Invalid request.';
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        billing_events_clear();
        $errors[] = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        billing_events_clear();
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

// One-time API key display: web_boot() already dropped it unless this is the first GET of this business within 2 minutes.
$shownKey = null;
if ($tenant && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['new_key']) && (int)$_SESSION['new_key'][0] === (int)$tenant['id']) {
    $shownKey = (string)$_SESSION['new_key'][1];
}
unset($_SESSION['new_key']); // the session is saved without it at the end of this page

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
    $trialWords = trial_words($tenant, $today);
    echo '<section class="card"><h2>Status now</h2><p>' . view_pill($computed, !empty($tenant['status_override']))
        . ($trialWords !== '' ? ' ' . view_trial_tag($trialWords) : '') . '</p>';
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
        echo '<section class="card key"><h2>Licence key (API key), shown once</h2>'
            . '<p>Copy this now. Put it in the business\'s settings file (<code>LICENCE_KEY=</code>), or the business\'s admin can paste it on their <strong>Licence</strong> page (Change licence key). It cannot be shown again; if lost, generate a new one.</p>'
            . '<p><code class="keybox">' . e($shownKey) . '</code></p>'
            . '<p class="muted">Short name to use with it: <code>' . e($tenant['slug']) . '</code></p></section>';
    }

    // Free trial (Part 3): start / extend / end / convert.
    $tid0 = (int)$tenant['id'];
    $presetSel = static function (string $name = 'trial_preset', bool $none = false): string {
        $h = '<label>Length<select name="' . $name . '">' . ($none ? '<option value="">No free trial</option>' : '');
        foreach (TRIAL_PRESETS as $p) {
            $h .= '<option value="' . $p . '"' . ($p === 14 ? ' selected' : '') . '>' . $p . ' days</option>';
        }
        return $h . '<option value="custom">Custom (below)</option></select></label>'
            . '<label>Custom days (1-' . TRIAL_MAX_DAYS . ')<input type="number" name="trial_custom" min="1" max="' . TRIAL_MAX_DAYS . '"></label>';
    };
    $tHidden = csrf_field() . '<input type="hidden" name="id" value="' . $tid0 . '">';
    echo '<section class="card" id="trial"><h2>Free trial</h2>';
    if (!empty($tenant['trial_converted_at'])) {
        echo '<p>Converted to a paid plan on ' . e(billing_date(substr((string)$tenant['trial_converted_at'], 0, 10))) . ' (trial '
            . e(billing_date((string)$tenant['trial_started'])) . ' to ' . e(billing_date((string)$tenant['trial_until'])) . ').</p>';
    } elseif (trial_is_on($tenant)) {
        $sd = trial_step_dates((string)$tenant['trial_until'], (int)$tenant['grace_days'], (int)$tenant['readonly_days']);
        $tLen = !empty($tenant['trial_started']) ? billing_days_between((string)$tenant['trial_started'], (string)$tenant['trial_until']) : 0;
        $tLeft = (int)trial_days_left($tenant, $today);
        $trialLen = ($tLen > 0 ? $tLen . ' days in total, ' : '') . ($tLeft > 0 ? $tLeft . ' day' . ($tLeft === 1 ? '' : 's') . ' after today' : ($tLeft === 0 ? 'today is the last day' : 'ended'));
        $modeOpts = '';
        foreach (TRIAL_CHANGE_MODES as $mk => $ml) {
            $modeOpts .= '<option value="' . e($mk) . '">' . e($ml) . '</option>';
        }
        echo '<p>' . view_trial_tag($trialWords) . ' Started ' . e(billing_date((string)$tenant['trial_started'])) . ', last day '
            . e(billing_date((string)$tenant['trial_until'])) . '. After it: view-only from ' . e(billing_date($sd['read_only_from']))
            . ', paused from ' . e(billing_date($sd['locked_from'])) . '. Data is never deleted.</p>'
            . '<div class="bill-grid">'
            . '<form method="post" action="tenant.php?id=' . $tid0 . '#trial" class="js-once">' . $tHidden . '<input type="hidden" name="action" value="trial_change">'
            . '<input type="hidden" name="once" value="' . e(billing_once_issue('trial_change_' . $tid0)) . '">'
            . '<h3>Lengthen or shorten</h3><p class="muted">Now ' . e($trialLen) . '. Adding counts from the last day (or from today if it has ended).</p>'
            . '<label>Change<select name="mode">' . $modeOpts . '</select></label>'
            . '<label>Days<input type="number" name="days" min="1" max="' . TRIAL_MAX_AHEAD_DAYS . '" required></label>'
            . '<button type="submit" class="secondary">Change trial</button></form>'
            . '<form method="post" action="tenant.php?id=' . $tid0 . '#trial" class="js-once">' . $tHidden . '<input type="hidden" name="action" value="trial_change">'
            . '<input type="hidden" name="once" value="' . e(billing_once_issue('trial_change_' . $tid0)) . '"><input type="hidden" name="mode" value="date">'
            . '<h3>Set the last day</h3><p class="muted">Pick the exact last day of the trial (earlier or later).</p>'
            . '<label>Last day<input type="date" name="until" required value="' . e(substr((string)$tenant['trial_until'], 0, 10)) . '" min="' . e(status_add_days($today, -1))
            . '" max="' . e(status_add_days($today, TRIAL_MAX_AHEAD_DAYS)) . '"></label>'
            . '<button type="submit" class="secondary">Set last day</button></form>';
        if ((int)trial_days_left($tenant, $today) >= 0) {
            echo '<form method="post" action="tenant.php?id=' . $tid0 . '#trial">' . $tHidden . '<input type="hidden" name="action" value="trial_end">'
                . '<h3>End now</h3><p class="muted">Yesterday becomes the last day: view-only starts today, then the staff area pauses.</p>'
                . '<button type="submit" class="danger">End the trial now</button></form>';
        }
        echo '</div>';
        billing_seed_plans($pdo, $adminId); // first use only (locked, audited, once): the usual plans at price 0
        $plans = plan_list($pdo);
        $pct = plan_discount_pct(billing_setting('annual_discount_pct'));
        echo '<form method="post" action="tenant.php?id=' . $tid0 . '#trial" class="js-once spaced">' . $tHidden . '<input type="hidden" name="action" value="trial_convert">'
            . '<input type="hidden" name="once" value="' . e(billing_once_issue('trial_convert_' . $tid0)) . '">'
            . '<h3>Convert to paid</h3><p class="muted">Creates a recurring invoice starting today and its first invoice. The normal grace and read-only days '
            . '(' . e(billing_setting('default_grace_days')) . ' / ' . e(billing_setting('default_readonly_days')) . ') come back. Paid until moves when the first invoice is paid; '
            . 'until then the trial\'s last day stays.</p>'
            . '<label>Plan<select name="plan_id" required><option value="0">This business\'s monthly fee: ' . e(billing_money((float)$tenant['monthly_fee'], (string)$tenant['currency'])) . ' a month</option>';
        foreach ($plans as $pl) {
            echo '<option value="' . (int)$pl['id'] . '">' . e(plan_label($pl)) . '</option>';
        }
        echo '</select></label>'
            . '<label class="check"><input type="checkbox" name="yearly" value="1"> Bill a monthly plan once a year (12 months' . ($pct > 0 ? ', ' . e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) . '% annual discount' : '') . ')</label>'
            . '<label>First invoice<select name="first_invoice"><option value="draft">Save as a draft (I will check and send it)</option><option value="send">Email it now</option></select></label>'
            . '<label>Later invoices<select name="send_mode">';
        foreach (recurring_send_modes() as $mk => $ml) {
            echo '<option value="' . e($mk) . '"' . ($mk === 'approve' ? ' selected' : '') . '>' . e($ml) . '</option>';
        }
        echo '</select></label><button type="submit">Convert to paid</button></form>';
    } elseif (trial_start_error($tenant, trial_has_paid($pdo, $tid0)) === "") { // never for a paid-up business (e.g. paid until 2099)
        echo '<p class="muted">This business has never paid, so it can start a free trial. The trial sets paid until to its last day; after it the system is '
            . 'view-only for ' . e(billing_setting('trial_readonly_days')) . ' day(s)' . ((int)billing_setting('trial_grace_days') > 0 ? ' after ' . e(billing_setting('trial_grace_days')) . ' grace day(s)' : '')
            . ', then the staff area pauses (data is never deleted).</p>'
            . '<form method="post" action="tenant.php?id=' . $tid0 . '#trial">' . $tHidden . '<input type="hidden" name="action" value="trial_start">'
            . $presetSel() . '<button type="submit" class="secondary">Start free trial</button></form>';
    } else {
        echo '<p class="muted">Not on a free trial. A trial is only for a business that has never paid.</p>';
    }
    echo '</section>';

    // Go-live introduction email (owner 2026-11-09): sent by hand when the business is ready to go live.
    echo '<section class="card" id="golive"><h2>Introduction email</h2>'
        . '<p class="muted">When this business is ready to go live, send them an email that introduces the system: what is included, '
        . 'the website and staff sign-in addresses, and the first steps. It never contains passwords; give login details separately.</p>'
        . '<form method="post" action="tenant.php?id=' . $tid0 . '#golive" class="js-once">' . $tHidden
        . '<input type="hidden" name="action" value="go_live_send">'
        . '<input type="hidden" name="once" value="' . e(billing_once_issue('go_live_' . $tid0)) . '">'
        . '<label>Personal note at the top (optional)<textarea name="message" rows="3" maxlength="2000"></textarea></label>'
        . '<button type="submit" class="secondary">Send introduction email</button></form></section>';

    // Billing (2026-11-09): what this business owes on sent invoices.
    $bill = billing_tenant_summary($pdo, (int)$tenant['id']);
    $tid = (int)$tenant['id'];
    $tClient = client_for_tenant($pdo, $tid);
    echo '<section class="card"><h2>Billing</h2><div class="bill-stats">'
        . '<p><span class="muted">Owed</span><strong>' . e(billing_money($bill['owed'], $bill['currency'])) . '</strong></p>'
        . '<p><span class="muted">Overdue</span><strong' . ($bill['overdue'] > BILLING_TOLERANCE ? ' class="bill-overdue"' : '') . '>'
        . e(billing_money($bill['overdue'], $bill['currency'])) . '</strong></p>'
        . '<p><span class="muted">Last payment</span><strong>' . ($bill['last_payment']
            ? e(billing_money((float)$bill['last_payment']['amount'], (string)$bill['last_payment']['currency'])) . ' <span class="muted">on ' . e(billing_date(substr((string)$bill['last_payment']['created_at'], 0, 10))) . '</span>'
            : view_empty()) . '</strong></p></div>'
        . (!empty($bill['other']) ? '<p class="muted">Also owed: ' . e(implode(', ', array_map(static fn($c, $v) => billing_money((float)$v['owed'], (string)$c), array_keys($bill['other']), $bill['other']))) . ' (each currency is billed separately).</p>' : '');
    if ($bill['open']) {
        echo '<div class="scroll"><table><thead><tr>'
            . view_th('Invoice', 'Invoice number.') . view_th('Date', 'Date of the invoice.') . view_th('Due', 'Due date.')
            . view_th('Balance', 'Still to pay on this invoice.') . '</tr></thead><tbody>';
        foreach ($bill['open'] as $d) {
            echo '<tr><td data-label="Invoice"><a href="document.php?id=' . (int)$d['id'] . '">' . e($d['number']) . '</a></td>'
                . '<td data-label="Date" class="nw">' . e(billing_date($d['issue_date'])) . '</td>'
                . '<td data-label="Due" class="nw">' . e(billing_date($d['due_date'])) . ($d['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '') . '</td>'
                . '<td data-label="Balance" class="nw num">' . e(billing_money($d['balance'], $d['currency'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="muted">No unpaid invoices.</p>';
    }
    echo '<p class="bill-links">' . ($tClient ? '<a href="client.php?id=' . (int)$tClient['id'] . '">Client page</a>' : '')
        . '<a href="document.php?new=invoice&amp;tenant=' . $tid . '">New invoice</a>'
        . '<a href="document.php?new=quote&amp;tenant=' . $tid . '">New quotation</a>'
        . '<a href="statement.php?tenant=' . $tid . '">Statement</a>'
        . '<a href="invoices.php?tenant=' . $tid . '">All invoices</a></p>';
    if (($tenant['billing_email'] ?? '') === '') {
        echo '<p class="muted">No billing email yet: add one under Details so invoices and reminders can be emailed.</p>';
    }
    echo '</section>';

    $extInv = billing_open_extending_invoice($pdo, $tid);
    echo '<section class="card"><h2>Record payment</h2>'
        . '<p class="muted">Extends "paid until" from today or the current paid-until date, whichever is later. '
        . 'For money against an invoice, use Record payment on the invoice instead.</p>'
        . (trial_is_on($tenant) ? '<p class="flash flash-info">This business is on a free trial. A payment recorded here ends the trial: it becomes a paying customer with the normal step-down days. '
            . 'To set up its monthly billing as well, use <a href="#trial">Convert to paid</a> instead. '
            . 'An amount of 0 gives free months instead: the trial carries on that many months longer, and no payment is recorded.</p>' : '')
        . ($extInv ? '<p class="flash flash-info">Invoice <a href="document.php?id=' . (int)$extInv['id'] . '">' . e($extInv['number']) . '</a> extends this licence when it is paid: record the payment there, not here, or the licence would be extended twice.</p>' : '')
        . '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '" class="js-once">' . csrf_field()
        . '<input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
        . '<input type="hidden" name="once" value="' . e(billing_once_issue('tenant_pay_' . $tid)) . '">'
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
    . (!$tenant ? '<fieldset class="modules"><legend>Free trial (optional)</legend><p class="muted">A trial sets paid until to its last day and uses the '
        . 'after-trial step-down from Settings. Leave "No free trial" to set paid until yourself.</p>'
        . '<label>Length<select name="trial_preset"><option value="">No free trial</option>'
        . implode('', array_map(static fn($p) => '<option value="' . $p . '"' . ((string)($_POST['trial_preset'] ?? '') === (string)$p ? ' selected' : '') . '>' . $p . ' days</option>', TRIAL_PRESETS))
        . '<option value="custom"' . (($_POST['trial_preset'] ?? '') === 'custom' ? ' selected' : '') . '>Custom (below)</option></select></label>'
        . '<label>Custom days (1-' . TRIAL_MAX_DAYS . ')<input type="number" name="trial_custom" min="1" max="' . TRIAL_MAX_DAYS . '" value="' . e((string)($_POST['trial_custom'] ?? '')) . '"></label></fieldset>' : '')
    . '<label>Paid until (YYYY-MM-DD; use Record payment to extend' . (!$tenant ? '; not needed with a free trial' : '') . ')<input type="date" name="paid_until"' . ($tenant ? ' required' : '') . ' value="' . e($v('paid_until')) . '"></label>'
    . '<label>Grace days (banner shown, everything works)<input type="number" name="grace_days" min="0" max="365" required value="' . e($v('grace_days', billing_setting('default_grace_days'))) . '"></label>'
    . '<label>Read-only days (view, check-out and export only)<input type="number" name="readonly_days" min="0" max="365" required value="' . e($v('readonly_days', billing_setting('default_readonly_days'))) . '"></label>'
    . '<fieldset class="modules"><legend>Billing contact (invoices, reminders and statements go here)</legend>'
    . '<label>Contact name<input type="text" name="contact_name" maxlength="120" value="' . e($v('contact_name')) . '"></label>'
    . '<label>Billing email<input type="email" name="billing_email" maxlength="190" value="' . e($v('billing_email')) . '"></label>'
    . '<label>Copy to (other emails, separated by commas)<input type="text" name="cc_emails" maxlength="500" value="' . e($v('cc_emails')) . '"></label>'
    . '<label>Phone<input type="text" name="contact_phone" maxlength="60" value="' . e($v('contact_phone')) . '"></label>'
    . '<label>Billing address (printed on invoices)<textarea name="billing_address" rows="3" maxlength="1000">' . e($v('billing_address')) . '</textarea></label>'
    . '</fieldset>'
    . '<label>Notes<textarea name="notes" rows="3" maxlength="2000">' . e($v('notes')) . '</textarea></label>'
    . '<button type="submit">' . ($tenant ? 'Save details' : 'Add business') . '</button></form></section>';

if ($tenant) {
    $hint = (string)($tenant['api_key_hint'] ?? '');
    echo '<section class="card"><h2>Licence key (API key)</h2>'
        . '<p>' . (!$tenant['api_key_hash'] ? 'No key set; the business cannot check in.'
            : ($hint !== '' ? 'Ends in <code>â€¦' . e($hint) . '</code>. It should match the end of the key in that business\'s settings file or on its Licence page.' : 'A key is set.')) . '</p>'
        . '<p class="muted">The full key is never stored here (only a scrambled copy), so it cannot be shown again. Generating a new key stops the old one working immediately; give the new one to the business straight after.</p>'
        . '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '">' . csrf_field()
        . '<input type="hidden" name="action" value="regen_key"><input type="hidden" name="id" value="' . (int)$tenant['id'] . '">'
        . '<button type="submit" class="danger">Generate new licence key</button></form></section>';

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
            . view_th('', 'Void a payment entered by mistake, or record money given back.', 'Void or refund')
            . '</tr></thead><tbody>';
        $methods = billing_payment_methods();
        foreach ($payments as $p) {
            $isLive = billing_payment_live($p);
            $state = billing_payment_state($p, billing_payment_family($pdo, $p));
            if ($isLive && !empty($p['licence_back_at'])) {
                $state = trim($state . ' · licence time taken back', ' ·');
            }
            $fix = view_empty();
            if (!empty($p['document_id'])) {
                $fix = '<a href="document.php?id=' . (int)$p['document_id'] . '#pay">On the invoice</a>';
            } elseif ($isLive && !billing_payment_is_refund($p)) {
                $left = billing_payment_refundable($p, billing_payment_family($pdo, $p));
                $once = e(billing_once_issue('pay_fix_' . (int)$p['id']));
                $base = '<input type="hidden" name="id" value="' . (int)$tenant['id'] . '"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
                    . '<input type="hidden" name="once" value="' . $once . '">';
                $months = (int)$p['months'];
                $fix = '<details><summary>Void or refund</summary>';
                if ($left >= round((float)$p['amount'], 2) - BILLING_TOLERANCE) {
                    $fix .= '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '" class="spaced js-once" data-confirm="Void this payment? It stops counting everywhere.">' . csrf_field() . $base
                        . '<input type="hidden" name="action" value="payment_void"><h3>Void: entered by mistake</h3>'
                        . '<p class="muted">The payment stops counting' . ($months > 0 && empty($p['licence_back_at']) ? ' and the ' . $months . ' month' . ($months === 1 ? '' : 's') . ' it added are taken back' : '') . '.</p>'
                        . '<label>Why<input type="text" name="reason" required minlength="5" maxlength="255" placeholder="Entered twice"></label>'
                        . '<button type="submit" class="danger">Void payment</button></form>';
                }
                $fix .= '<form method="post" action="tenant.php?id=' . (int)$tenant['id'] . '" class="spaced js-once" data-confirm="Record this refund?">' . csrf_field() . $base
                    . '<input type="hidden" name="action" value="payment_refund"><h3>Refund: money given back</h3>'
                    . '<p class="muted">Up to ' . e(billing_money($left, (string)$p['currency'])) . '. It counts as money out on the day you record it.</p>'
                    . '<label>Amount given back<input type="text" name="amount" inputmode="decimal" required value="' . e(number_format($left, 2, '.', '')) . '"></label>'
                    . '<label>Given back by<select name="method" required>';
                foreach ($methods as $mk => $ml) {
                    $fix .= '<option value="' . e($mk) . '">' . e($ml) . '</option>';
                }
                $fix .= '</select></label><label>Reference<input type="text" name="reference" required minlength="3" maxlength="190"></label>'
                    . ($months > 0 && empty($p['licence_back_at']) ? '<label class="check"><input type="checkbox" name="take_back" value="1" checked> Take back the ' . $months . ' month'
                        . ($months === 1 ? '' : 's') . ' it added to paid until</label>' : '')
                    . '<button type="submit" class="danger">Record refund</button></form></details>';
            }
            echo '<tr' . (!$isLive ? ' class="muted"' : '') . '><td data-label="Recorded" class="nw">' . e($p['created_at']) . '</td>'
                . '<td data-label="Amount" class="nw">' . (!$isLive ? '<s>' : '') . e($p['amount']) . ' ' . e($p['currency']) . (!$isLive ? '</s>' : '')
                . ($state !== '' ? '<br><span class="pill pill-' . (!$isLive ? 'suspended' : 'grace') . '">' . e($state) . '</span>' : '') . '</td>'
                . '<td data-label="Months">' . (int)$p['months'] . '</td>'
                . '<td data-label="Paid until" class="nw">' . e($p['paid_until_before']) . ' to ' . e($p['paid_until_after']) . '</td>'
                . '<td data-label="Reference">' . (($p['reference'] ?? '') !== '' ? e($p['reference']) : view_empty()) . '</td>'
                . '<td data-label="Void or refund">' . $fix . '</td></tr>';
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
