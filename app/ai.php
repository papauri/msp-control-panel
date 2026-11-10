<?php
// The Assistant (AI) package per business, managed from the panel (owner 2026-11-11).
//
// The owner enters the business's AI provider key and chooses its models here. The panel TESTS the key and every chosen
// model itself (it lists the provider's models, then sends each chosen model a 5-token "Reply OK") before anything can be
// sent. Sending ("deploy") queues one cp_hms_commands row of kind 'ai_setup', so it travels like a staff change: in the
// hotel's next SIGNED licence answer, applied once, confirmed in the hotel's staff report. The hotel tests the key again
// itself and changes nothing if that fails. Its own administrators may change the Assistant afterwards in Assistant
// Settings; the panel never re-applies anything until the owner sends again, and shows where the hotel now differs.
//
// The key:
//   - at rest on the panel it is SEALED (AES-256-GCM under keys_derive('cp-ai-v1')); only its last 4 characters are clear;
//   - in the licence answer it is encrypted again (AES-256-GCM, 'cpai1:') under a key derived from the business's own
//     licence key (which the hotel has just sent in its check-in, and which the panel stores only as a password hash), so the
//     answer the hotel keeps in site_settings never holds it readable;
//   - the sealed copy is wiped from the command as soon as the command is finished (done, refused, cancelled or lapsed);
//   - it is never logged, audited, shown or sent anywhere else. Provider error texts are scrubbed of anything key-shaped.
//
// Provider calls are ported from the hotel's includes/ai/ai-providers.php (same five providers, same headers, same test).
// The HTTP layer is injectable (ai_set_transport) so the tests never touch the network.

const AI_KIND = 'ai_setup';
const AI_ROLES = [
    'primary' => 'Primary',
    'fast' => 'Fast',
    'fallback' => 'Fallback',
];
const AI_ROLE_HELP = [
    'primary' => 'Answers questions and plans. Required.',
    'fast' => 'Short replies and routing. Usually the cheapest.',
    'fallback' => 'Used when the Primary fails or is busy.',
];
const AI_PAYERS = ['msp' => 'We pay the provider (part of the package)', 'business' => 'The business pays the provider (its own key)'];
const AI_TEST_VALID_SECONDS = 86400; // a test older than this must be run again before sending
const AI_MAX_MODELS_KEPT = 400;

function ai_providers(): array
{
    return [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'hint' => 'Starts with sk-ant-. Create one at console.anthropic.com > API keys.'],
        'openai' => ['label' => 'OpenAI (GPT)', 'hint' => 'Starts with sk-. Create one at platform.openai.com > API keys.'],
        'google' => ['label' => 'Google (Gemini)', 'hint' => 'Create one at aistudio.google.com > Get API key.'],
        'xai' => ['label' => 'xAI (Grok)', 'hint' => 'Starts with xai-. Create one at console.x.ai.'],
        'deepseek' => ['label' => 'DeepSeek', 'hint' => 'Starts with sk-. Hosted in China: questions are processed there.'],
    ];
}

function ai_provider_label(string $p): string
{
    return ai_providers()[$p]['label'] ?? ($p !== '' ? $p : '-');
}

// ---------------------------------------------------------------------------------------------------- pure: the key

/** A pasted key is plausible: no spaces, 20 to 300 printable characters (the hotel's own rule). */
function ai_key_looks_valid(string $key): bool
{
    return (bool)preg_match('/^[\x21-\x7E]{20,300}$/D', $key);
}

/** The panel's at-rest key for AI provider keys (null when there is no usable signing key). */
function ai_box_key(): ?string
{
    if (PHP_SAPI === 'cli' && isset($GLOBALS['__cp_ai_box_test']) && is_string($GLOBALS['__cp_ai_box_test'])) {
        return $GLOBALS['__cp_ai_box_test']; // the --db tests on a laptop without the signing key
    }
    return function_exists('keys_derive') ? keys_derive('cp-ai-v1') : null;
}

/** Seal a provider key for storage: "cpk1:" + base64(nonce . ciphertext . tag). Null when it cannot be sealed safely. */
function ai_seal(string $plain, ?string $box): ?string
{
    if ($plain === '' || $box === null || strlen($box) !== 32) {
        return null;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $box, OPENSSL_RAW_DATA, $nonce, $tag, 'cp-ai-key', 16);
    if ($ct === false || strlen($tag) !== 16) {
        return null;
    }
    $sealed = 'cpk1:' . base64_encode($nonce . $ct . $tag);
    return ai_open($sealed, $box) === $plain ? $sealed : null; // never store anything that does not open again
}

/** The plain key from ai_seal(), or null (missing, wrong key, changed). */
function ai_open(?string $sealed, ?string $box): ?string
{
    if ($sealed === null || strncmp($sealed, 'cpk1:', 5) !== 0 || $box === null || strlen($box) !== 32) {
        return null;
    }
    $raw = base64_decode(substr($sealed, 5), true);
    if ($raw === false || strlen($raw) < 12 + 16 + 1) {
        return null;
    }
    $pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $box, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), 'cp-ai-key');
    return $pt === false ? null : $pt;
}

