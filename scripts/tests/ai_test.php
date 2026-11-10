<?php
// The Assistant (AI) package from the panel (app/ai.php, 2026-11-11).
// Pure tests always run and never touch the network (a fake transport answers every provider call).
// With --db a smoke test runs on the real database inside ONE transaction that is rolled back: a made-up business saves a
// key, tests it (fake provider), sends it, the licence answer carries it encrypted for that business only, the hotel's report
// confirms it and the sealed key is wiped from the command.
//   php scripts/tests/ai_test.php        pure tests
//   php scripts/tests/ai_test.php --db   pure tests + rolled-back database smoke test
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/hms.php';
require __DIR__ . '/../../app/ai.php';

$box = random_bytes(32);
$key = 'sk-ant-api03-' . str_repeat('Ab3', 10) . 'WXYZ';

// --- the key at rest
t_eq('five providers, as at the hotel', ['anthropic', 'openai', 'google', 'xai', 'deepseek'], array_keys(ai_providers()));
t_ok('a real-looking key is accepted', ai_key_looks_valid($key));
t_ok('a key with a space is refused', !ai_key_looks_valid('sk-ant abc def ghi jkl mno pqr'));
t_ok('a short key is refused', !ai_key_looks_valid('sk-short'));
$sealed = ai_seal($key, $box);
t_ok('sealed form has its prefix and hides the key', is_string($sealed) && strncmp($sealed, 'cpk1:', 5) === 0 && strpos($sealed, 'WXYZ') === false);
t_eq('opens again with the same box key', $key, ai_open($sealed, $box));
t_eq('wrong box key: nothing', null, ai_open($sealed, random_bytes(32)));
t_eq('no box key: cannot seal', null, ai_seal($key, null));
$raw = base64_decode(substr($sealed, 5));
$raw[14] = chr(ord($raw[14]) ^ 1);
t_eq('changed sealed value: nothing', null, ai_open('cpk1:' . base64_encode($raw), $box));
t_ok('two seals of one key differ (random nonce)', ai_seal($key, $box) !== $sealed);

// --- the key in the licence answer
$blob = ai_transport_seal($key, 'licence-key-abc-123', 'Rosalyns', 42);
t_ok('transport form has its prefix and hides the key', is_string($blob) && strncmp($blob, 'cpai1:', 6) === 0 && strpos($blob, 'WXYZ') === false);
t_eq('the business opens it with its licence key', $key, ai_transport_open($blob, 'licence-key-abc-123', 'rosalyns', 42));
t_eq('another licence key cannot', null, ai_transport_open($blob, 'licence-key-abc-124', 'rosalyns', 42));
t_eq('another business cannot', null, ai_transport_open($blob, 'licence-key-abc-123', 'liwonde', 42));
t_eq('bound to its command id', null, ai_transport_open($blob, 'licence-key-abc-123', 'rosalyns', 43));
t_eq('no licence key: not sent', null, ai_transport_seal($key, '', 'x', 1));
// The hotels' includes/licence-ai.php test opens this same fixed vector (made once with ai_transport_seal).
t_eq('fixed vector shared with the hotels\' test', 'sk-ant-api03-FIXEDVECTORFORTESTS-0000',
    ai_transport_open('cpai1:mx+/a+XvUaIuY6MWllBud/gXZDJ7fjATg/EV/Gr6vYtVmRkgKHHwvLJJ7BEcyZ2bBZNwO2kdAOKivrRSZjI4hMs=', 'test-licence-key-0123456789', 'zz-test', 42));

