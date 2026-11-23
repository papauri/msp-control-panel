<?php
// Deployments (app/deploy.php, scripts/deploy-check.php; 2026-11-23).
// Pure tests always run and never touch the network (a fake transport answers every GitHub call; cPanel is never called).
// With --db a smoke test runs on the real database inside ONE transaction that is rolled back (cp_ tables only): a made-up
// business gets a live copy, an update is requested, its licence answer carries the request, its report closes it.
// It is skipped when migration 015 is not applied.
//   php scripts/tests/deploy_test.php        pure tests
//   php scripts/tests/deploy_test.php --db   pure tests + rolled-back database smoke test
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/deploy.php';

$box = random_bytes(32);
$gh = 'github_pat_11ABCDEFG0' . str_repeat('xY9', 20) . 'WXYZ';

// --- the GitHub token at rest
$sealed = deploy_seal($gh, $box, 'github');
t_ok('sealed form has its prefix and hides the token', is_string($sealed) && strncmp($sealed, 'cpd1:', 5) === 0 && strpos($sealed, 'WXYZ') === false && strpos($sealed, 'github_pat') === false);
t_eq('opens again with the same key and purpose', $gh, deploy_open($sealed, $box, 'github'));
t_eq('wrong key: nothing', null, deploy_open($sealed, random_bytes(32), 'github'));
t_eq('wrong purpose: nothing', null, deploy_open($sealed, $box, 'cpanel'));
t_eq('no key: refuses to seal', null, deploy_seal($gh, null, 'github'));
t_eq('short key: refuses to seal', null, deploy_seal($gh, 'short', 'github'));
t_eq('empty value: nothing sealed', null, deploy_seal('', $box, 'github'));
$raw = base64_decode(substr($sealed, 5));
$raw[14] = chr(ord($raw[14]) ^ 1);
t_eq('changed sealed value: nothing', null, deploy_open('cpd1:' . base64_encode($raw), $box, 'github'));
t_ok('two seals differ (random nonce)', deploy_seal($gh, $box, 'github') !== $sealed);
t_eq('hint shows the last 4 only', "\u{2022}\u{2022}\u{2022}\u{2022}WXYZ", deploy_hint($gh));
t_ok('fine-grained token accepted', deploy_github_token_ok($gh));
t_ok('classic token accepted', deploy_github_token_ok('ghp_' . str_repeat('a1', 18)));
t_ok('token with a space refused', !deploy_github_token_ok('ghp_abc def ghi jkl mno pqr stu'));
t_ok('short token refused', !deploy_github_token_ok('ghp_short'));

// --- scrubbing
$s = deploy_scrub('failed for ' . $gh . ' and Bearer ' . $gh, [$gh]);
t_ok('scrub removes the token', strpos($s, 'WXYZ') === false && strpos($s, 'github_pat') === false && strpos($s, 'xY9xY9') === false);
t_ok('scrub removes any token shape', strpos(deploy_scrub('x ghp_ABCDEFGHIJ1234567890 y github_pat_ZZZ_111 z Authorization: cpanel bob:ABC123'), 'ghp_A') === false
    && strpos(deploy_scrub('github_pat_ZZZ_111'), 'ZZZ') === false && strpos(deploy_scrub('cpanel bob:SECRET99'), 'SECRET99') === false);
t_eq('scrub flattens control characters', 'a b c', deploy_scrub("a\x00\x07b\r\n\tc"));
t_eq('scrub limits the length', 500, mb_strlen(deploy_scrub(str_repeat('é', 900), [], 500)));
t_eq('scrub survives bad UTF-8', 'ab', str_replace('?', '', deploy_scrub("a\xC3b")));
t_eq('scrub: user:password in an address', 'fatal: https://***@github.com/x', deploy_scrub('fatal: https://user:tok@github.com/x'));
t_eq('scrub: a token in an address', "'https://***@github.com' x", deploy_scrub("'https://tok@github.com' x"));
t_eq('scrub: a password holding "@" is removed whole', 'https://***@github.com/x', deploy_scrub('https://user:p@ss@github.com/x'));
t_eq('scrub: a GitHub token in an address', 'https://***@github.com/x', deploy_scrub('https://ghp_ABCDEFGH123456@github.com/x'));
t_eq('scrub: plain addresses and emails left alone', 'https://github.com/papauri/x mail a@b.com', deploy_scrub('https://github.com/papauri/x mail a@b.com'));

