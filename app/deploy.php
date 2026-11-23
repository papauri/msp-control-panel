<?php
// Deployments (owner 2026-11-23): see every commit pushed to GitHub, which one each live copy runs, and ask a live copy
// to run cPanel "Update from Remote".
//
// No cPanel credentials (owner's choice 2026-11-23: "no credentials: cron"). The button only RECORDS a request (a new,
// rising request id). A cron job on each server picks it up and runs the pull itself with git (git pull --ff-only of the
// branch already checked out; cPanel's uapi cannot run from cron on this host: CageFS):
//   - this panel: scripts/deploy-check.php --want / --report (the cron line is in the Guide, section 18, and README);
//   - a business: the request travels in its SIGNED licence answer (deploy_requested), and it reports back in its next
//     check-in (deploy: seen_id, done_id, ok, msg, sha). The hotel side is specified in .claude/DEPLOY_HOTEL_SPEC.md.
// cPanel's "Update from Remote" is a fast-forward-only pull (docs.cpanel.net, Git Version Control); the cron line does the
// same with git pull --ff-only origin BRANCH and writes git's output plus "exit=N" (read by deploy_uapi_result).
// Neither repository has a .cpanel.yml, so there is no "Deploy HEAD" step: the pull is the deploy.
// A forged request could only make a site pull its own branch; still, every report is checked: ids must be newer than the
// last handled one and never above the newest request, and results are shown as reported, never trusted for anything else.
//
// GitHub (read only): GET /repos/{owner}/{repo}/commits?sha={branch}&per_page=100 with one panel-wide fine-grained token
// (Contents + Metadata: read), SEALED at rest (AES-256-GCM under keys_derive('cp-deploy-v1'); only its last 4 characters
// are clear), sent only in an Authorization header over verified HTTPS, scrubbed from every error. The list is cached for
// 5 minutes under storage/ (outside public/). The HTTP layer is injectable (deploy_set_transport) for the tests.
// The panel never pushes code anywhere.

const DEPLOY_CACHE_SECONDS = 300;
const DEPLOY_GITHUB_PER_PAGE = 100;
const DEPLOY_SEAL_PREFIX = 'cpd1:';
const DEPLOY_WAIT_WARN_SECONDS = 1800;   // a request not picked up after 30 minutes: "is the cron job set up?"
const DEPLOY_LAPSE_SECONDS = 86400;      // a request not picked up within 24 hours lapses and is never run late
const DEPLOY_MSG_MAX = 500;

// ---------------------------------------------------------------------------------------------------- pure: secrets

/** The panel's at-rest key for the GitHub token (null when there is no usable signing key). */
function deploy_box_key(): ?string
{
    if (PHP_SAPI === 'cli' && isset($GLOBALS['__cp_deploy_box_test']) && is_string($GLOBALS['__cp_deploy_box_test'])) {
        return $GLOBALS['__cp_deploy_box_test']; // the --db tests on a laptop without the signing key
    }
    return function_exists('keys_derive') ? keys_derive('cp-deploy-v1') : null;
}

/** "cpd1:" + base64(nonce . ciphertext . tag), bound to its purpose. Null when it cannot be sealed. */
function deploy_seal(string $plain, ?string $box, string $purpose): ?string
{
    if ($plain === '' || $box === null || strlen($box) !== 32) {
        return null;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $box, OPENSSL_RAW_DATA, $nonce, $tag, 'cp-deploy:' . $purpose, 16);
    if ($ct === false || strlen($tag) !== 16) {
        return null;
    }
    $sealed = DEPLOY_SEAL_PREFIX . base64_encode($nonce . $ct . $tag);
    return deploy_open($sealed, $box, $purpose) === $plain ? $sealed : null; // never store what does not open again
}

/** The plain value from deploy_seal(), or null (missing, wrong key, wrong purpose, changed). */
function deploy_open(?string $sealed, ?string $box, string $purpose): ?string
{
    if ($sealed === null || strncmp($sealed, DEPLOY_SEAL_PREFIX, 5) !== 0 || $box === null || strlen($box) !== 32) {
        return null;
    }
    $raw = base64_decode(substr($sealed, 5), true);
    if ($raw === false || strlen($raw) < 12 + 16 + 1) {
        return null;
    }
    $pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $box, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), 'cp-deploy:' . $purpose);
    return $pt === false ? null : $pt;
}

/** "••••abcd": what the page shows for a stored token. */
function deploy_hint(string $token): string
{
    return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr($token, -4);
}

