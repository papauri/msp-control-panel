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
t_eq('no models chosen yet: the newest are picked and tested', ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => ''], $r['models']);
t_ok('a picked model out of credit: not passed, said in plain words', !$r['ok'] && count($r['listed']) === 2 && strpos($r['checks'][1]['message'], 'limit or credit') !== false);
$calls = [];
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-gone-1']);
t_ok('every chosen model is tested: one passes, one is out of credit', !$r['ok'] && array_map(static fn($c) => $c['ok'], $r['checks']) === [true, false, false]);
t_eq('a chosen model no longer offered: nothing else free to take its role, so it stays and fails', 'claude-gone-1', $r['models']['fallback']);
t_eq('not offered: no call made for it', 3, count($calls));
t_ok('the key travels in a header only, never in a URL', strpos($calls[0][1], $key) === false && in_array('x-api-key: ' . $key, $calls[0][2], true));
t_ok('the test asks for at most 5 tokens', json_decode($calls[1][3], true)['max_tokens'] === 5);
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5', 'fast' => '', 'fallback' => '']);
t_ok('all chosen answered: passed', $r['ok'] && strpos($r['message'], 'Passed') === 0 && $r['changes'] === []);
ai_set_transport(static fn() => ['http' => 401, 'body' => '{"error":{"message":"invalid x-api-key ' . $key . '"}}']);
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5']);
t_ok('a refused key: not passed, and the key is scrubbed from the message', !$r['ok'] && strpos($r['message'], 'refused') !== false && strpos($r['message'], $key) === false);
ai_set_transport(null);

// --- always the newest models (owner 2026-11-11), on messy lists like the providers really send
t_eq('version from the id', [5.5, 4.5, 2.5, 4.1, 5.1, 0.0], array_map('ai_model_version', ['claude-sonnet-5-5', 'claude-haiku-4-5-20251001', 'gemini-2.5-flash', 'gpt-4.1', 'gpt-5.1', 'deepseek-chat']));
t_eq('family without versions or dates', ['claude-sonnet', 'claude-sonnet', 'gemini-flash-lite', 'gpt-mini', 'grok-fast-non-reasoning'],
    array_map('ai_model_family', ['claude-sonnet-5-5', 'claude-3-7-sonnet-20250219', 'gemini-3.5-flash-lite', 'gpt-5-mini-2025-08-07', 'grok-4-1-fast-non-reasoning']));
t_eq('sizes: big never picked for chat, small for Fast', ['big', 'big', 'mid', 'small', 'small', 'mid', 'big', 'big', 'big', 'small', 'mid'],
    [ai_model_tier('anthropic', 'claude-opus-5-5'), ai_model_tier('anthropic', 'claude-fable-5-2'), ai_model_tier('anthropic', 'claude-sonnet-5-5'), ai_model_tier('anthropic', 'claude-haiku-5-5'),
     ai_model_tier('openai', 'gpt-5-nano'), ai_model_tier('openai', 'gpt-5.1'), ai_model_tier('openai', 'gpt-5-pro'), ai_model_tier('google', 'gemini-2.5-pro'),
     ai_model_tier('xai', 'grok-4'), ai_model_tier('xai', 'grok-3-mini'), ai_model_tier('deepseek', 'deepseek-chat')]);
t_eq('a newer version of a family that is big by price is big too', 'big', ai_model_tier('xai', 'grok-5'));
t_ok('Gemini 4 is never used (owner rule)', ai_model_banned('gemini-4-argon') && ai_model_banned('models/gemini-4-flash') && !ai_model_banned('gemini-3.8-flash'));
t_ok('Gemini 4 refused when chosen by hand', strpos(ai_clean_models(['primary' => 'gemini-4-flash'], null)[0], 'Gemini 4') === 0);
t_ok('previews, aliases and special models are never picked automatically', ai_model_special('gemini-3.8-flash-preview-09-2026') && ai_model_special('gpt-5-chat-latest')
    && ai_model_special('gpt-5.1-codex') && ai_model_special('o4-mini') && ai_model_special('grok-code-fast-1') && ai_model_special('gpt-oss-120b')
    && ai_model_special('o3-deep-research') && !ai_model_special('gpt-4o-mini') && !ai_model_special('claude-sonnet-5-5'));