// --- validation
foreach (['papauri/msp-control-panel', 'papauri/Liwonde_Sun_Hotel_2026', 'papauri/Rosalyns-hotel-2026', 'a.b/c.d'] as $r) {
    t_ok("repo ok: $r", deploy_repo_ok($r));
}
foreach (['', 'papauri', 'papauri/x/y', 'https://github.com/a/b', 'a/..', '../b', 'a b/c', 'a/b?x=1', "a/b\n"] as $r) {
    t_ok('repo refused: ' . json_encode($r), !deploy_repo_ok($r));
}
foreach (['main', 'release/2026', 'feature_x-1.2'] as $b) {
    t_ok("branch ok: $b", deploy_branch_ok($b));
}
foreach (['', '-x', '/main', 'main/', 'a..b', 'x.lock', 'a b', 'a//b', 'm;rm'] as $b) {
    t_ok('branch refused: ' . json_encode($b), !deploy_branch_ok($b));
}
[$c, $errs] = deploy_validate_target(['name' => ' Panel ', 'tenant_id' => '', 'github_repo' => 'papauri/msp-control-panel', 'branch' => ''], [1, 2]);
t_eq('valid target: no problems', [], $errs);
t_eq('blank business = this panel', null, $c['tenant_id']);
t_eq('blank branch = main', 'main', $c['branch']);
t_eq('name trimmed', 'Panel', $c['name']);
[$c, $errs] = deploy_validate_target(['name' => 'X', 'tenant_id' => '2', 'github_repo' => 'a/b', 'branch' => 'main'], [1, 2]);
t_eq('business kept', 2, $c['tenant_id']);
[, $errs] = deploy_validate_target(['name' => '', 'tenant_id' => '9', 'github_repo' => 'bad', 'branch' => '../x'], [1, 2]);
t_eq('four problems', 4, count($errs));
[, $errs] = deploy_validate_target(['name' => ['x'], 'tenant_id' => ['1'], 'github_repo' => 'a/b'], [1]);
t_eq('arrays in the form are refused, not used', 1, count($errs));

// --- commits
$sha = static fn(int $i): string => sha1('commit-' . $i);
$ghList = [];
for ($i = 1; $i <= 12; $i++) {
    $ghList[] = ['sha' => $sha($i), 'html_url' => 'https://github.com/papauri/x/commit/' . $sha($i),
        'commit' => ['message' => "Subject $i\r\n\r\nBody line $i", 'author' => ['name' => "Dev $i", 'date' => '2026-11-2' . ($i % 3) . 'T10:00:00Z']],
        'author' => ['login' => "dev$i"]];
}
$ghList[] = ['sha' => 'not-a-sha', 'commit' => []];
$ghList[] = ['sha' => $sha(99), 'html_url' => 'javascript:alert(1)', 'commit' => ['message' => ['x'], 'author' => 'x'], 'author' => null];
$parsed = deploy_parse_commits(json_encode($ghList));
t_eq('commits parsed (bad sha skipped)', 13, count($parsed));
t_eq('sha kept', $sha(1), $parsed[0]['sha']);
t_eq('author name', 'Dev 1', $parsed[0]['author']);
t_eq('login', 'dev1', $parsed[0]['login']);
t_eq('date parsed', strtotime('2026-11-21T10:00:00Z'), $parsed[0]['date']);
t_eq('line endings made plain', "Subject 1\n\nBody line 1", $parsed[0]['message']);
t_eq('only github.com links', '', $parsed[12]['url']);
t_eq('odd fields become empty', ['', '', '', 0], [$parsed[12]['message'], $parsed[12]['author'], $parsed[12]['login'], $parsed[12]['date']]);
t_eq('not a list: null', null, deploy_parse_commits('{"message":"Not Found"}'));
t_eq('not JSON: null', null, deploy_parse_commits('<html>'));
t_eq('empty list: empty', [], deploy_parse_commits('[]'));
t_eq('subject = first line', 'Subject 1', deploy_subject($parsed[0]['message']));
t_eq('subject of blank', '', deploy_subject(''));