// --- fingerprint (what a test proved)
$m = ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => ''];
$fp = ai_fingerprint('anthropic', $key, $m, $box);
t_eq('fingerprint is stable', $fp, ai_fingerprint('anthropic', $key, $m, $box));
t_ok('another key changes it', $fp !== ai_fingerprint('anthropic', $key . 'x', $m, $box));
t_ok('another model changes it', $fp !== ai_fingerprint('anthropic', $key, ['primary' => 'claude-opus-5-5'] + $m, $box));
t_ok('a role swap changes it', $fp !== ai_fingerprint('anthropic', $key, ['primary' => 'claude-haiku-5-5', 'fast' => 'claude-sonnet-5-5', 'fallback' => ''], $box));
t_ok('it does not contain the key', strpos($fp, 'WXYZ') === false && strlen($fp) === 64);

// --- scrubbing and plain words
t_eq('the key is scrubbed from provider text', 'bad key [key] here', ai_scrub('bad key ' . $key . ' here', $key));
t_eq('key-shaped text is scrubbed even without the key', 'got [key]', ai_scrub('got sk-proj-abcdefghijklmnop'));
t_eq('401 in plain words', 'The key was refused - check it was copied in full (HTTP 401): invalid x-api-key',
    ai_http_problem(['http' => 401, 'body' => '{"error":{"message":"invalid x-api-key"}}', 'error' => ''], $key));
t_eq('no network in plain words', 'Could not reach the provider (timeout).', ai_http_problem(['http' => 0, 'body' => '', 'error' => 'timeout'], $key));

// --- model lists
t_eq('anthropic list: labels kept', [['model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5']],
    ai_normalise_models('anthropic', ['data' => [['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5']]]));
t_eq('openai list: non-chat models dropped', ['gpt-5', 'gpt-5-mini'],
    array_column(ai_normalise_models('openai', ['data' => [['id' => 'gpt-5'], ['id' => 'text-embedding-3-large'], ['id' => 'gpt-5-mini'], ['id' => 'whisper-1'], ['id' => 'gpt-image-1']]]), 'model_id'));
t_eq('google list: only generateContent models, prefix dropped', ['gemini-3.6-flash'],
    array_column(ai_normalise_models('google', ['models' => [['name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']]]]), 'model_id'));