/** A GitHub token (fine-grained github_pat_..., or classic ghp_...): letters, digits and underscores. */
function deploy_github_token_ok(string $t): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{20,255}$/D', $t);
}

/**
 * Remove the given secrets, credentials in addresses (scheme://user:pass@ or scheme://token@ -> scheme://***@, as git
 * echoes remote URLs) and anything shaped like a token from text that may be shown, stored or audited.
 */
function deploy_scrub(string $text, array $secrets = [], int $max = 240): string
{
    $text = mb_scrub($text, 'UTF-8');
    foreach ($secrets as $s) {
        $s = (string)$s;
        if (strlen($s) >= 4) {
            $text = str_replace($s, '[token]', $text);
            if (strlen($s) > 12) {
                $text = str_replace([substr($s, 0, 12), substr($s, -8)], '[token]', $text);
            }
        }
    }
    $text = (string)preg_replace('~\b([a-z][a-z0-9+.\-]*://)[^\s/]+@~iu', '$1***@', $text);
    $text = (string)preg_replace('/(github_pat_[A-Za-z0-9_]+|gh[pousr]_[A-Za-z0-9_]{6,}|cpanel\s+[^\s:]+:\S+|Bearer\s+\S+)/i', '[token]', $text);
    $text = (string)preg_replace('/[^\P{C}\n]+|\n+/u', ' ', $text); // control characters (and line breaks) become spaces
    return mb_substr(trim((string)preg_replace('/ {2,}/', ' ', $text)), 0, $max);
}

// ---------------------------------------------------------------------------------------------------- pure: validation

/** owner/name on GitHub. */
function deploy_repo_ok(string $r): bool
{
    if (strlen($r) > 140 || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $r)) {
        return false;
    }
    foreach (explode('/', $r) as $seg) {
        if ($seg === '.' || $seg === '..') {
            return false;
        }
    }
    return true;
}

/** A plain branch name (main, release/2026): no "..", no leading "-" or "/", no trailing "/" or ".lock". */
function deploy_branch_ok(string $b): bool
{
    return strlen($b) <= 100 && (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/-]*$/D', $b)
        && strpos($b, '..') === false && strpos($b, '//') === false && substr($b, -1) !== '/' && substr($b, -5) !== '.lock';
}

/**
 * Cleans the target form. $tenantIds = the businesses that exist.
 * @return array{0:array,1:string[]} [clean, problems]
 */
function deploy_validate_target(array $in, array $tenantIds): array
{
    $g = static fn(string $k): string => isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
    $errs = [];
    $c = [];
    $c['name'] = $g('name');
    if ($c['name'] === '' || mb_strlen($c['name']) > 80) {
        $errs[] = 'Give the live copy a name (up to 80 characters).';
    }
    $t = $g('tenant_id');
    $c['tenant_id'] = null;
    if ($t !== '' && $t !== '0') {
        if (!ctype_digit($t) || !in_array((int)$t, array_map('intval', $tenantIds), true)) {
            $errs[] = 'Choose a business from the list, or "This panel".';
        } else {
            $c['tenant_id'] = (int)$t;
        }
    }
    $c['github_repo'] = $g('github_repo');
    if (!deploy_repo_ok($c['github_repo'])) {
        $errs[] = 'GitHub repository must look like owner/name (for example papauri/msp-control-panel).';
    }
    $c['branch'] = $g('branch') !== '' ? $g('branch') : 'main';
    if (!deploy_branch_ok($c['branch'])) {
        $errs[] = 'Branch must be a plain branch name, like main.';
    }
    return [$c, $errs];
}

// ---------------------------------------------------------------------------------------------------- pure: commits

/**
 * GitHub's commit list as plain rows (newest first).
 * @return array<int,array{sha:string,message:string,author:string,login:string,date:int,url:string}>|null null = not a list
 */