$lists = [
    'anthropic' => ['data' => [
        ['id' => 'claude-fable-5-2', 'created_at' => '2026-10-20T00:00:00Z'], ['id' => 'claude-opus-5-5', 'created_at' => '2026-09-01T00:00:00Z'],
        ['id' => 'claude-sonnet-5-5', 'created_at' => '2026-09-01T00:00:00Z'], ['id' => 'claude-haiku-5-5', 'created_at' => '2026-09-01T00:00:00Z'],
        ['id' => 'claude-opus-5', 'created_at' => '2026-05-01T00:00:00Z'], ['id' => 'claude-sonnet-5', 'created_at' => '2026-05-01T00:00:00Z'],
        ['id' => 'claude-sonnet-4-5-20250929', 'created_at' => '2025-09-29T00:00:00Z'], ['id' => 'claude-haiku-4-5-20251001', 'created_at' => '2025-10-01T00:00:00Z'],
        ['id' => 'claude-3-7-sonnet-20250219', 'created_at' => '2025-02-19T00:00:00Z'], ['id' => 'claude-3-5-haiku-20241022', 'created_at' => '2024-10-22T00:00:00Z'],
    ], 'has_more' => false],
    'openai' => ['data' => array_map(static fn($id) => ['id' => $id, 'created' => 1750000000], [
        'gpt-5.1', 'gpt-5.1-codex', 'gpt-5.1-chat-latest', 'gpt-5', 'gpt-5-2025-08-07', 'gpt-5-mini', 'gpt-5-mini-2025-08-07', 'gpt-5-nano', 'gpt-5-pro',
        'gpt-5-search-api', 'chatgpt-4o-latest', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini', 'o3', 'o4-mini', 'o3-deep-research', 'gpt-oss-120b',
        'gpt-3.5-turbo-instruct', 'gpt-realtime', 'gpt-audio', 'computer-use-preview', 'text-embedding-3-large', 'tts-1', 'dall-e-3'])],
    'google' => ['models' => array_map(static fn($id) => ['name' => 'models/' . $id, 'supportedGenerationMethods' => ['generateContent']], [
        'gemini-4-argon', 'gemini-4-flash', 'gemini-3.8-flash', 'gemini-3.8-flash-preview-09-2026', 'gemini-3.6-flash', 'gemini-3.5-flash-lite',
        'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite', 'gemini-flash-latest', 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemma-3-27b-it',
        'gemini-2.0-flash-exp', 'learnlm-2.0-flash-experimental', 'gemini-2.0-flash-thinking-exp'])],
    'xai' => ['data' => array_map(static fn($id) => ['id' => $id, 'created' => 1760000000], [
        'grok-4', 'grok-4-0709', 'grok-4-fast-reasoning', 'grok-4-fast-non-reasoning', 'grok-4-1-fast-reasoning', 'grok-4-1-fast-non-reasoning',
        'grok-3', 'grok-3-mini', 'grok-code-fast-1', 'grok-2-vision-1212', 'grok-2-image-1212'])],
    'deepseek' => ['object' => 'list', 'data' => [['id' => 'deepseek-chat', 'object' => 'model'], ['id' => 'deepseek-reasoner', 'object' => 'model']]],
];
$closed = []; // model ids the fake provider answers 404 for
$pinged = [];
ai_set_transport(static function ($method, $url, $headers, $body) use (&$lists, &$closed, &$pinged) {
    $p = strpos($url, 'anthropic') !== false ? 'anthropic' : (strpos($url, 'openai') !== false ? 'openai' : (strpos($url, 'googleapis') !== false ? 'google'
        : (strpos($url, 'x.ai') !== false ? 'xai' : 'deepseek')));
    if ($method === 'GET') {
        return ['http' => 200, 'body' => json_encode($lists[$p])];
    }
    $m = $p === 'google' ? (preg_match('#/models/([^:]+):#', $url, $x) ? rawurldecode($x[1]) : '') : (string)(json_decode($body, true)['model'] ?? '');
    $pinged[] = $m;
    if (in_array($m, $closed, true)) {
        return ['http' => 404, 'body' => '{"error":{"message":"model not found"}}'];
    }
    return ['http' => 200, 'body' => $p === 'anthropic' ? '{"content":[{"text":"OK"}]}' : ($p === 'google' ? '{"candidates":[{"content":{"parts":[{"text":"OK"}]}}]}' : '{"choices":[{"message":{"content":"OK"}}]}')];
});
$none = ['primary' => '', 'fast' => '', 'fallback' => ''];
$want = [
    'anthropic' => ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-sonnet-5'],
    'openai' => ['primary' => 'gpt-5.1', 'fast' => 'gpt-5-mini', 'fallback' => 'gpt-5'],
    'google' => ['primary' => 'gemini-3.8-flash', 'fast' => 'gemini-3.5-flash-lite', 'fallback' => 'gemini-3.6-flash'],
    'xai' => ['primary' => 'grok-4-1-fast-non-reasoning', 'fast' => 'grok-4-1-fast-reasoning', 'fallback' => 'grok-4-fast-non-reasoning'],
    'deepseek' => ['primary' => 'deepseek-chat', 'fast' => '', 'fallback' => 'deepseek-reasoner'],
];
foreach ($want as $prov => $w) {
    $r = ai_run_test($prov, $key, $none);
    t_eq($prov . ': the newest suitable models are picked', $w, $r['models']);
    t_ok($prov . ': and pass their test', $r['ok'] && count($r['changes']) === count(array_filter($w)));
}
$r = ai_run_test('google', $key, $none);
t_ok('google: Gemini 4 is not even listed', !array_filter(array_column($r['listed'], 'model_id'), 'ai_model_banned'));
t_eq('the list comes newest first', ['gemini-3.8-flash', 'gemini-3.8-flash-preview-09-2026'], array_slice(array_column($r['listed'], 'model_id'), 0, 2));
t_eq('anthropic: the dated date is kept from the list', '2026-09-01', array_column(ai_run_test('anthropic', $key, $none)['listed'], 'created', 'model_id')['claude-sonnet-5-5']);