/** The key that protects the provider key inside one business's licence answer (the hotel derives the same one). */
function ai_transport_key(string $licenceKey, string $tenantSlug): string
{
    return hash_hkdf('sha256', $licenceKey, 32, 'cp-ai-deploy-v1|' . strtolower(trim($tenantSlug)));
}

/** "cpai1:" + base64(nonce . ciphertext . tag), bound to the command id. Null on failure. */
function ai_transport_seal(string $plain, string $licenceKey, string $tenantSlug, int $commandId): ?string
{
    if ($plain === '' || $licenceKey === '' || $commandId <= 0) {
        return null;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', ai_transport_key($licenceKey, $tenantSlug), OPENSSL_RAW_DATA, $nonce, $tag, 'ai_setup:' . $commandId, 16);
    return ($ct === false || strlen($tag) !== 16) ? null : 'cpai1:' . base64_encode($nonce . $ct . $tag);
}

/** The hotel's side of ai_transport_seal (kept here for the tests; includes/licence-ai.php in the hotels does the same). */
function ai_transport_open(string $blob, string $licenceKey, string $tenantSlug, int $commandId): ?string
{
    if (strncmp($blob, 'cpai1:', 6) !== 0) {
        return null;
    }
    $raw = base64_decode(substr($blob, 6), true);
    if ($raw === false || strlen($raw) < 12 + 16 + 1) {
        return null;
    }
    $pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', ai_transport_key($licenceKey, $tenantSlug), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, -16), 'ai_setup:' . $commandId);
    return $pt === false ? null : $pt;
}

/** What a test proves: this provider, this key, these models. Keyed with the panel's own secret, so it says nothing about the key. */
function ai_fingerprint(string $provider, string $plainKey, array $models, string $box): string
{
    $m = [];
    foreach (array_keys(AI_ROLES) as $r) {
        $m[] = (string)($models[$r] ?? '');
    }
    return hash_hmac('sha256', json_encode([$provider, $plainKey, $m]), $box . 'fingerprint');
}

/** Remove the key, and anything shaped like a key, from text that may be shown or stored (the hotel's rule). */
function ai_scrub(string $text, string $key = ''): string
{
    if ($key !== '') {
        $text = str_replace($key, '[key]', $text);
        if (strlen($key) > 12) {
            $text = str_replace([substr($key, 0, 12), substr($key, -8)], '[key]', $text);
        }
    }
    $text = (string)preg_replace('/\b(sk-[A-Za-z0-9_\-*]{6,}|xai-[A-Za-z0-9_\-*]{6,}|AIza[0-9A-Za-z_\-]{10,}|[A-Za-z0-9_\-]{40,})/', '[key]', $text);
    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? ''), 0, 240);
}

// ---------------------------------------------------------------------------------------------------- pure: settings

/** Models chosen per role: each must be one the provider listed (when a list is given) and no model may hold two roles. */
function ai_clean_models(array $in, ?array $listedIds): array
{
    $out = [];
    $err = '';
    foreach (array_keys(AI_ROLES) as $r) {
        $v = isset($in[$r]) && is_string($in[$r]) ? trim($in[$r]) : '';
        if ($v !== '' && !preg_match('/^[A-Za-z0-9._:\/@-]{1,120}$/D', $v)) {
            $err = 'A model name has characters no provider uses.';
            $v = '';
        }
        if ($v !== '' && $listedIds !== null && !in_array($v, $listedIds, true)) {
            $err = 'The ' . AI_ROLES[$r] . ' model is not in the list the provider gave at the last test.';
            $v = '';
        }
        $out[$r] = $v;
    }
    $used = array_filter($out, static fn($v) => $v !== '');
    if (count($used) !== count(array_unique($used))) {
        $err = 'Each model can hold one role only (the hotel keeps one role per model). Choose different models, or leave Fast / Fallback empty.';
    }
    if ($out['primary'] === '' && ($out['fast'] !== '' || $out['fallback'] !== '')) {
        $err = 'Choose a Primary model first.';
    }
    return [$err, $out];
}

/** Subscription fields. @return array{0:string,1:array} [error, clean] */
function ai_validate_subscription(array $in, array $planIds): array
{
    $payer = (string)($in['key_payer'] ?? 'msp');
    if (!isset(AI_PAYERS[$payer])) {
        return ['Choose who pays the AI provider.', []];
    }
    $budget = trim((string)($in['monthly_budget_usd'] ?? ''));
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $budget) || (float)$budget > 10000) {
        return ['The monthly aim is US dollars, 0 to 10000 (for example 5 or 12.50).', []];
    }
    $plan = trim((string)($in['plan_item_id'] ?? ''));
    $planId = null;
    if ($plan !== '') {
        if (!ctype_digit($plan) || !in_array((int)$plan, $planIds, true)) {
            return ['Choose a plan from the Catalog list, or none.', []];
        }
        $planId = (int)$plan;
    }
    $notes = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($in['notes'] ?? '')) ?? '');
    if (mb_strlen($notes) > 255) {
        return ['Notes are at most 255 characters.', []];
    }
    return ['', ['key_payer' => $payer, 'monthly_budget_usd' => number_format((float)$budget, 2, '.', ''), 'plan_item_id' => $planId, 'notes' => $notes]];
}