function deploy_parse_commits(string $body): ?array
{
    $j = json_decode($body, true);
    if (!is_array($j) || ($j !== [] && !array_is_list($j))) {
        return null;
    }
    $out = [];
    foreach ($j as $c) {
        if (!is_array($c) || !isset($c['sha']) || !is_string($c['sha']) || !preg_match('/^[0-9a-f]{40}$/D', $c['sha'])) {
            continue;
        }
        $commit = is_array($c['commit'] ?? null) ? $c['commit'] : [];
        $a = is_array($commit['author'] ?? null) ? $commit['author'] : [];
        $ts = isset($a['date']) && is_string($a['date']) ? strtotime($a['date']) : false;
        $url = isset($c['html_url']) && is_string($c['html_url']) && strncmp($c['html_url'], 'https://github.com/', 19) === 0 ? $c['html_url'] : '';
        $out[] = [
            'sha' => $c['sha'],
            'message' => mb_substr(str_replace("\r\n", "\n", is_string($commit['message'] ?? null) ? $commit['message'] : ''), 0, 5000),
            'author' => mb_substr(is_string($a['name'] ?? null) ? $a['name'] : '', 0, 120),
            'login' => is_array($c['author'] ?? null) && is_string($c['author']['login'] ?? null) ? mb_substr($c['author']['login'], 0, 60) : '',
            'date' => $ts === false ? 0 : (int)$ts,
            'url' => $url,
        ];
    }
    return $out;
}

/** The first line of a commit message (the "subject"). */
function deploy_subject(string $message): string
{
    $line = strtok(ltrim($message), "\n");
    return $line === false ? '' : trim($line);
}

/**
 * How far a live copy is behind the branch.
 * state: 'up_to_date' | 'behind' (count) | 'not_found' (the live commit is not in the newest 100: more than 100 behind,
 *        or not on this branch) | 'unknown' (no live commit or no list).
 * @return array{state:string,count:?int}
 */
function deploy_behind(?array $commits, string $liveSha): array
{
    $liveSha = strtolower(trim($liveSha));
    if ($commits === null || $commits === [] || !preg_match('/^[0-9a-f]{7,40}$/D', $liveSha)) {
        return ['state' => 'unknown', 'count' => null];
    }
    foreach (array_values($commits) as $i => $c) {
        if (strncmp((string)$c['sha'], $liveSha, strlen($liveSha)) === 0) {
            return $i === 0 ? ['state' => 'up_to_date', 'count' => 0] : ['state' => 'behind', 'count' => $i];
        }
    }
    return ['state' => 'not_found', 'count' => null];
}

/** Plain words for deploy_behind(). */
function deploy_behind_words(array $b, string $branch): string
{
    switch ($b['state']) {
        case 'up_to_date':
            return 'Up to date with ' . $branch;
        case 'behind':
            return $b['count'] . ' commit' . ($b['count'] === 1 ? '' : 's') . ' behind ' . $branch;
        case 'not_found':
            return 'More than ' . DEPLOY_GITHUB_PER_PAGE . ' commits behind ' . $branch . ', or not on this branch';
    }
    return 'Not known yet';
}

// ---------------------------------------------------------------------------------------------------- pure: requests

/** A DATETIME written by this panel (PHP's clock and time zone) as a unix time; 0 when empty. */
function deploy_ts($dt): int
{
    if (!is_string($dt) || $dt === '') {
        return 0;
    }
    $t = strtotime($dt);
    return $t === false ? 0 : (int)$t;
}

/**
 * Where the newest request stands.
 * state: none | requested | waiting (not picked up after 30 min) | lapsed (not picked up in 24 h) | picked_up | done | failed
 * @return array{state:string,words:string}
 */
function deploy_request_state(array $row, int $now): array
{
    $req = (int)($row['update_request_id'] ?? 0);
    $done = (int)($row['done_id'] ?? 0);
    $seen = (int)($row['picked_up_id'] ?? 0);
    $at = static fn(string $col): string => deploy_ts($row[$col] ?? null) > 0 ? date('Y-m-d H:i', deploy_ts($row[$col])) : '?';
    if ($req <= 0) {
        return ['state' => 'none', 'words' => 'No update asked for yet'];
    }
    if ($done >= $req) {
        return (int)($row['result_ok'] ?? 0) === 1
            ? ['state' => 'done', 'words' => 'Done ' . $at('done_at')]
            : ['state' => 'failed', 'words' => 'Failed ' . $at('done_at')];
    }
    if ($seen >= $req) {
        return ['state' => 'picked_up', 'words' => 'Picked up ' . $at('picked_up_at') . ', waiting for the result'];
    }
    $age = $now - deploy_ts($row['update_requested_at'] ?? null);
    if ($age > DEPLOY_LAPSE_SECONDS) {
        return ['state' => 'lapsed', 'words' => 'Lapsed: not picked up within 24 hours. Is the cron job set up?'];
    }
    if ($age > DEPLOY_WAIT_WARN_SECONDS) {
        return ['state' => 'waiting', 'words' => 'Waiting since ' . $at('update_requested_at') . ': is the cron job set up?'];
    }
    return ['state' => 'requested', 'words' => 'Requested ' . $at('update_requested_at')];
}