// --- behind
$list = array_slice($parsed, 0, 12);
t_eq('live = newest: up to date', ['state' => 'up_to_date', 'count' => 0], deploy_behind($list, $sha(1)));
t_eq('live = 4th: 3 behind', ['state' => 'behind', 'count' => 3], deploy_behind($list, $sha(4)));
t_eq('short live sha matches', ['state' => 'behind', 'count' => 3], deploy_behind($list, substr($sha(4), 0, 7)));
t_eq('upper-case live sha matches', ['state' => 'behind', 'count' => 1], deploy_behind($list, strtoupper($sha(2))));
t_eq('not in the list', 'not_found', deploy_behind($list, str_repeat('b', 40))['state']);
t_eq('no live sha', 'unknown', deploy_behind($list, '')['state']);
t_eq('no list', 'unknown', deploy_behind(null, $sha(1))['state']);
t_eq('empty list', 'unknown', deploy_behind([], $sha(1))['state']);
t_eq('words: behind', '3 commits behind main', deploy_behind_words(['state' => 'behind', 'count' => 3], 'main'));
t_eq('words: one', '1 commit behind main', deploy_behind_words(['state' => 'behind', 'count' => 1], 'main'));
t_eq('words: up to date', 'Up to date with main', deploy_behind_words(['state' => 'up_to_date', 'count' => 0], 'main'));

// --- GitHub through the fake transport, with the cache
$dir = sys_get_temp_dir() . '/cp-deploy-test-' . bin2hex(random_bytes(4));
$calls = [];
$answer = ['http' => 200, 'body' => json_encode($ghList)];
deploy_set_transport(static function ($m, $u, $h, $b, $t) use (&$calls, &$answer) {
    $calls[] = [$m, $u, $h];
    return $answer;
});
$now = 1_800_000_000;
$r = deploy_github_commits('papauri/msp-control-panel', 'main', $gh, $now, $dir);
t_ok('fetched', $r['ok'] && count($r['commits']) === 13 && !$r['cached']);
t_eq('one call', 1, count($calls));
t_eq('the documented address', 'https://api.github.com/repos/papauri/msp-control-panel/commits?sha=main&per_page=100', $calls[0][1]);
t_ok('token only in the Authorization header', in_array('Authorization: Bearer ' . $gh, $calls[0][2], true) && strpos($calls[0][1], 'github_pat') === false);
t_ok('GitHub headers', in_array('Accept: application/vnd.github+json', $calls[0][2], true) && in_array('X-GitHub-Api-Version: 2022-11-28', $calls[0][2], true));
$files = glob($dir . '/gh-*.json');
t_eq('one cache file', 1, count($files));
t_ok('cache file never holds the token', strpos((string)file_get_contents($files[0]), 'github_pat') === false && strpos((string)file_get_contents($files[0]), 'WXYZ') === false);
t_ok('cache folder is closed to the web', is_file($dir . '/.htaccess'));
$r = deploy_github_commits('papauri/msp-control-panel', 'main', $gh, $now + 299, $dir);
t_ok('within 5 minutes: from the cache, no call', $r['ok'] && $r['cached'] && count($calls) === 1);
$r = deploy_github_commits('papauri/msp-control-panel', 'main', $gh, $now + 300, $dir);
t_ok('after 5 minutes: asked again', !$r['cached'] && count($calls) === 2);
$r = deploy_github_commits('papauri/msp-control-panel', 'main', $gh, $now + 301, $dir, true);
t_ok('forced: asked again', !$r['cached'] && count($calls) === 3);
$r = deploy_github_commits('papauri/Rosalyns-hotel-2026', 'main', $gh, $now, $dir);
t_ok('another repository has its own cache', !$r['cached'] && count($calls) === 4);
deploy_cache_clear($dir, 'papauri/msp-control-panel', 'main');
$r = deploy_github_commits('papauri/msp-control-panel', 'main', $gh, $now + 302, $dir);
t_ok('cleared: asked again', !$r['cached'] && count($calls) === 5);
$answer = ['http' => 401, 'body' => json_encode(['message' => 'Bad credentials for ' . $gh])];
$r = deploy_github_commits('papauri/x', 'main', $gh, $now, $dir);
t_ok('401: plain words', !$r['ok'] && strpos($r['error'], 'refused the token') !== false && strpos($r['error'], 'HTTP 401') !== false);
t_ok('401: the token never in the error', strpos($r['error'], 'WXYZ') === false && strpos($r['error'], 'github_pat') === false);
$answer = ['http' => 0, 'body' => '', 'error' => 'Could not resolve host while sending Bearer ' . $gh];
$r = deploy_github_commits('papauri/x', 'main', $gh, $now, $dir);
t_ok('no connection: plain words, no token', !$r['ok'] && strpos($r['error'], 'Could not reach GitHub') === 0 && strpos($r['error'], 'WXYZ') === false);
$answer = ['http' => 404, 'body' => '{"message":"Not Found"}'];
$r = deploy_github_commits('papauri/x', 'nope', $gh, $now, $dir);
t_ok('404: says what to check', !$r['ok'] && strpos($r['error'], 'does not show this repository') !== false);
$answer = ['http' => 200, 'body' => '{"message":"odd"}'];
$r = deploy_github_commits('papauri/y', 'main', $gh, $now, $dir);
t_ok('200 but not a list: refused, not cached', !$r['ok'] && !glob($dir . '/gh-' . hash('sha256', 'papauri/y|main') . '.json'));
$n = count($calls);
$r = deploy_github_commits('papauri/z', 'main', '', $now, $dir);
t_ok('no token: no call', !$r['ok'] && count($calls) === $n && strpos($r['error'], 'No GitHub token') === 0);
$r = deploy_github_commits('bad repo', 'main', $gh, $now, $dir);
t_ok('bad repository: no call', !$r['ok'] && count($calls) === $n);
deploy_set_transport(null);
deploy_cache_clear($dir);
@unlink($dir . '/.htaccess');
@rmdir($dir);