/** Can this configuration be sent now? '' = yes, else the reason in plain words. $now is a unix time. */
function ai_deploy_problem(?array $cfg, ?string $plainKey, string $currentHash, bool $switchOn, bool $licensed, int $now): string
{
    if ($cfg === null || ($cfg['provider'] ?? '') === '') {
        return 'Choose a provider and save its key first.';
    }
    if ($plainKey === null || $plainKey === '') {
        return 'No readable key is saved for this business. Paste the key again.';
    }
    if (($cfg['model_primary'] ?? '') === '') {
        return 'Choose a Primary model, then Save and test.';
    }
    if ($cfg['test_ok'] === null || $cfg['test_hash'] === null) {
        return 'Test first: click Save and test.';
    }
    if ((int)$cfg['test_ok'] !== 1) {
        return 'The last test did not pass. Fix what it says, then Save and test again.';
    }
    if (!hash_equals((string)$cfg['test_hash'], $currentHash)) {
        return 'The key or the models changed after the last test. Save and test again.';
    }
    if (empty($cfg['test_at']) || $now - (int)strtotime((string)$cfg['test_at']) > AI_TEST_VALID_SECONDS) {
        return 'The last test was more than a day ago. Save and test again.';
    }
    if ($switchOn && !$licensed) {
        return 'The Assistant add-on is not in this business\'s licence, so the hotel cannot switch it on. Start the subscription above first, '
            . 'or untick "Switch the Assistant on".';
    }
    return '';
}

/** The command data as it is queued (the sealed key is wiped once the command is finished). */
function ai_deploy_data(array $cfg, bool $switchOn): array
{
    return [
        'provider' => (string)$cfg['provider'],
        'key_sealed' => (string)$cfg['key_sealed'],
        'key_last4' => (string)$cfg['key_last4'],
        'models' => ['primary' => (string)$cfg['model_primary'], 'fast' => (string)$cfg['model_fast'], 'fallback' => (string)$cfg['model_fallback']],
        'budget_usd' => (float)$cfg['monthly_budget_usd'],
        'switch_on' => $switchOn,
    ];
}

function ai_deploy_summary(array $d): string
{
    $parts = [];
    foreach (AI_ROLES as $r => $label) {
        if (($d['models'][$r] ?? '') !== '') {
            $parts[] = $label . ' ' . $d['models'][$r];
        }
    }
    return 'Assistant set-up: ' . ai_provider_label((string)$d['provider']) . ' key ' . "\u{2022}\u{2022}\u{2022}\u{2022}" . $d['key_last4'] . ', '
        . implode(', ', $parts) . ', aim US$' . rtrim(rtrim(number_format((float)$d['budget_usd'], 2, '.', ''), '0'), '.') . ' a month'
        . (!empty($d['switch_on']) ? ', switch it on' : '');
}

/**
 * The command as it travels in ONE business's signed licence answer: the sealed key is opened and encrypted again for that
 * business (ai_transport_seal). Null when it cannot be sent (no readable key): the caller marks it failed.
 */
function ai_command_payload(array $c, string $licenceKey, string $tenantSlug, ?string $box): ?array
{
    $data = json_decode((string)($c['data'] ?? ''), true);
    if (!is_array($data)) {
        return null;
    }
    $plain = ai_open(isset($data['key_sealed']) ? (string)$data['key_sealed'] : null, $box);
    $enc = $plain !== null ? ai_transport_seal($plain, $licenceKey, $tenantSlug, (int)$c['id']) : null;
    if ($enc === null) {
        return null;
    }
    unset($data['key_sealed']);
    $data['key_enc'] = $enc;
    return ['id' => (int)$c['id'], 'kind' => AI_KIND, 'username' => '', 'user_id' => 0, 'data' => $data];
}

/** The hotel's report about its Assistant, kept to known fields. Null when it is not a report. */
function ai_clean_state($in): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $s = static fn($v, int $max): string => is_string($v) || is_int($v) || is_float($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v) ?? ''), 0, $max) : '';
    $num = static fn($v): float => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? max(0.0, (float)$v) : 0.0;
    $out = [
        'ready' => !empty($in['ready']),
        'enabled' => !empty($in['enabled']),
        'off_reason' => $s($in['off_reason'] ?? '', 240),
        'cloud_on' => !array_key_exists('cloud_on', $in) || !empty($in['cloud_on']),
        'provider' => preg_match('/^[a-z]{1,20}$/', (string)($in['provider'] ?? '')) ? (string)$in['provider'] : '',
        'key_last4' => $s($in['key_last4'] ?? '', 8),
        'key_status' => preg_match('/^[a-z]{1,20}$/', (string)($in['key_status'] ?? '')) ? (string)$in['key_status'] : '',
        'last_test_at' => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', (string)($in['last_test_at'] ?? '')) ? (string)$in['last_test_at'] : '',
        'last_test_message' => ai_scrub($s($in['last_test_message'] ?? '', 240)),
        'budget_usd' => round($num($in['budget_usd'] ?? 0), 2),
        'models' => [],
        'usage' => null,
        'applied_id' => is_int($in['applied_id'] ?? null) ? max(0, $in['applied_id']) : 0,
    ];
    foreach (is_array($in['models'] ?? null) ? array_slice($in['models'], 0, 10) : [] as $m) {
        $role = is_array($m) ? (string)($m['role'] ?? '') : '';
        $id = is_array($m) ? $s($m['model_id'] ?? '', 120) : '';
        if ($id !== '' && preg_match('/^[a-z]{1,10}$/', $role)) {
            $out['models'][] = ['model_id' => $id, 'role' => $role, 'provider' => preg_match('/^[a-z]{1,20}$/', (string)($m['provider'] ?? '')) ? (string)$m['provider'] : ''];
        }
    }
    if (is_array($in['usage'] ?? null)) {
        $u = $in['usage'];
        $out['usage'] = [
            'month' => preg_match('/^\d{4}-\d{2}$/', (string)($u['month'] ?? '')) ? (string)$u['month'] : '',
            'requests' => (int)$num($u['requests'] ?? 0),
            'tokens_in' => (int)$num($u['tokens_in'] ?? 0),
            'tokens_out' => (int)$num($u['tokens_out'] ?? 0),
            'cost_usd' => round($num($u['cost_usd'] ?? 0), 4),
        ];
    }
    return $out;
}