/**
 * The open request a server should act on: {id, at} while it is newer than the last finished one and under 24 hours old.
 * @return array{id:int,at:int}|null
 */
function deploy_pending(array $row, int $now): ?array
{
    $req = (int)($row['update_request_id'] ?? 0);
    $at = deploy_ts($row['update_requested_at'] ?? null);
    if ($req <= 0 || $req <= (int)($row['done_id'] ?? 0) || $at <= 0 || $now - $at > DEPLOY_LAPSE_SECONDS) {
        return null;
    }
    return ['id' => $req, 'at' => $at];
}

/**
 * A server's report, strictly cleaned: {seen_id, done_id, ok, msg, sha}. Null when it is not a report at all.
 * ids: whole numbers >= 0 (anything else = 0); ok: true / false / null (no result); msg: printable, at most 500
 * characters, token-shaped text removed; sha: 40 lower-case hex characters or ''.
 * @return array{seen_id:int,done_id:int,ok:?bool,msg:string,sha:string}|null
 */
function deploy_clean_report($in): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $id = static fn($v): int => is_int($v) && $v > 0 && $v <= 4294967295 ? $v : 0;
    $sha = isset($in['sha']) && is_string($in['sha']) ? strtolower(trim($in['sha'])) : '';
    return [
        'seen_id' => $id($in['seen_id'] ?? null),
        'done_id' => $id($in['done_id'] ?? null),
        'ok' => isset($in['ok']) && is_bool($in['ok']) ? $in['ok'] : null,
        'msg' => isset($in['msg']) && is_string($in['msg']) ? deploy_scrub($in['msg'], [], DEPLOY_MSG_MAX) : '',
        'sha' => preg_match('/^[0-9a-f]{40}$/D', $sha) ? $sha : '',
    ];
}

/**
 * What a cleaned report changes on a target row (column => value; [] = nothing). Ids are only accepted when they are
 * newer than the last one recorded and not above the newest request; a result needs ok true/false.
 */
function deploy_report_changes(array $row, array $r, int $now): array
{
    $req = (int)($row['update_request_id'] ?? 0);
    $stamp = date('Y-m-d H:i:s', $now);
    $ch = [];
    if ($r['sha'] !== '') {
        $ch['live_sha'] = $r['sha'];
        $ch['live_sha_at'] = $stamp;
    }
    if ($r['seen_id'] > (int)($row['picked_up_id'] ?? 0) && $r['seen_id'] <= $req) {
        $ch['picked_up_id'] = $r['seen_id'];
        $ch['picked_up_at'] = $stamp;
    }
    if ($r['ok'] !== null && $r['done_id'] > (int)($row['done_id'] ?? 0) && $r['done_id'] <= $req) {
        $ch['done_id'] = $r['done_id'];
        $ch['done_at'] = $stamp;
        $ch['result_ok'] = $r['ok'] ? 1 : 0;
        $ch['result_msg'] = $r['msg'] !== '' ? $r['msg'] : ($r['ok'] ? 'Updated.' : 'Failed (no reason given).');
        if ($r['done_id'] > (int)($ch['picked_up_id'] ?? $row['picked_up_id'] ?? 0)) {
            $ch['picked_up_id'] = $r['done_id'];
            $ch['picked_up_at'] = $ch['picked_up_at'] ?? $stamp;
        }
    }
    return $ch;
}

/**
 * A UAPI answer: {status, data, errors, messages} (the web /execute form), or the same under "result" (the uapi
 * command line with --output=json / jsonpretty). Text around the JSON (stderr mixed in by 2>&1) is ignored.
 * @return array{ok:bool,data:mixed,errors:string[],messages:string[]}
 */
function deploy_uapi_parse(string $body): array
{
    $j = json_decode($body, true);
    if (!is_array($j)) {
        $a = strpos($body, '{');
        $b = strrpos($body, '}');
        $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($body, $a, $b - $a + 1), true) : null;
    }
    if (is_array($j) && is_array($j['result'] ?? null) && !array_key_exists('status', $j)) {
        $j = $j['result'];
    }
    if (!is_array($j) || !array_key_exists('status', $j)) {
        return ['ok' => false, 'data' => null, 'errors' => ['cPanel did not answer in the expected way.'], 'messages' => []];
    }
    $flat = static function ($v): array {
        $out = [];
        foreach ((array)$v as $x) {
            if (is_scalar($x)) {
                $out[] = (string)$x;
            } elseif (is_array($x)) {
                $out[] = implode(' ', array_filter($x, 'is_scalar'));
            }
        }
        return array_values(array_filter($out, static fn($s) => trim($s) !== ''));
    };
    return ['ok' => (int)$j['status'] === 1, 'data' => $j['data'] ?? null, 'errors' => $flat($j['errors'] ?? []), 'messages' => $flat($j['messages'] ?? [])];
}