// --- requests: states and what is sent
$at = static fn(int $t): string => date('Y-m-d H:i:s', $t);
$row = ['update_request_id' => 0, 'update_requested_at' => null, 'picked_up_id' => 0, 'picked_up_at' => null, 'done_id' => 0, 'done_at' => null, 'result_ok' => null, 'result_msg' => null];
t_eq('state: none', 'none', deploy_request_state($row, $now)['state']);
t_eq('pending: none', null, deploy_pending($row, $now));
$row = ['update_request_id' => $now - 60, 'update_requested_at' => $at($now - 60)] + $row;
t_eq('state: requested', 'requested', deploy_request_state($row, $now)['state']);
t_eq('pending: id and time', ['id' => $now - 60, 'at' => $now - 60], deploy_pending($row, $now));
t_eq('state: waiting after 30 minutes', 'waiting', deploy_request_state($row, $now + 1800)['state']);
t_ok('waiting asks about the cron job', strpos(deploy_request_state($row, $now + 1800)['words'], 'cron job') !== false);
t_eq('state: lapsed after 24 hours', 'lapsed', deploy_request_state($row, $now + 86400)['state']);
t_eq('pending: lapsed is never sent', null, deploy_pending($row, $now + 86400));
$picked = ['picked_up_id' => $now - 60, 'picked_up_at' => $at($now - 30)] + $row;
t_eq('state: picked up', 'picked_up', deploy_request_state($picked, $now)['state']);
t_ok('pending: still sent until done', deploy_pending($picked, $now) !== null);
$done = ['done_id' => $now - 60, 'done_at' => $at($now), 'result_ok' => 1] + $picked;
t_eq('state: done', 'done', deploy_request_state($done, $now)['state']);
t_eq('pending: none after done', null, deploy_pending($done, $now));
t_eq('state: failed', 'failed', deploy_request_state(['result_ok' => 0] + $done, $now)['state']);
$again = ['update_request_id' => $now + 5, 'update_requested_at' => $at($now + 5)] + $done;
t_eq('a new request after a finished one is open again', 'requested', deploy_request_state($again, $now + 10)['state']);