// --- choosing models
$ids = ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'];
t_eq('a good choice', ['', ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-opus-5-5']],
    ai_clean_models(['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-opus-5-5'], $ids));
t_ok('one model in two roles is refused (the hotel keeps one role per model)',
    strpos(ai_clean_models(['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-sonnet-5-5'], $ids)[0], 'one role only') !== false);
t_ok('a model the provider did not list is refused', ai_clean_models(['primary' => 'gpt-5'], $ids)[0] !== '');
t_eq('Fast without a Primary is refused', 'Choose a Primary model first.', ai_clean_models(['fast' => 'claude-haiku-5-5'], $ids)[0]);
t_eq('nothing chosen yet is fine', '', ai_clean_models([], $ids)[0]);
t_ok('odd characters refused', ai_clean_models(['primary' => 'x" onmouseover="y'], null)[0] !== '');

// --- subscription
t_eq('subscription ok', ['', ['key_payer' => 'business', 'monthly_budget_usd' => '12.50', 'plan_item_id' => 7, 'notes' => 'x']],
    ai_validate_subscription(['key_payer' => 'business', 'monthly_budget_usd' => '12.5', 'plan_item_id' => '7', 'notes' => ' x '], [7, 8]));
t_ok('a plan not in the catalog is refused', ai_validate_subscription(['key_payer' => 'msp', 'monthly_budget_usd' => '5', 'plan_item_id' => '9'], [7])[0] !== '');
t_ok('a negative or wordy aim is refused', ai_validate_subscription(['key_payer' => 'msp', 'monthly_budget_usd' => '-1'], [])[0] !== ''
    && ai_validate_subscription(['key_payer' => 'msp', 'monthly_budget_usd' => 'five'], [])[0] !== '');
t_ok('unknown payer refused', ai_validate_subscription(['key_payer' => 'nobody', 'monthly_budget_usd' => '5'], [])[0] !== '');

// --- when a set-up may be sent
$now = 1_800_000_000;
$cfg = ['provider' => 'anthropic', 'key_sealed' => $sealed, 'key_last4' => 'WXYZ', 'model_primary' => 'claude-sonnet-5-5', 'model_fast' => 'claude-haiku-5-5',
    'model_fallback' => '', 'monthly_budget_usd' => '5.00', 'test_ok' => '1', 'test_hash' => $fp, 'test_at' => date('Y-m-d H:i:s', $now - 60)];
t_eq('tested and unchanged: may be sent', '', ai_deploy_problem($cfg, $key, $fp, true, true, $now));
t_ok('never tested', ai_deploy_problem(['test_ok' => null, 'test_hash' => null] + $cfg, $key, $fp, false, true, $now) === 'Test first: click Save and test.');
t_ok('test failed', strpos(ai_deploy_problem(['test_ok' => '0'] + $cfg, $key, $fp, false, true, $now), 'did not pass') !== false);
t_ok('changed after the test', strpos(ai_deploy_problem($cfg, $key, 'other', false, true, $now), 'changed after the last test') !== false);
t_ok('test too old', strpos(ai_deploy_problem($cfg, $key, $fp, false, true, $now + AI_TEST_VALID_SECONDS + 100), 'more than a day') !== false);
t_ok('switching on needs the add-on in the licence', strpos(ai_deploy_problem($cfg, $key, $fp, true, false, $now), 'not in this business') !== false);
t_eq('not switching on: the add-on is not needed to send', '', ai_deploy_problem($cfg, $key, $fp, false, false, $now));
t_ok('no readable key', strpos(ai_deploy_problem($cfg, null, $fp, false, true, $now), 'No readable key') !== false);
t_ok('no Primary', strpos(ai_deploy_problem(['model_primary' => ''] + $cfg, $key, $fp, false, true, $now), 'Primary') !== false);

// --- what is queued and what travels
$data = ai_deploy_data($cfg, true);
$summary = ai_deploy_summary($data);
t_ok('summary in plain words, last 4 only', strpos($summary, "Anthropic (Claude) key \u{2022}\u{2022}\u{2022}\u{2022}WXYZ, Primary claude-sonnet-5-5, Fast claude-haiku-5-5, aim US$5 a month, switch it on") !== false
    && strpos($summary, 'sk-ant') === false);
$cmd = ['id' => 42, 'kind' => 'ai_setup', 'data' => json_encode($data)];
$p = ai_command_payload($cmd, 'licence-key-abc-123', 'zz-test', $box);
t_ok('payload: the sealed key never travels', !isset($p['data']['key_sealed']) && strpos(json_encode($p), $sealed) === false);
t_ok('payload: the plain key never travels (only its last 4)', strpos(json_encode($p), substr($key, 0, 20)) === false && strpos(json_encode($p), json_encode($key)) === false);
t_eq('payload: the business opens the key', $key, ai_transport_open((string)$p['data']['key_enc'], 'licence-key-abc-123', 'zz-test', 42));
t_eq('payload: shape', ['id' => 42, 'kind' => 'ai_setup', 'username' => '', 'user_id' => 0], array_slice($p, 0, 4));
t_eq('payload: models and switch travel', [['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => ''], true], [$p['data']['models'], $p['data']['switch_on']]);
t_eq('payload: a key the panel cannot open is not sent', null, ai_command_payload($cmd, 'licence-key-abc-123', 'zz-test', random_bytes(32)));
t_eq('payload: no licence key, not sent', null, ai_command_payload($cmd, '', 'zz-test', $box));

// --- the hotel's report
$st = ai_clean_state(['ready' => 1, 'enabled' => true, 'provider' => 'anthropic', 'key_last4' => 'WXYZ', 'key_status' => 'ok', 'budget_usd' => '5',
    'models' => [['model_id' => 'claude-sonnet-5-5', 'role' => 'primary'], ['model_id' => 'claude-haiku-5-5', 'role' => 'fast'], 'junk', ['role' => 'x']],
    'usage' => ['month' => '2026-11', 'requests' => 12, 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.0123], 'api_key' => $key,
    'last_test_message' => 'Connected with ' . $key]);
t_ok('report: never keeps a key, even if sent', strpos(json_encode($st), $key) === false && !isset($st['api_key']));
t_eq('report: models kept, junk dropped', ['claude-sonnet-5-5', 'claude-haiku-5-5'], array_column($st['models'], 'model_id'));
t_eq('report: usage kept', 12, $st['usage']['requests']);
t_eq('report: not a report', null, ai_clean_state('x'));
unset($data['key_sealed']);
t_eq('matches what was sent: no differences', [], ai_differences($data, $st));
$changed = $st;
$changed['models'][0]['model_id'] = 'claude-opus-5-5';
$changed['enabled'] = false;
$changed['off_reason'] = 'Switched off.';
$d = ai_differences($data, $changed);
t_eq('an admin changed the Primary and switched it off: both shown', 2, count($d));
t_ok('and in plain words', strpos($d[0], 'Primary model at the hotel: claude-opus-5-5 (sent: claude-sonnet-5-5)') === 0);
$changed = $st;
$changed['key_last4'] = 'QQQQ';
t_ok('another key at the hotel is noticed', strpos(implode(' ', ai_differences($data, $changed)), 'different key') !== false);

// --- the panel's test, with a fake provider (no network)
$calls = [];
ai_set_transport(static function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = [$method, $url, $headers, $body];
    if ($method === 'GET') {
        return ['http' => 200, 'body' => json_encode(['data' => [['id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5'], ['id' => 'claude-haiku-5-5']], 'has_more' => false])];
    }
    $b = json_decode($body, true);
    if ($b['model'] === 'claude-haiku-5-5') {
        return ['http' => 429, 'body' => '{"error":{"message":"credit balance too low"}}'];
    }
    return ['http' => 200, 'body' => '{"content":[{"text":"OK"}],"stop_reason":"end_turn"}'];
});
$r = ai_run_test('anthropic', $key, ['primary' => '', 'fast' => '', 'fallback' => '']);
t_ok('no models chosen yet: the list loads, the test is not passed', !$r['ok'] && count($r['listed']) === 2 && strpos($r['message'], 'choose the models') !== false);
$calls = [];
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-gone-1']);
t_ok('every chosen model is tested: one passes, one is out of credit, one is not offered', !$r['ok']
    && array_map(static fn($c) => $c['ok'], $r['checks']) === [true, false, false]);
t_ok('the credit problem is in plain words', strpos($r['checks'][1]['message'], 'limit or credit') !== false);
t_eq('not offered: no call made for it', 3, count($calls));
t_ok('the key travels in a header only, never in a URL', strpos($calls[0][1], $key) === false && in_array('x-api-key: ' . $key, $calls[0][2], true));
t_ok('the test asks for at most 5 tokens', json_decode($calls[1][3], true)['max_tokens'] === 5);
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5', 'fast' => '', 'fallback' => '']);
t_ok('all chosen answered: passed', $r['ok'] && strpos($r['message'], 'Passed') === 0);
ai_set_transport(static fn() => ['http' => 401, 'body' => '{"error":{"message":"invalid x-api-key ' . $key . '"}}']);
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5']);
t_ok('a refused key: not passed, and the key is scrubbed from the message', !$r['ok'] && strpos($r['message'], 'refused') !== false && strpos($r['message'], $key) === false);
ai_set_transport(null);

if (in_array('--db', $argv, true)) {
    require __DIR__ . '/../../app/bootstrap.php';
    $GLOBALS['__cp_ai_box_test'] = $box; // the laptop has no signing key; the live panel derives its own
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO cp_tenants (slug, name, domain, paid_until, modules) VALUES ('zz-ai-test', 'AI test', 'ai-test.invalid', CURDATE(), ?)")
            ->execute([json_encode(['bookings', 'finance'])]);
        $tid = (int)$pdo->lastInsertId();
        $t = tenant_find($pdo, $tid);
        t_eq('db: no set-up yet', null, ai_config($pdo, $tid));
        t_ok('db: not licensed yet', !ai_licensed($t));
        t_eq('db: a provider change needs its key', 'Paste the key first.', ai_save_setup($pdo, $tid, 'anthropic', '', [], 0));
        t_eq('db: key saved', '', ai_save_setup($pdo, $tid, 'anthropic', $key, [], 0));
        $c = ai_config($pdo, $tid);
        t_ok('db: stored sealed, last 4 in clear, never the key', $c['key_last4'] === 'WXYZ' && strpos(json_encode($c), $key) === false && ai_open($c['key_sealed'], $box) === $key);
        $aud = $pdo->prepare("SELECT COUNT(*) FROM cp_audit_log WHERE tenant_id = ? AND (CONCAT_WS(' ', before_json, after_json) LIKE ? OR CONCAT_WS(' ', before_json, after_json) LIKE ?)");
        ai_set_transport(static function ($method, $url, $headers, $body) {
            if ($method === 'GET') {
                return ['http' => 200, 'body' => json_encode(['data' => [['id' => 'claude-sonnet-5-5'], ['id' => 'claude-haiku-5-5']]])];
            }
            return ['http' => 200, 'body' => '{"content":[{"text":"OK"}]}'];
        });
        $r = ai_test_saved($pdo, $tid, 0);
        t_ok('db: first test lists the models but does not pass (none chosen)', !$r['ok'] && ai_listed_models(ai_config($pdo, $tid)) !== null);
        t_eq('db: models chosen from the list', '', ai_save_setup($pdo, $tid, 'anthropic', '', ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5'], 0));
        t_ok('db: a model not in the list is refused', ai_save_setup($pdo, $tid, 'anthropic', '', ['primary' => 'gpt-5'], 0) !== '');
        t_ok('db: saving again sets the test back', ai_config($pdo, $tid)['test_ok'] === null);
        [$err] = ai_deploy($pdo, $t, false, 0);
        t_eq('db: cannot send before a test', 'Test first: click Save and test.', $err);
        $r = ai_test_saved($pdo, $tid, 0);
        t_ok('db: second test passes', $r['ok'] && (int)ai_config($pdo, $tid)['test_ok'] === 1);
        [$err] = ai_deploy($pdo, $t, true, 0);
        t_ok('db: switching on refused while the add-on is not licensed', strpos($err, 'not in this business') !== false);
        ai_set_licensed($pdo, $tid, true, 0);
        $t = tenant_find($pdo, $tid);
        t_eq('db: add-on started, other modules kept', ['bookings', 'finance', 'ai_assistant'], catalog_tenant_modules($t));
        [$err, $cmdId] = ai_deploy($pdo, $t, true, 0);
        t_ok('db: sent', $err === '' && $cmdId > 0);
        [$err, $cmdId2] = ai_deploy($pdo, $t, true, 0);
        $older = $pdo->query('SELECT result FROM cp_hms_commands WHERE id = ' . $cmdId)->fetchColumn();
        t_eq('db: sending again cancels the older one still waiting', 'cancelled', $older);
        t_ok('db: and wipes its sealed key', strpos((string)$pdo->query('SELECT data FROM cp_hms_commands WHERE id = ' . $cmdId)->fetchColumn(), 'key_sealed') === false);
        $f = hms_answer_fields($pdo, $t, 0, '');
        t_eq('db: without the licence key the set-up is not sent', [], $f['staff_commands']);
        $f = hms_answer_fields($pdo, $t, 0, 'zz-licence-key-0001');
        t_eq('db: with it, the set-up travels', [$cmdId2], array_column($f['staff_commands'], 'id'));
        $pl = $f['staff_commands'][0];
        t_ok('db: answer holds no readable key', strpos(json_encode($f), $key) === false && strpos(json_encode($f), 'key_sealed') === false);
        t_eq('db: the business opens it', $key, ai_transport_open($pl['data']['key_enc'], 'zz-licence-key-0001', 'zz-ai-test', $cmdId2));
        t_ok('db: marked sent', $pdo->query('SELECT sent_at FROM cp_hms_commands WHERE id = ' . $cmdId2)->fetchColumn() !== null);
        hms_store_report($pdo, $tid, [['id' => $cmdId2, 'ok' => true, 'message' => 'Set up; the hotel test answered in 400 ms.']], null, null);
        t_eq('db: the hotel confirms it', 'ok', $pdo->query('SELECT result FROM cp_hms_commands WHERE id = ' . $cmdId2)->fetchColumn());
        t_ok('db: the sealed key is wiped once done', strpos((string)$pdo->query('SELECT data FROM cp_hms_commands WHERE id = ' . $cmdId2)->fetchColumn(), 'key_sealed') === false);
        ai_store_state($pdo, $tid, ai_clean_state(['ready' => 1, 'enabled' => 1, 'provider' => 'anthropic', 'key_last4' => 'WXYZ', 'budget_usd' => 5,
            'models' => [['model_id' => 'claude-sonnet-5-5', 'role' => 'primary'], ['model_id' => 'claude-haiku-5-5', 'role' => 'fast']]]));
        $c = ai_config($pdo, $tid);
        t_eq('db: hotel state matches what was sent', [], ai_differences(json_decode($c['deployed_json'], true), ai_state($c)));
        $aud->execute([$tid, '%' . $key . '%', '%' . substr($key, 0, 12) . '%']);
        t_eq('db: the audit log never holds the key', 0, (int)$aud->fetchColumn());
        $pdo->prepare('UPDATE cp_hms_commands SET done_at = NULL, result = NULL WHERE id = ?')->execute([$cmdId2]);
        $pdo->prepare('UPDATE cp_ai_config SET key_sealed = ? WHERE tenant_id = ?')->execute([ai_seal($key, random_bytes(32)), $tid]);
        $pdo->prepare('UPDATE cp_hms_commands SET data = ? WHERE id = ?')->execute([json_encode(['key_sealed' => ai_seal($key, random_bytes(32))] + $data), $cmdId2]);
        $f = hms_answer_fields($pdo, $t, 0, 'zz-licence-key-0001');
        t_eq('db: a key the panel cannot open is never sent', [], $f['staff_commands']);
        t_eq('db: and the set-up is marked not done, in plain words', 'failed', $pdo->query('SELECT result FROM cp_hms_commands WHERE id = ' . $cmdId2)->fetchColumn());
        ai_set_licensed($pdo, $tid, false, 0);
        t_eq('db: add-on stopped', ['bookings', 'finance'], catalog_tenant_modules(tenant_find($pdo, $tid)));
        $pdo->prepare('UPDATE cp_tenants SET modules = ? WHERE id = ?')->execute([json_encode(['finance', 'ai_assistant']), $tid]);
        $refused = '';
        try {
            ai_set_licensed($pdo, $tid, false, 0);
        } catch (RuntimeException $ex) {
            $refused = $ex->getMessage();
        }
        t_ok('db: stopping is refused when it would leave only Finance (the rule of the business form)', strpos($refused, 'only module besides Finance') !== false
            && catalog_tenant_modules(tenant_find($pdo, $tid)) === ['finance', 'ai_assistant']);
        ai_set_transport(null);
    } finally {
        $pdo->rollBack();
    }
    t_eq('db: nothing kept', 0, (int)$pdo->query("SELECT COUNT(*) FROM cp_tenants WHERE slug = 'zz-ai-test'")->fetchColumn());
}

t_done('ai_test');