$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-4-5-20250929', 'fast' => 'claude-3-5-haiku-20241022', 'fallback' => 'claude-opus-5']);
t_eq('older chosen models move to the newest of their family, same size', ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-opus-5-5'], $r['models']);
t_ok('and the page says so', $r['ok'] && in_array('Primary moved to the newer claude-sonnet-5-5 (was claude-sonnet-4-5-20250929).', ai_latest_notes($r), true));
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5', 'fast' => '', 'fallback' => 'claude-sonnet-5-5']);
t_eq('two roles never end on one model: the Fallback takes the next free one', ['primary' => 'claude-sonnet-5-5', 'fast' => '', 'fallback' => 'claude-sonnet-5'], $r['models']);
$r = ai_run_test('openai', $key, ['primary' => 'gpt-5.1-chat-latest', 'fast' => 'gpt-4o-mini', 'fallback' => '']);
t_eq('a special model chosen by hand stays; a small one moves to the newest small one of its family', ['primary' => 'gpt-5.1-chat-latest', 'fast' => 'gpt-5-mini', 'fallback' => ''], $r['models']);
$r = ai_run_test('anthropic', $key, ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => 'claude-sonnet-5']);
t_ok('already the newest: nothing changes', $r['ok'] && $r['changes'] === [] && ai_latest_notes($r) === []);

$closed = ['gpt-5.1'];
$pinged = [];
$r = ai_run_test('openai', $key, $none);
t_eq('a picked model the provider refuses (404): the next newest takes the role', ['primary' => 'gpt-5', 'fast' => 'gpt-5-mini', 'fallback' => 'gpt-4.1'], $r['models']);
t_ok('and the test passes', $r['ok']);
$r = ai_run_test('openai', $key, ['primary' => 'gpt-4.1', 'fast' => '', 'fallback' => '']);
t_eq('a newer model the provider refuses: the next newer one of the family', 'gpt-5', $r['models']['primary']);
$closed = ['gpt-5.1', 'gpt-5', 'gpt-4.1', 'gpt-4o'];
$pinged = [];
$r = ai_run_test('openai', $key, ['primary' => '', 'fast' => '', 'fallback' => '']);
t_eq('at most ' . AI_TRIES_PER_ROLE . ' test replies per role, then the next role', ['gpt-5.1', 'gpt-5', 'gpt-4.1', 'gpt-5-mini'], array_slice($pinged, 0, 4));
t_ok('Primary not found within the tries: not passed, the last one tried is shown', !$r['ok'] && $r['models']['primary'] === 'gpt-4.1');
$closed = [];
t_ok('"closed" means the model is not there for this key', ai_ping_closed(['http' => 404, 'message' => '']) && ai_ping_closed(['http' => 400, 'message' => 'The model gpt-9 does not exist'])
    && ai_ping_closed(['http' => 403, 'message' => 'You do not have access to this model']));