// --- reports: cleaning
t_eq('not a report', null, deploy_clean_report('x'));
t_eq('not a report (null)', null, deploy_clean_report(null));
$cr = deploy_clean_report(['seen_id' => '5', 'done_id' => -3, 'ok' => 'yes', 'msg' => "line1\nline2\x00 " . $gh, 'sha' => strtoupper($sha(3))]);
t_eq('ids must be whole numbers', [0, 0], [$cr['seen_id'], $cr['done_id']]);
t_eq('ok must be true/false', null, $cr['ok']);
t_ok('message flattened and scrubbed', strpos($cr['msg'], "\n") === false && strpos($cr['msg'], 'WXYZ') === false && strpos($cr['msg'], 'line1 line2') === 0);
t_eq('sha lower-cased', $sha(3), $cr['sha']);
t_eq('bad sha dropped', '', deploy_clean_report(['sha' => 'abc1234'])['sha']);
t_eq('sha with junk dropped', '', deploy_clean_report(['sha' => $sha(3) . ';'])['sha']);
t_eq('message at most 500', 500, mb_strlen(deploy_clean_report(['msg' => str_repeat('x', 2000)])['msg']));
t_eq('huge id refused', 0, deploy_clean_report(['seen_id' => 99999999999])['seen_id']);

// --- reports: what they may change
$row = ['update_request_id' => 100, 'picked_up_id' => 90, 'done_id' => 90, 'live_sha' => null] + $row;
$ch = deploy_report_changes($row, deploy_clean_report(['sha' => $sha(2)]), $now);
t_eq('sha only', ['live_sha' => $sha(2), 'live_sha_at' => $at($now)], $ch);
$ch = deploy_report_changes($row, deploy_clean_report(['seen_id' => 100]), $now);
t_eq('picked up the newest request', 100, $ch['picked_up_id'] ?? null);
t_eq('above the newest request: refused', [], deploy_report_changes($row, deploy_clean_report(['seen_id' => 101, 'done_id' => 101, 'ok' => true]), $now));
t_eq('older than the last handled: refused', [], deploy_report_changes($row, deploy_clean_report(['seen_id' => 90, 'done_id' => 90, 'ok' => true]), $now));
t_eq('done without ok/failed: no result', [], deploy_report_changes($row, deploy_clean_report(['done_id' => 100]), $now));
$ch = deploy_report_changes($row, deploy_clean_report(['done_id' => 100, 'ok' => false, 'msg' => 'cannot fast-forward']), $now);
t_eq('failure recorded as reported', [100, 0, 'cannot fast-forward', 100], [$ch['done_id'], $ch['result_ok'], $ch['result_msg'], $ch['picked_up_id']]);
$ch = deploy_report_changes($row, deploy_clean_report(['seen_id' => 100, 'done_id' => 100, 'ok' => true, 'sha' => $sha(1)]), $now);
t_eq('success recorded', [100, 1, 'Updated.', $sha(1)], [$ch['done_id'], $ch['result_ok'], $ch['result_msg'], $ch['live_sha']]);

// --- the uapi answer (command line wraps it in "result"; the web form does not)
$cliOk = json_encode(['apiversion' => 3, 'func' => 'update', 'module' => 'VersionControl', 'result' => ['status' => 1, 'data' => ['branch' => 'main'], 'errors' => null, 'messages' => null]], JSON_PRETTY_PRINT);
$cliNo = json_encode(['result' => ['status' => 0, 'data' => null, 'errors' => ['Git could not fast-forward ' . $gh], 'messages' => null]]);
t_ok('cli form: ok', deploy_uapi_parse($cliOk)['ok']);
t_ok('web form: ok', deploy_uapi_parse('{"status":1,"data":[],"errors":null}')['ok']);
t_ok('web form: failed', !deploy_uapi_parse('{"status":0,"errors":["no"]}')['ok']);
t_ok('noise around the JSON is ignored', deploy_uapi_parse("warning: something\n" . $cliOk . "\n")['ok']);
t_ok('not JSON: failed', !deploy_uapi_parse('uapi: command not found')['ok']);
$res = deploy_uapi_result($cliOk, 'main');
t_ok('result ok', $res['ok'] && strpos($res['msg'], 'Update from Remote for main') !== false);
$res = deploy_uapi_result($cliNo, 'main');
t_ok('result failed with the reason, token scrubbed', !$res['ok'] && strpos($res['msg'], 'fast-forward') !== false && strpos($res['msg'], 'WXYZ') === false);
t_ok('result: empty file', !deploy_uapi_result('', 'main')['ok']);
t_ok('result: at most 500', mb_strlen(deploy_uapi_result(json_encode(['result' => ['status' => 0, 'errors' => [str_repeat('e', 3000)]]]), 'main')['msg']) <= 500);