/**
 * The result file the cron line wrote (storage/deploy-last.json) as {ok, msg} for a report (msg scrubbed, at most 500
 * characters): the output of `git pull --ff-only origin BRANCH` ending in a line "exit=N" (the cron line since
 * 2026-11-23, see deploy_git_result; git output without that line = the command did not finish), or the older
 * `uapi VersionControl update` JSON, still read so a result written by the old cron line is recorded too.
 */
function deploy_uapi_result(string $body, string $branch): array
{
    if (trim($body) === '') {
        return ['ok' => false, 'msg' => 'The update command wrote nothing (check the cron line: Guide, section 18).'];
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', mb_scrub($body, 'UTF-8')) ?: []), static fn($l) => $l !== ''));
    if ($lines && preg_match('/^exit=(\d+)$/D', $lines[count($lines) - 1], $m)) {
        return deploy_git_result(array_slice($lines, 0, -1), (int)$m[1], $branch); // checked first: git output may hold "{...}"
    }
    $p = deploy_uapi_parse($body);
    if ($p['ok']) {
        return ['ok' => true, 'msg' => 'cPanel accepted Update from Remote for ' . deploy_scrub($branch, [], 100) . '.'];
    }
    if (!preg_match('/"status"/', $body) && preg_grep('/^(From |Updating |Already up|Fast-forward|error:|fatal:|hint:)/', $lines)) {
        return ['ok' => false, 'msg' => deploy_scrub('The update command did not finish (no exit code): ' . implode(' ', array_slice($lines, -5)), [], DEPLOY_MSG_MAX)];
    }
    $why = $p['errors'] ? implode(' ', $p['errors']) : 'no reason given';
    return ['ok' => false, 'msg' => deploy_scrub('cPanel said no: ' . $why . ' (it only pulls when the live copy can fast-forward: local changes on the server stop it).', [], DEPLOY_MSG_MAX)];
}

/**
 * `git pull --ff-only` output lines (the "exit=N" line taken off) as {ok, msg}. Exit 0 = done: "Already up to date." or
 * the "Updating a..b" line with "Fast-forward" and the "N files changed" line (found anywhere: 2>&1 mixes stdout and
 * stderr). Any other exit = failed: git's own lines from the first "error:" / "fatal:" on (hint: lines left out), or the
 * last 5 lines when there is none (e.g. the shell's "No such file" for exit 127).
 */
function deploy_git_result(array $lines, int $exit, string $branch): array
{
    $text = implode("\n", $lines);
    $head = 'git pull of ' . deploy_scrub($branch, [], 100) . ': ';
    if ($exit === 0) {
        if (preg_match('/^Already up[ -]to[ -]date\b/mi', $text)) {
            return ['ok' => true, 'msg' => $head . 'Already up to date.'];
        }
        $parts = [];
        if (preg_match('/^Updating \S+/m', $text, $u)) {
            $parts[] = $u[0];
        }
        if (preg_match('/^Fast-forward\b/m', $text)) {
            $parts[] = 'Fast-forward';
        }
        if (preg_match('/^\d+ files? changed[^\n]*/m', $text, $c)) {
            $parts[] = $c[0];
        }
        return ['ok' => true, 'msg' => deploy_scrub($head . ($parts ? implode(', ', $parts) : 'finished') . '.', [], DEPLOY_MSG_MAX)];
    }
    $why = array_slice($lines, -5);
    foreach ($lines as $i => $l) {
        if (preg_match('/^(error|fatal):/i', $l)) {
            $why = array_slice($lines, $i);
            break;
        }
    }
    $why = array_filter($why, static fn($l) => strncasecmp($l, 'hint:', 5) !== 0);
    return ['ok' => false, 'msg' => deploy_scrub($head . 'failed (exit ' . $exit . '): ' . ($why ? implode(' ', $why) : 'no output'), [], DEPLOY_MSG_MAX)];
}

/**
 * The commit checked out in a git folder, read from the files only (HEAD -> ref -> refs/heads/* or packed-refs; a
 * ".git" file pointing elsewhere is followed once). Null when it cannot be read.
 */
function deploy_git_head(string $gitDir): ?string
{
    if (is_file($gitDir)) {
        $line = trim((string)@file_get_contents($gitDir, false, null, 0, 1024));
        if (strncmp($line, 'gitdir:', 7) !== 0) {
            return null;
        }
        $to = trim(substr($line, 7));
        $gitDir = ($to !== '' && ($to[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $to))) ? $to : dirname($gitDir) . '/' . $to;
    }
    $head = trim((string)@file_get_contents($gitDir . '/HEAD', false, null, 0, 1024));
    if (preg_match('/^[0-9a-f]{40}$/D', $head)) {
        return $head; // detached
    }
    if (!preg_match('#^ref: (refs/heads/[A-Za-z0-9_][A-Za-z0-9_./-]*)$#D', $head, $m) || strpos($m[1], '..') !== false) {
        return null;
    }
    $ref = $m[1];
    $loose = trim((string)@file_get_contents($gitDir . '/' . $ref, false, null, 0, 128));
    if (preg_match('/^[0-9a-f]{40}$/D', $loose)) {
        return $loose;
    }
    foreach (@file($gitDir . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('#^([0-9a-f]{40}) (\S+)$#D', $l, $pm) && $pm[2] === $ref) {
            return $pm[1];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------------- HTTP (GitHub only)

/** Replace the HTTP layer (tests): fn(method, url, headers, body, timeout) => ['http' => int, 'body' => string, 'error' => string]. */
function deploy_set_transport(?callable $fn): void
{
    $GLOBALS['__cp_deploy_transport'] = $fn;
}

/** @return array{http:int,body:string,error:string} HTTPS only, certificates verified, no redirects, short timeouts. */
function deploy_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 15): array
{
    $fake = $GLOBALS['__cp_deploy_transport'] ?? null;
    if (is_callable($fake)) {
        $r = (array)$fake($method, $url, $headers, $body, $timeout);
        return ['http' => (int)($r['http'] ?? 0), 'body' => (string)($r['body'] ?? ''), 'error' => (string)($r['error'] ?? '')];
    }
    if (strncmp($url, 'https://', 8) !== 0) {
        return ['http' => 0, 'body' => '', 'error' => 'Only https addresses are used.'];
    }
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'body' => '', 'error' => 'This server cannot make web requests (PHP cURL is missing).'];
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => max(5, $timeout), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'LicencePanel-Deployments/1.0', CURLOPT_MAXFILESIZE => 8 * 1048576,
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $resp === false ? (string)curl_error($ch) : '';
    curl_close($ch);
    return ['http' => $http, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err];
}

// ---------------------------------------------------------------------------------------------------- GitHub

function deploy_cache_dir(): string
{
    return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/deploy-cache';
}

/** The cache file for one repository + branch (the name says nothing about the token; the file never holds it). */
function deploy_cache_file(string $dir, string $repo, string $branch): string
{
    return rtrim($dir, '/\\') . '/gh-' . hash('sha256', strtolower($repo) . '|' . $branch) . '.json';
}

function deploy_cache_clear(string $dir, ?string $repo = null, ?string $branch = null): void
{
    $files = $repo !== null ? [deploy_cache_file($dir, $repo, (string)$branch)] : (glob(rtrim($dir, '/\\') . '/gh-*.json') ?: []);
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/** A plain sentence for a failed GitHub call (scrubbed). */
function deploy_github_problem(array $r, string $token): string
{
    if ((int)$r['http'] === 0) {
        return 'Could not reach GitHub' . ($r['error'] !== '' ? ' (' . deploy_scrub($r['error'], [$token]) . ')' : '') . '.';
    }
    $msg = '';
    $j = json_decode($r['body'], true);
    if (is_array($j) && isset($j['message']) && is_string($j['message'])) {
        $msg = $j['message'];
    }
    $lead = [
        401 => 'GitHub refused the token - check it was copied in full and has not expired',
        403 => 'GitHub refused (the token may lack read access to this repository, or the hourly limit is used up)',
        404 => 'GitHub does not show this repository or branch to this token - check owner/name, the branch, and that the token lists this repository',
        409 => 'The repository is empty',
        422 => 'GitHub does not know that branch',
    ][(int)$r['http']] ?? 'GitHub answered with an error';
    return $lead . ' (HTTP ' . (int)$r['http'] . ')' . ($msg !== '' ? ': ' . deploy_scrub($msg, [$token]) : '.');
}

/**
 * The newest 100 commits on a branch, newest first; cached for 5 minutes ($force = ask GitHub now).
 * @return array{ok:bool,commits:array,error:string,fetched_at:int,cached:bool}
 */
function deploy_github_commits(string $repo, string $branch, string $token, int $now, string $cacheDir, bool $force = false): array
{
    $fail = static fn(string $e): array => ['ok' => false, 'commits' => [], 'error' => $e, 'fetched_at' => 0, 'cached' => false];
    if (!deploy_repo_ok($repo) || !deploy_branch_ok($branch)) {
        return $fail('The repository or branch is not valid.');
    }
    $file = deploy_cache_file($cacheDir, $repo, $branch);
    if (!$force && is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && is_int($c['at'] ?? null) && is_array($c['commits'] ?? null)
            && $now - $c['at'] >= 0 && $now - $c['at'] < DEPLOY_CACHE_SECONDS) {
            return ['ok' => true, 'commits' => $c['commits'], 'error' => '', 'fetched_at' => $c['at'], 'cached' => true];
        }
    }
    if ($token === '') {
        return $fail('No GitHub token is saved yet (see "GitHub access" below).');
    }
    [$owner, $name] = explode('/', $repo, 2);
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name)
        . '/commits?sha=' . rawurlencode($branch) . '&per_page=' . DEPLOY_GITHUB_PER_PAGE;
    $r = deploy_http('GET', $url, [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
    ], null, 15);
    if ($r['http'] !== 200) {
        return $fail(deploy_github_problem($r, $token));
    }
    $commits = deploy_parse_commits($r['body']);
    if ($commits === null) {
        return $fail('GitHub did not answer with a list of commits.');
    }
    if (is_dir($cacheDir) || @mkdir($cacheDir, 0700, true)) {
        if (!is_file($cacheDir . '/.htaccess')) {
            @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['at' => $now, 'commits' => $commits], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }
    return ['ok' => true, 'commits' => $commits, 'error' => '', 'fetched_at' => $now, 'cached' => false];
}

// ---------------------------------------------------------------------------------------------------- database

function deploy_targets(PDO $pdo): array
{
    return $pdo->query('SELECT d.*, t.name AS tenant_name FROM cp_deploy_targets d LEFT JOIN cp_tenants t ON t.id = d.tenant_id ORDER BY d.tenant_id IS NOT NULL, d.name, d.id')->fetchAll();
}

function deploy_target(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_deploy_targets WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** The target of one business (null = none), or with $tenantId null this panel's own. */
function deploy_target_for(PDO $pdo, ?int $tenantId, bool $lock = false): ?array
{
    $st = $tenantId === null
        ? $pdo->prepare('SELECT * FROM cp_deploy_targets WHERE tenant_id IS NULL ORDER BY id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''))
        : $pdo->prepare('SELECT * FROM cp_deploy_targets WHERE tenant_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute($tenantId === null ? [] : [$tenantId]);
    $r = $st->fetch();
    return $r ?: null;
}

function deploy_audit_view(?array $t): ?array
{
    if ($t === null) {
        return null;
    }
    return array_intersect_key($t, array_flip(['id', 'name', 'tenant_id', 'github_repo', 'branch']));
}

/**
 * Adds ($id = 0) or edits a live copy from deploy_validate_target(). One per business, and one for this panel.
 * @return array{0:int,1:string} [id, problem]
 */
function deploy_save_target(PDO $pdo, int $id, array $c, int $adminId): array
{
    $before = $id > 0 ? deploy_target($pdo, $id) : null;
    if ($id > 0 && $before === null) {
        return [0, 'That live copy no longer exists.'];
    }
    $other = deploy_target_for($pdo, $c['tenant_id']);
    if ($other !== null && (int)$other['id'] !== $id) {
        return [0, ($c['tenant_id'] === null ? 'This panel' : 'That business') . ' already has a live copy ("' . $other['name'] . '"). Edit that one instead.'];
    }
    $vals = [$c['name'], $c['tenant_id'], $c['github_repo'], $c['branch'], $adminId];
    if ($id > 0) {
        $pdo->prepare('UPDATE cp_deploy_targets SET name = ?, tenant_id = ?, github_repo = ?, branch = ?, updated_by = ? WHERE id = ?')
            ->execute(array_merge($vals, [$id]));
    } else {
        $pdo->prepare('INSERT INTO cp_deploy_targets (name, tenant_id, github_repo, branch, updated_by) VALUES (?, ?, ?, ?, ?)')->execute($vals);
        $id = (int)$pdo->lastInsertId();
    }
    audit_log($pdo, $adminId, $before ? 'deploy_target_changed' : 'deploy_target_added', $c['tenant_id'],
        deploy_audit_view($before), deploy_audit_view(deploy_target($pdo, $id)));
    return [$id, ''];
}

/** Records a new "Update from Remote" request (a rising id), audited. Returns the request id (0 = no such target). */
function deploy_request_update(PDO $pdo, int $id, int $adminId, int $now): int
{
    $before = deploy_target($pdo, $id);
    if ($before === null) {
        return 0;
    }
    $pdo->prepare('UPDATE cp_deploy_targets SET update_request_id = GREATEST(update_request_id + 1, ?), update_requested_at = ?, requested_by = ? WHERE id = ?')
        ->execute([$now, date('Y-m-d H:i:s', $now), $adminId, $id]);
    $after = deploy_target($pdo, $id);
    $reqId = (int)$after['update_request_id'];
    audit_log($pdo, $adminId, 'deploy_update_requested', $before['tenant_id'] !== null ? (int)$before['tenant_id'] : null,
        ['request_id' => (int)$before['update_request_id'], 'live_sha' => $before['live_sha']],
        deploy_audit_view($after) + ['request_id' => $reqId]);
    return $reqId;
}

/** Applies a cleaned report to a target (checked against its newest values, under a row lock). Returns what changed. */
function deploy_apply_report(PDO $pdo, int $id, array $report, int $now): array
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $row = deploy_target($pdo, $id, true);
        $ch = $row ? deploy_report_changes($row, $report, $now) : [];
        if ($ch) {
            $sets = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($ch)));
            $pdo->prepare('UPDATE cp_deploy_targets SET ' . $sets . ' WHERE id = ?')->execute(array_merge(array_values($ch), [$id]));
            if (isset($ch['done_id'])) {
                audit_log($pdo, null, 'deploy_update_reported', $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null, null, [
                    'target' => $id, 'request_id' => $ch['done_id'], 'ok' => (bool)$ch['result_ok'], 'message' => $ch['result_msg'],
                    'live_sha' => $ch['live_sha'] ?? $row['live_sha'],
                ]);
            }
        }
        if ($own) {
            $pdo->commit();
        }
        return $ch;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * For the licence answer (public/api/licence.php): stores the business's deploy report (if any) and returns
 * ['deploy_requested' => {id, at} | null]. Nothing at all when the business has no live copy here.
 */
function deploy_answer_fields(PDO $pdo, array $tenant, $reportIn, int $now): array
{
    $t = deploy_target_for($pdo, (int)$tenant['id']);
    if ($t === null) {
        return [];
    }
    $report = deploy_clean_report($reportIn);
    if ($report !== null) {
        deploy_apply_report($pdo, (int)$t['id'], $report, $now);
        $t = deploy_target($pdo, (int)$t['id']) ?? $t;
    }
    return ['deploy_requested' => deploy_pending($t, $now)];
}

/** The panel-wide GitHub settings row (sealed token, hint), or an empty one. */
function deploy_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT github_token_sealed, github_token_hint, updated_at FROM cp_deploy_settings WHERE id = 1')->fetch();
    return $r ?: ['github_token_sealed' => null, 'github_token_hint' => null, 'updated_at' => null];
}

function deploy_github_token(PDO $pdo): ?string
{
    return deploy_open(deploy_settings($pdo)['github_token_sealed'] ?? null, deploy_box_key(), 'github');
}

/** Saves the GitHub token (sealed). '' = saved, otherwise the problem. */
function deploy_save_github_token(PDO $pdo, string $token, int $adminId): string
{
    $token = trim($token);
    if (!deploy_github_token_ok($token)) {
        return 'That does not look like a GitHub token (letters, digits and underscores, no spaces).';
    }
    $sealed = deploy_seal($token, deploy_box_key(), 'github');
    if ($sealed === null) {
        return 'The token was not saved: this panel has no signing key to lock it with (Signing key page).';
    }
    $old = deploy_settings($pdo)['github_token_hint'] ?? null;
    $pdo->prepare('INSERT INTO cp_deploy_settings (id, github_token_sealed, github_token_hint, updated_by) VALUES (1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE github_token_sealed = VALUES(github_token_sealed), github_token_hint = VALUES(github_token_hint), updated_by = VALUES(updated_by)')
        ->execute([$sealed, deploy_hint($token), $adminId]);
    audit_log($pdo, $adminId, 'deploy_github_token_saved', null, ['hint' => $old], ['hint' => deploy_hint($token)]);
    return '';
}