/** The model the hotel uses for one role, '' when none. */
function ai_state_model(array $state, string $role): string
{
    foreach ($state['models'] ?? [] as $m) {
        if (($m['role'] ?? '') === $role) {
            return (string)$m['model_id'];
        }
    }
    return '';
}

/**
 * Where the hotel now differs from what the panel last sent and the hotel confirmed (its own admins may change it).
 * @return string[] plain sentences, [] when it matches
 */
function ai_differences(array $deployed, array $state): array
{
    $out = [];
    if (!$state['ready']) {
        return ['The hotel\'s Assistant is not installed yet (its database update has not run).'];
    }
    if ($state['provider'] !== ($deployed['provider'] ?? '')) {
        $out[] = 'Provider in use at the hotel: ' . ($state['provider'] !== '' ? ai_provider_label($state['provider']) : 'none') . ' (sent: ' . ai_provider_label((string)($deployed['provider'] ?? '')) . ').';
    } elseif ($state['key_last4'] !== '' && $state['key_last4'] !== (string)($deployed['key_last4'] ?? '')) {
        $out[] = 'The hotel uses a different key (ending ' . $state['key_last4'] . '; sent: ending ' . ($deployed['key_last4'] ?? '') . ').';
    }
    foreach (AI_ROLES as $r => $label) {
        $want = (string)($deployed['models'][$r] ?? '');
        $have = ai_state_model($state, $r);
        if ($want !== $have) {
            $out[] = $label . ' model at the hotel: ' . ($have !== '' ? $have : 'none') . ' (sent: ' . ($want !== '' ? $want : 'none') . ').';
        }
    }
    if (abs($state['budget_usd'] - (float)($deployed['budget_usd'] ?? 0)) >= 0.01) {
        $out[] = 'Monthly aim at the hotel: US$' . $state['budget_usd'] . ' (sent: US$' . (float)($deployed['budget_usd'] ?? 0) . ').';
    }
    if (!empty($deployed['switch_on']) && !$state['enabled']) {
        $out[] = 'The Assistant is off at the hotel' . ($state['off_reason'] !== '' ? ': ' . $state['off_reason'] : '.');
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------- provider calls

/** Replace the HTTP layer (tests): fn(method, url, headers, body, timeout) => ['http' => int, 'body' => string, 'error' => string]. */
function ai_set_transport(?callable $fn): void
{
    $GLOBALS['__cp_ai_transport'] = $fn;
}

/** @return array{http:int,body:string,error:string,ms:int} */
function ai_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): array
{
    $t0 = microtime(true);
    $fake = $GLOBALS['__cp_ai_transport'] ?? null;
    if (is_callable($fake)) {
        $r = (array)$fake($method, $url, $headers, $body, $timeout);
        return ['http' => (int)($r['http'] ?? 0), 'body' => (string)($r['body'] ?? ''), 'error' => (string)($r['error'] ?? ''), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'body' => '', 'error' => 'This server cannot make web requests (PHP cURL is missing).', 'ms' => 0];
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => max(5, $timeout), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'LicencePanel-AI-Test/1.0',
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $resp === false ? (string)curl_error($ch) : '';
    curl_close($ch);
    return ['http' => $http, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/** Base address and headers per provider. The key travels in a header only, never in a URL. */
function ai_provider_request(string $provider, string $key): array
{
    switch ($provider) {
        case 'anthropic':
            return ['base' => 'https://api.anthropic.com/v1', 'headers' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']];
        case 'openai':
            return ['base' => 'https://api.openai.com/v1', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        case 'google':
            return ['base' => 'https://generativelanguage.googleapis.com/v1beta', 'headers' => ['x-goog-api-key: ' . $key, 'Content-Type: application/json']];
        case 'xai':
            return ['base' => 'https://api.x.ai/v1', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        case 'deepseek':
            return ['base' => 'https://api.deepseek.com', 'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
    }
    throw new InvalidArgumentException('Unknown provider.');
}

/** A plain sentence for a failed provider call (scrubbed). */
function ai_http_problem(array $r, string $key): string
{
    if ($r['http'] === 0) {
        return 'Could not reach the provider' . ($r['error'] !== '' ? ' (' . ai_scrub($r['error'], $key) . ')' : '') . '.';
    }
    $msg = '';
    $j = json_decode($r['body'], true);
    if (is_array($j)) {
        $e = $j['error'] ?? null;
        if (is_array($e)) {
            $msg = (string)($e['message'] ?? '');
        } elseif (is_string($e)) {
            $msg = $e;
        } elseif (isset($j['message']) && is_string($j['message'])) {
            $msg = $j['message'];
        }
    }
    $lead = [
        400 => 'The provider refused the request',
        401 => 'The key was refused - check it was copied in full',
        403 => 'This key is not allowed to do that',
        404 => 'The provider did not recognise the request',
        429 => 'The provider says the limit or credit for this key is used up',
    ][$r['http']] ?? 'The provider answered with an error';
    return $lead . ' (HTTP ' . $r['http'] . ')' . ($msg !== '' ? ': ' . ai_scrub($msg, $key) : '.');
}

/** Ids of models that are not for chat (embeddings, speech, images, moderation, realtime...): the hotel's own rule. */
function ai_is_chat_model(string $id): bool
{
    return !preg_match('/(embed|tts|whisper|dall-e|gpt-image|imagen|image|audio|moderation|realtime|transcribe|speech|veo|\baqa\b|babbage|davinci|sora|\blive\b|robotics|lyria|nano-banana|customtools|\bomni\b)/i', $id);
}

/** A provider's model list as [['model_id', 'label']], chat models only. */
function ai_normalise_models(string $provider, array $json): array
{
    $out = [];
    if ($provider === 'google') {
        foreach ((array)($json['models'] ?? []) as $m) {
            if (!is_array($m) || !in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) {
                continue;
            }
            $id = (string)preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            if ($id !== '' && ai_is_chat_model($id)) {
                $out[] = ['model_id' => $id, 'label' => (string)($m['displayName'] ?? $id)];
            }
        }
        return $out;
    }
    foreach ((array)($json['data'] ?? []) as $m) {
        $id = is_array($m) ? (string)($m['id'] ?? '') : '';
        if ($id !== '' && ai_is_chat_model($id)) {
            $out[] = ['model_id' => $id, 'label' => $provider === 'anthropic' ? (string)($m['display_name'] ?? $id) : $id];
        }
    }
    return $out;
}

/** @return array{ok:bool,models:array,message:string,ms:int} */
function ai_list_models(string $provider, string $key): array
{
    if (!isset(ai_providers()[$provider])) {
        return ['ok' => false, 'models' => [], 'message' => 'Unknown provider.', 'ms' => 0];
    }
    $req = ai_provider_request($provider, $key);
    $models = [];
    $ms = 0;
    $cursor = '';
    for ($page = 0; $page < 5; $page++) {
        if ($provider === 'anthropic') {
            $url = $req['base'] . '/models?limit=1000' . ($cursor !== '' ? '&after_id=' . rawurlencode($cursor) : '');
        } elseif ($provider === 'google') {
            $url = $req['base'] . '/models?pageSize=1000' . ($cursor !== '' ? '&pageToken=' . rawurlencode($cursor) : '');
        } else {
            $url = $req['base'] . '/models';
        }
        $r = ai_http('GET', $url, $req['headers'], null, 20);
        $ms += $r['ms'];
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'models' => [], 'message' => ai_http_problem($r, $key), 'ms' => $ms];
        }
        $json = json_decode($r['body'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'models' => [], 'message' => 'The provider sent an answer that could not be read.', 'ms' => $ms];
        }
        $models = array_merge($models, ai_normalise_models($provider, $json));
        $cursor = '';
        if ($provider === 'anthropic' && !empty($json['has_more']) && !empty($json['last_id'])) {
            $cursor = (string)$json['last_id'];
        } elseif ($provider === 'google' && !empty($json['nextPageToken'])) {
            $cursor = (string)$json['nextPageToken'];
        }
        if ($cursor === '') {
            break;
        }
    }
    $seen = [];
    $models = array_values(array_filter($models, static function ($m) use (&$seen) {
        if (isset($seen[$m['model_id']])) {
            return false;
        }
        return $seen[$m['model_id']] = true;
    }));
    usort($models, static fn($a, $b) => strcmp($a['model_id'], $b['model_id']));
    return ['ok' => true, 'models' => array_slice($models, 0, AI_MAX_MODELS_KEPT), 'message' => '', 'ms' => $ms];
}

/** Send "Reply OK" (at most 5 tokens; 16 on Google) to one model. @return array{ok:bool,ms:int,message:string,reply:string} */
function ai_ping(string $provider, string $key, string $modelId): array
{
    $req = ai_provider_request($provider, $key);
    $prompt = 'Reply OK';
    switch ($provider) {
        case 'anthropic':
            $url = $req['base'] . '/messages';
            $body = ['model' => $modelId, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
            break;
        case 'google':
            $url = $req['base'] . '/models/' . rawurlencode($modelId) . ':generateContent';
            $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => ['maxOutputTokens' => 16]];
            break;
        case 'openai':
            $url = $req['base'] . '/chat/completions';
            $body = ['model' => $modelId, 'max_completion_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
            break;
        default: // xai, deepseek
            $url = $req['base'] . '/chat/completions';
            $body = ['model' => $modelId, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => $prompt]]];
    }
    $r = ai_http('POST', $url, $req['headers'], (string)json_encode($body), 30);
    if ($r['http'] < 200 || $r['http'] >= 300) {
        return ['ok' => false, 'ms' => $r['ms'], 'message' => ai_http_problem($r, $key), 'reply' => ''];
    }
    $j = json_decode($r['body'], true);
    $reply = '';
    if (is_array($j)) {
        if ($provider === 'anthropic') {
            $reply = (string)($j['content'][0]['text'] ?? '');
        } elseif ($provider === 'google') {
            $reply = (string)($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
        } else {
            $reply = (string)($j['choices'][0]['message']['content'] ?? '');
        }
    }
    return ['ok' => true, 'ms' => $r['ms'], 'message' => '', 'reply' => ai_scrub($reply, $key)];
}

/**
 * The panel's test: list the provider's chat models with this key, then ping every chosen model.
 * @return array{ok:bool,listed:array,checks:array,message:string}
 */
function ai_run_test(string $provider, string $key, array $models): array
{
    $out = ['ok' => false, 'listed' => [], 'checks' => [], 'message' => ''];
    $list = ai_list_models($provider, $key);
    if (!$list['ok']) {
        $out['message'] = $list['message'];
        return $out;
    }
    $out['listed'] = $list['models'];
    $ids = array_column($list['models'], 'model_id');
    $n = count($ids);
    if ($n === 0) {
        $out['message'] = 'The key works, but the provider offers no chat models to it.';
        return $out;
    }
    if (($models['primary'] ?? '') === '') {
        $out['message'] = 'The key works: ' . $n . ' chat model' . ($n === 1 ? '' : 's') . ' listed. Now choose the models (at least a Primary) and click Save and test again.';
        return $out;
    }
    $all = true;
    foreach (AI_ROLES as $r => $label) {
        $m = (string)($models[$r] ?? '');
        if ($m === '') {
            continue;
        }
        if (!in_array($m, $ids, true)) {
            $out['checks'][] = ['role' => $r, 'model' => $m, 'ok' => false, 'ms' => 0, 'message' => 'Not offered to this key (any more).'];
            $all = false;
            continue;
        }
        $p = ai_ping($provider, $key, $m);
        $out['checks'][] = ['role' => $r, 'model' => $m, 'ok' => $p['ok'], 'ms' => $p['ms'],
            'message' => $p['ok'] ? 'Answered in ' . $p['ms'] . ' ms.' : $p['message']];
        $all = $all && $p['ok'];
    }
    $out['ok'] = $all;
    $out['message'] = $all ? 'Passed: the key works (' . $n . ' chat models listed) and every chosen model answered.'
        : 'Not passed: a chosen model did not answer (see each model below).';
    return $out;
}

// ---------------------------------------------------------------------------------------------------- database

function ai_config(PDO $pdo, int $tenantId): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_ai_config WHERE tenant_id = ?');
    $st->execute([$tenantId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Make sure the row exists (defaults). */
function ai_config_ensure(PDO $pdo, int $tenantId): void
{
    $pdo->prepare('INSERT IGNORE INTO cp_ai_config (tenant_id) VALUES (?)')->execute([$tenantId]);
}

/** The models listed at the last test, for this provider only. @return array<int,array{model_id:string,label:string}>|null */
function ai_listed_models(?array $cfg): ?array
{
    if ($cfg === null || empty($cfg['models_json'])) {
        return null;
    }
    $d = json_decode((string)$cfg['models_json'], true);
    return is_array($d) && ($d['provider'] ?? '') === ($cfg['provider'] ?? '') && is_array($d['models'] ?? null) ? $d['models'] : null;
}

/** For the audit log: never the key, never its sealed form. */
function ai_audit_view(?array $cfg): ?array
{
    if ($cfg === null) {
        return null;
    }
    return ['provider' => $cfg['provider'], 'key_last4' => $cfg['key_last4'], 'key_payer' => $cfg['key_payer'], 'model_primary' => $cfg['model_primary'],
        'model_fast' => $cfg['model_fast'], 'model_fallback' => $cfg['model_fallback'], 'monthly_budget_usd' => $cfg['monthly_budget_usd'],
        'plan_item_id' => $cfg['plan_item_id'], 'notes' => $cfg['notes']];
}

function ai_save_subscription(PDO $pdo, int $tenantId, array $clean, int $adminId): void
{
    ai_config_ensure($pdo, $tenantId);
    $before = ai_audit_view(ai_config($pdo, $tenantId));
    $pdo->prepare('UPDATE cp_ai_config SET key_payer = ?, monthly_budget_usd = ?, plan_item_id = ?, notes = ?, updated_by = ? WHERE tenant_id = ?')
        ->execute([$clean['key_payer'], $clean['monthly_budget_usd'], $clean['plan_item_id'], $clean['notes'] !== '' ? $clean['notes'] : null, $adminId, $tenantId]);
    audit_log($pdo, $adminId, 'ai_subscription_saved', $tenantId, $before, ai_audit_view(ai_config($pdo, $tenantId)));
}

/**
 * Save the provider, a new key (optional: '' keeps the saved one) and the models. Anything that changes what a test proved
 * sets the test back to "not tested". @return string '' or the problem in plain words
 */
function ai_save_setup(PDO $pdo, int $tenantId, string $provider, string $newKey, array $modelsIn, int $adminId): string
{
    if (!isset(ai_providers()[$provider])) {
        return 'Choose a provider.';
    }
    ai_config_ensure($pdo, $tenantId);
    $cfg = ai_config($pdo, $tenantId);
    $newKey = trim($newKey);
    $sealed = $cfg['key_sealed'] ?? null;
    $last4 = $cfg['key_last4'] ?? null;
    if ($newKey !== '') {
        if (!ai_key_looks_valid($newKey)) {
            return 'That key does not look right (no spaces; 20 to 300 characters). Copy it again from the provider.';
        }
        $sealed = ai_seal($newKey, ai_box_key());
        if ($sealed === null) {
            return 'The panel cannot store keys safely right now (its signing key is missing). See Signing key.';
        }
        $last4 = substr($newKey, -4);
    } elseif ($provider !== ($cfg['provider'] ?? '') && ($cfg['provider'] ?? '') !== '') {
        return 'The provider changed: paste the key for ' . ai_provider_label($provider) . '.';
    } elseif ($sealed === null) {
        return 'Paste the key first.';
    }
    $sameProvider = $provider === ($cfg['provider'] ?? '');
    [$err, $models] = ai_clean_models($modelsIn, $sameProvider && $newKey === '' ? array_column(ai_listed_models($cfg) ?? [], 'model_id') ?: null : null);
    if ($err !== '') {
        return $err;
    }
    if (!$sameProvider) {
        $models = ['primary' => '', 'fast' => '', 'fallback' => '']; // another provider's model names never carry over
    }
    $before = ai_audit_view($cfg);
    $pdo->prepare('UPDATE cp_ai_config SET provider = ?, key_sealed = ?, key_last4 = ?, model_primary = ?, model_fast = ?, model_fallback = ?,
                   models_json = IF(? = 1, models_json, NULL), test_ok = NULL, test_hash = NULL, test_json = NULL, updated_by = ? WHERE tenant_id = ?')
        ->execute([$provider, $sealed, $last4, $models['primary'], $models['fast'], $models['fallback'], $sameProvider ? 1 : 0, $adminId, $tenantId]);
    $after = ai_audit_view(ai_config($pdo, $tenantId));
    if ($newKey !== '') {
        $after['key_replaced'] = true;
    }
    audit_log($pdo, $adminId, 'ai_setup_saved', $tenantId, $before, $after);
    return '';
}

/** Run the panel's test on what is saved now and record it. @return array the ai_run_test() result */
function ai_test_saved(PDO $pdo, int $tenantId, int $adminId): array
{
    $cfg = ai_config($pdo, $tenantId);
    $box = ai_box_key();
    $key = $cfg ? ai_open($cfg['key_sealed'] ?? null, $box) : null;
    if ($cfg === null || $key === null || $box === null) {
        return ['ok' => false, 'listed' => [], 'checks' => [], 'message' => 'No readable key is saved for this business. Paste the key again.'];
    }
    $models = ['primary' => (string)$cfg['model_primary'], 'fast' => (string)$cfg['model_fast'], 'fallback' => (string)$cfg['model_fallback']];
    $r = ai_run_test((string)$cfg['provider'], $key, $models);
    $hash = ai_fingerprint((string)$cfg['provider'], $key, $models, $box);
    $key = '';
    $listed = $r['listed'] ? json_encode(['provider' => $cfg['provider'], 'models' => $r['listed']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    // test_at on PHP's clock: ai_deploy_problem() compares it with PHP's time() (the database clock may differ).
    $pdo->prepare('UPDATE cp_ai_config SET test_ok = ?, test_hash = ?, test_at = ?, test_json = ?,
                   models_json = COALESCE(?, models_json), models_at = IF(? IS NULL, models_at, NOW()) WHERE tenant_id = ?')
        ->execute([$r['ok'] ? 1 : 0, $hash, date('Y-m-d H:i:s'), json_encode(['message' => $r['message'], 'checks' => $r['checks']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $listed, $listed, $tenantId]);
    audit_log($pdo, $adminId, 'ai_tested', $tenantId, null, ['provider' => $cfg['provider'], 'ok' => $r['ok'],
        'checks' => array_map(static fn($c) => $c['role'] . ':' . $c['model'] . ':' . ($c['ok'] ? 'ok' : 'failed'), $r['checks'])]);
    return $r;
}

/** The fingerprint of what is saved now ('' when the key cannot be read). */
function ai_current_hash(?array $cfg): string
{
    $box = ai_box_key();
    $key = $cfg ? ai_open($cfg['key_sealed'] ?? null, $box) : null;
    if ($key === null || $box === null) {
        return '';
    }
    return ai_fingerprint((string)$cfg['provider'], $key, ['primary' => (string)$cfg['model_primary'], 'fast' => (string)$cfg['model_fast'],
        'fallback' => (string)$cfg['model_fallback']], $box);
}

function ai_forget_key(PDO $pdo, int $tenantId, int $adminId): void
{
    $before = ai_audit_view(ai_config($pdo, $tenantId));
    $pdo->prepare('UPDATE cp_ai_config SET key_sealed = NULL, key_last4 = NULL, test_ok = NULL, test_hash = NULL, updated_by = ? WHERE tenant_id = ?')
        ->execute([$adminId, $tenantId]);
    audit_log($pdo, $adminId, 'ai_key_forgotten', $tenantId, $before, ai_audit_view(ai_config($pdo, $tenantId)));
}

function ai_licensed(array $tenant): bool
{
    return in_array('ai_assistant', catalog_tenant_modules($tenant), true);
}

/** Start or stop the Assistant add-on in the business's licence (the module list the hotel enforces). */
function ai_set_licensed(PDO $pdo, int $tenantId, bool $on, int $adminId): void
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $before = tenant_find($pdo, $tenantId, true);
        if (!$before) {
            throw new RuntimeException('Business not found.');
        }
        $mods = array_values(array_diff(catalog_tenant_modules($before), ['ai_assistant']));
        if (!$on && array_values(array_diff($mods, ['finance'])) === []) {
            // The business form's rule: a licence with nothing but Finance would switch every other part of the HMS off.
            throw new RuntimeException('The Assistant is the only module besides Finance in this licence, so it cannot be taken out here. '
                . 'Change the modules on the business page instead.');
        }
        if ($on) {
            $mods[] = 'ai_assistant';
        }
        $mods = catalog_clean_modules($mods);
        $pdo->prepare('UPDATE cp_tenants SET modules = ? WHERE id = ?')->execute([json_encode($mods), $tenantId]);
        audit_log($pdo, $adminId, $on ? 'ai_subscription_started' : 'ai_subscription_stopped', $tenantId, ['modules' => catalog_tenant_modules($before)], ['modules' => $mods]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Queue the tested set-up for the hotel. @return array{0:string,1:int} [problem, command id] */
function ai_deploy(PDO $pdo, array $tenant, bool $switchOn, int $adminId, ?int $now = null): array
{
    $tid = (int)$tenant['id'];
    $cfg = ai_config($pdo, $tid);
    $key = $cfg ? ai_open($cfg['key_sealed'] ?? null, ai_box_key()) : null;
    $problem = ai_deploy_problem($cfg, $key, ai_current_hash($cfg), $switchOn, ai_licensed($tenant), $now ?? time());
    $key = '';
    if ($problem !== '') {
        return [$problem, 0];
    }
    $data = ai_deploy_data($cfg, $switchOn);
    $summary = ai_deploy_summary($data);
    // Only the newest set-up matters: an older one still waiting is cancelled (and its sealed key wiped).
    $pdo->prepare("UPDATE cp_hms_commands SET done_at = NOW(), result = 'cancelled' WHERE tenant_id = ? AND kind = ? AND done_at IS NULL")->execute([$tid, AI_KIND]);
    $pdo->prepare('INSERT INTO cp_hms_commands (tenant_id, kind, username, pms_user_id, data, summary, created_by) VALUES (?, ?, \'\', NULL, ?, ?, ?)')
        ->execute([$tid, AI_KIND, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), mb_substr($summary, 0, 255), $adminId]);
    $id = (int)$pdo->lastInsertId();
    unset($data['key_sealed']);
    $pdo->prepare('UPDATE cp_ai_config SET deploy_command_id = ?, deployed_json = ? WHERE tenant_id = ?')
        ->execute([$id, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tid]);
    ai_wipe_finished($pdo, $tid);
    audit_log($pdo, $adminId, 'ai_deploy_queued', $tid, null, ['command' => $id, 'summary' => $summary]);
    return ['', $id];
}

/** Wipe the sealed key from every finished set-up command of this business (done, refused, cancelled or lapsed). */
function ai_wipe_finished(PDO $pdo, int $tenantId): void
{
    $st = $pdo->prepare("SELECT id, data FROM cp_hms_commands WHERE tenant_id = ? AND kind = ? AND done_at IS NOT NULL AND data LIKE '%key_sealed%'");
    $st->execute([$tenantId, AI_KIND]);
    $up = $pdo->prepare('UPDATE cp_hms_commands SET data = ? WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true);
        if (is_array($d)) {
            unset($d['key_sealed']);
            $up->execute([json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$r['id']]);
        }
    }
}

/** The hotel's report about its Assistant (already cleaned). */
function ai_store_state(PDO $pdo, int $tenantId, array $state): void
{
    ai_config_ensure($pdo, $tenantId);
    $pdo->prepare('UPDATE cp_ai_config SET hms_state = ?, hms_state_at = NOW() WHERE tenant_id = ?')
        ->execute([json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId]);
}

function ai_state(?array $cfg): ?array
{
    $raw = $cfg ? (string)($cfg['hms_state'] ?? '') : '';
    $d = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($d) ? ai_clean_state($d) : null;
}

/** The set-up commands sent to this business, newest first. */
function ai_commands(PDO $pdo, int $tenantId, int $limit = 10): array
{
    $st = $pdo->prepare('SELECT * FROM cp_hms_commands WHERE tenant_id = ? AND kind = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
    $st->execute([$tenantId, AI_KIND]);
    return $st->fetchAll();
}