// --- git pull --ff-only output ending in "exit=N" (the cron line since 2026-11-23: cPanel's uapi cannot run from cron, CageFS)
$from = "From https://github.com/papauri/msp-control-panel\n * branch            main       -> FETCH_HEAD\n";
t_eq('git: already up to date', ['ok' => true, 'msg' => 'git pull of main: Already up to date.'], deploy_uapi_result($from . "Already up to date.\nexit=0\n", 'main'));
t_eq('git: fast-forward with the file summary (a "{a => b}" rename line is not read as JSON)',
    ['ok' => true, 'msg' => 'git pull of main: Updating 1a2b3c4..5d6e7f8, Fast-forward, 2 files changed, 8 insertions(+), 6 deletions(-).'],
    deploy_uapi_result($from . "   1a2b3c4..5d6e7f8  main -> origin/main\nUpdating 1a2b3c4..5d6e7f8\nFast-forward\n app/deploy.php | 12 +++++++-----\n src/{old => new}/x.php | 2 +-\n 2 files changed, 8 insertions(+), 6 deletions(-)\nexit=0\n", 'main'));
t_eq('git: CRLF line ends, one file', ['ok' => true, 'msg' => 'git pull of main: Updating a1..b2, Fast-forward, 1 file changed, 1 insertion(+).'],
    deploy_uapi_result("Updating a1..b2\r\nFast-forward\r\n 1 file changed, 1 insertion(+)\r\nexit=0\r\n", 'main'));
t_eq('git: exit 0 with no output = done', ['ok' => true, 'msg' => 'git pull of main: finished.'], deploy_uapi_result("exit=0\n", 'main'));
$res = deploy_uapi_result($from . "Updating 1a2b3c4..5d6e7f8\nerror: Your local changes to the following files would be overwritten by merge:\n\tapp/deploy.php\n\tpublic/a.php\n\tpublic/b.php\n\tpublic/c.php\n\tpublic/d.php\nPlease commit your changes or stash them before you merge.\nAborting\nexit=1\n", 'main');
t_ok('git: local changes would be overwritten (exit 1), from the error line on', !$res['ok']
    && strpos($res['msg'], 'git pull of main: failed (exit 1): error: Your local changes to the following files would be overwritten by merge: app/deploy.php') === 0
    && strpos($res['msg'], 'Aborting') !== false && strpos($res['msg'], 'Updating') === false && strpos($res['msg'], 'exit=') === false);
t_eq('git: diverged (exit 128), hint lines left out', ['ok' => false, 'msg' => 'git pull of main: failed (exit 128): fatal: Not possible to fast-forward, aborting.'],
    deploy_uapi_result($from . "hint: Diverging branches can't be fast-forwarded, you need to either:\nhint:\nhint: \tgit merge --no-ff\nfatal: Not possible to fast-forward, aborting.\nexit=128\n", 'main'));
t_eq('git: network failure (exit 128), credentials in the address scrubbed',
    ['ok' => false, 'msg' => "git pull of main: failed (exit 128): fatal: unable to access 'https://***@github.com/papauri/x/': Could not resolve host: github.com"],
    deploy_uapi_result("fatal: unable to access 'https://user:s3cretTok@github.com/papauri/x/': Could not resolve host: github.com\nexit=128\n", 'main'));
t_eq('git: no error/fatal line = the last lines (exit 127)', ['ok' => false, 'msg' => 'git pull of main: failed (exit 127): /bin/sh: /usr/bin/git: No such file or directory'],
    deploy_uapi_result("/bin/sh: /usr/bin/git: No such file or directory\nexit=127\n", 'main'));