t_ok('a request problem with a new model is NOT "closed" (never quietly back to an older model)',
    !ai_ping_closed(['http' => 400, 'message' => 'Unsupported parameter: max_tokens is not supported with this model']) && !ai_ping_closed(['http' => 429, 'message' => 'model overloaded']));
ai_set_transport(static function ($method, $url, $headers, $body) use (&$lists) {
    if ($method === 'GET') {
        return ['http' => 200, 'body' => json_encode($lists['openai'])];
    }
    return json_decode($body, true)['model'] === 'gpt-5.1'
        ? ['http' => 400, 'body' => '{"error":{"message":"Unsupported parameter: max_completion_tokens is not supported with this model."}}']
        : ['http' => 200, 'body' => '{"choices":[{"message":{"content":"OK"}}]}'];
});
$r = ai_run_test('openai', $key, ['primary' => '', 'fast' => '', 'fallback' => '']);
t_ok('so the newest model stays chosen and the test is not passed (the page shows why)', !$r['ok'] && $r['models']['primary'] === 'gpt-5.1');
ai_set_transport(static function ($method, $url, $headers, $body) use (&$lists, &$closed, &$pinged) {
    if ($method === 'GET') {
        return ['http' => 200, 'body' => json_encode($lists[strpos($url, 'openai') !== false ? 'openai' : 'anthropic'])];
    }
    return ['http' => 200, 'body' => strpos($url, 'anthropic') !== false ? '{"content":[{"text":"OK"}]}' : '{"choices":[{"message":{"content":"OK"}}]}'];
});

$r = ai_run_test('anthropic', $key, $none);
t_eq('every pick is in the hotels\' price list: no warning', [], $r['unpriced']);
$r = ai_run_test('openai', $key, $none);
t_eq('a newest model the hotels have no price for is named', ['gpt-5.1'], $r['unpriced']);
t_ok('with a plain warning about the monthly aim', strpos(implode(' ', ai_latest_notes($r)), 'count the cost as US$0') !== false);
$lists['openai'] = ['data' => [['id' => 'gpt-5-pro'], ['id' => 'o3'], ['id' => 'gpt-5.1-codex']]];
$r = ai_run_test('openai', $key, $none);
t_ok('only big ones: a big model is used rather than nothing', $r['ok'] && $r['models']['primary'] === 'gpt-5-pro');
$lists['openai'] = ['data' => [['id' => 'o3'], ['id' => 'gpt-5.1-codex']]];
$r = ai_run_test('openai', $key, $none);
t_ok('only special models: nothing picked, choose by hand', !$r['ok'] && strpos($r['message'], 'Choose the models by hand') !== false && $r['models'] === $none);
ai_set_transport(null);

// the price list matches the hotels' catalog (when the hotel repo sits beside this one, as on the laptop)
$catFile = __DIR__ . '/../../../Rosalyns-hotel-2026/includes/ai/model-catalog.json';
if (is_file($catFile)) {
    $cat = json_decode((string)file_get_contents($catFile), true);
    $fromHotel = [];
    foreach ((array)($cat['providers'] ?? []) as $p => $v) {
        foreach ((array)($v['models'] ?? []) as $m) {
            if (isset($m['price_in'], $m['price_out'])) {
                $fromHotel[$p][(string)$m['id']] = [(float)$m['price_in'], (float)$m['price_out']];
            }
        }
    }
    t_eq('AI_HOTEL_PRICES is the hotels\' catalog (update it when the hotels\' catalog changes)', $fromHotel, AI_HOTEL_PRICES);
}

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
        $c = ai_config($pdo, $tid);
        t_ok('db: first test with only a key picks the newest models, saves them and passes', $r['ok'] && ai_listed_models($c) !== null
            && [$c['model_primary'], $c['model_fast'], $c['model_fallback']] === ['claude-sonnet-5-5', 'claude-haiku-5-5', ''] && (int)$c['test_ok'] === 1);
        t_ok('db: the saved test fingerprint covers the picked models (ready to send)', ai_current_hash($c) === $c['test_hash']);
        t_ok('db: the page notes say what was picked', strpos((string)$c['test_json'], 'Newest models picked') !== false);
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