$res = deploy_uapi_result($from . "Updating 1a2b3c4..5d6e7f8\nFast-forward\n", 'main');
t_ok('git: output with no exit= line = not finished, failed', !$res['ok'] && strpos($res['msg'], 'The update command did not finish (no exit code)') === 0 && strpos($res['msg'], 'Fast-forward') !== false);
$res = deploy_uapi_result("Updating a1..b2\nexit=0\nmore after\n", 'main');
t_ok('git: exit= not the last line = not finished', !$res['ok'] && strpos($res['msg'], 'did not finish (no exit code)') !== false);
t_ok('git: a malformed exit line is not a result', !deploy_uapi_result("exit=0x\n", 'main')['ok']);
t_ok('git: long failure cut to 500', mb_strlen(deploy_uapi_result(str_repeat("error: x\n", 200) . "exit=1\n", 'main')['msg']) <= 500);
t_ok('result: empty file names no uapi', strpos(deploy_uapi_result('', 'main')['msg'], 'uapi') === false);

// --- the live commit from .git (files only)
$g = sys_get_temp_dir() . '/cp-deploy-git-' . bin2hex(random_bytes(4));
@mkdir($g . '/.git/refs/heads', 0700, true);
file_put_contents($g . '/.git/HEAD', "ref: refs/heads/main\n");
file_put_contents($g . '/.git/refs/heads/main', $sha(7) . "\n");
t_eq('loose ref', $sha(7), deploy_git_head($g . '/.git'));
unlink($g . '/.git/refs/heads/main');
file_put_contents($g . '/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n" . $sha(8) . " refs/heads/main\n" . $sha(9) . " refs/tags/v1\n");
t_eq('packed ref', $sha(8), deploy_git_head($g . '/.git'));
file_put_contents($g . '/.git/HEAD', $sha(5) . "\n");
t_eq('detached HEAD', $sha(5), deploy_git_head($g . '/.git'));
file_put_contents($g . '/.git/HEAD', "ref: refs/heads/../../../etc/passwd\n");
t_eq('a ref with .. is refused', null, deploy_git_head($g . '/.git'));
file_put_contents($g . '/.git/HEAD', "ref: refs/heads/main\n");
file_put_contents($g . '/dotgit', "gitdir: .git\n");
t_eq('a .git file is followed', $sha(8), deploy_git_head($g . '/dotgit'));
t_eq('no repository', null, deploy_git_head($g . '/missing'));
@unlink($g . '/dotgit');
@unlink($g . '/.git/packed-refs');
@unlink($g . '/.git/HEAD');
@rmdir($g . '/.git/refs/heads');
@rmdir($g . '/.git/refs');
@rmdir($g . '/.git');
@rmdir($g);

// --- the page and the script never call cPanel and never push
$code = (string)file_get_contents(__DIR__ . '/../../app/deploy.php') . file_get_contents(__DIR__ . '/../../public/deploy.php') . file_get_contents(__DIR__ . '/../../scripts/deploy-check.php');
t_ok('no cPanel web calls (no :2083 / execute address)', strpos($code, ':2083') === false && strpos($code, "'/execute/") === false);
t_ok('no shell commands from PHP', !preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', $code));
t_ok('the script refuses the web', strpos((string)file_get_contents(__DIR__ . '/../../scripts/deploy-check.php'), "if (PHP_SAPI !== 'cli') { http_response_code(403);") !== false);

if (in_array('--db', $argv, true)) {
    require __DIR__ . '/../../app/bootstrap.php';
    $GLOBALS['__cp_deploy_box_test'] = $box; // the laptop has no signing key; the live panel derives its own
    $pdo = db();
    $have = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cp_deploy_targets', 'cp_deploy_settings')")->fetchColumn();
    if ($have !== 2) {
        echo "db: skipped (migration 015 is not applied)\n";
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO cp_tenants (slug, name, domain, paid_until, modules) VALUES ('zz-deploy-test', 'Deploy test', 'deploy-test.invalid', CURDATE(), ?)")
                ->execute([json_encode(['bookings', 'finance'])]);
            $tid = (int)$pdo->lastInsertId();
            $tenant = ['id' => $tid];
            t_eq('db: a business without a live copy gets nothing', [], deploy_answer_fields($pdo, $tenant, null, time()));
            [$id, $p] = deploy_save_target($pdo, 0, ['name' => 'Deploy test site', 'tenant_id' => $tid, 'github_repo' => 'papauri/x', 'branch' => 'main'], 0);
            t_ok('db: live copy added', $id > 0 && $p === '');
            [$id2, $p2] = deploy_save_target($pdo, 0, ['name' => 'Second', 'tenant_id' => $tid, 'github_repo' => 'papauri/y', 'branch' => 'main'], 0);
            t_ok('db: one live copy per business', $id2 === 0 && strpos($p2, 'already has a live copy') !== false);
            t_eq('db: nothing asked yet', ['deploy_requested' => null], deploy_answer_fields($pdo, $tenant, null, time()));
            $t0 = time();
            $req = deploy_request_update($pdo, $id, 0, $t0);
            t_ok('db: request id rises past the clock', $req >= $t0);
            $req2 = deploy_request_update($pdo, $id, 0, $t0);
            t_eq('db: a second request is newer', $req + 1, $req2);
            $ans = deploy_answer_fields($pdo, $tenant, null, $t0);
            t_eq('db: the answer carries the request', $req2, $ans['deploy_requested']['id'] ?? null);
            $ans = deploy_answer_fields($pdo, $tenant, ['seen_id' => $req2 + 50, 'done_id' => $req2 + 50, 'ok' => true], $t0);
            t_eq('db: a report above the newest request changes nothing', 0, (int)deploy_target($pdo, $id)['done_id']);
            $ans = deploy_answer_fields($pdo, $tenant, ['seen_id' => $req2, 'sha' => str_repeat('c', 40)], $t0);
            $row = deploy_target($pdo, $id);
            t_ok('db: picked up and sha stored, still open', (int)$row['picked_up_id'] === $req2 && $row['live_sha'] === str_repeat('c', 40) && $ans['deploy_requested'] !== null);
            $ans = deploy_answer_fields($pdo, $tenant, ['seen_id' => $req2, 'done_id' => $req2, 'ok' => false, 'msg' => 'Not possible to fast-forward'], $t0);
            $row = deploy_target($pdo, $id);
            t_ok('db: result stored and the request closed', (int)$row['done_id'] === $req2 && (int)$row['result_ok'] === 0 && $row['result_msg'] === 'Not possible to fast-forward' && $ans['deploy_requested'] === null);
            t_eq('db: shown as failed', 'failed', deploy_request_state($row, $t0)['state']);
            $logged = (int)$pdo->query("SELECT COUNT(*) FROM cp_audit_log WHERE action IN ('deploy_update_requested', 'deploy_update_reported') AND tenant_id = " . $tid)->fetchColumn();
            t_eq('db: requests and results are in the audit log', 3, $logged);
            t_eq('db: token refused when malformed', 'That does not look like a GitHub token (letters, digits and underscores, no spaces).', deploy_save_github_token($pdo, 'bad token', 0));
            t_eq('db: token saved', '', deploy_save_github_token($pdo, $gh, 0));
            $st = deploy_settings($pdo);
            t_ok('db: stored sealed, hint only', strpos((string)$st['github_token_sealed'], 'WXYZ') === false && $st['github_token_hint'] === deploy_hint($gh));
            t_eq('db: opens again', $gh, deploy_github_token($pdo));
            $aud = (string)$pdo->query("SELECT CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) FROM cp_audit_log WHERE action = 'deploy_github_token_saved' ORDER BY id DESC LIMIT 1")->fetchColumn();
            t_ok('db: the audit log never holds the token', $aud !== '' && strpos($aud, 'github_pat') === false && strpos($aud, 'cpd1:') === false);
        } finally {
            $pdo->rollBack();
        }
        t_eq('db: nothing kept', 0, (int)$pdo->query("SELECT COUNT(*) FROM cp_tenants WHERE slug = 'zz-deploy-test'")->fetchColumn());
    }
}
t_done('deploy');
