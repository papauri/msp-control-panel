<?php
// "Forgot your password?" (app/pwreset.php), no database, no network: the link address, the link secret's shape,
// when the feature is on, and the two emails.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/bootstrap.php'; // functions only: nothing here connects to the database

// ── The base address comes only from APP_URL, and only when it is https ──
t_eq('https address kept', 'https://licence.example.com', pwreset_base_url('https://licence.example.com'));
t_eq('trailing slash dropped', 'https://licence.example.com', pwreset_base_url('https://licence.example.com/'));
t_eq('a sub-folder is kept', 'https://example.com/panel', pwreset_base_url(' https://example.com/panel/ '));
t_eq('plain http refused', '', pwreset_base_url('http://licence.example.com'));
t_eq('http on localhost allowed (testing)', 'http://localhost:8080', pwreset_base_url('http://localhost:8080'));
t_eq('empty refused', '', pwreset_base_url(''));
t_eq('no scheme refused', '', pwreset_base_url('licence.example.com'));
t_eq('javascript: refused', '', pwreset_base_url('javascript:alert(1)'));
t_eq('user:pass@ refused', '', pwreset_base_url('https://a:b@licence.example.com'));
t_eq('query refused', '', pwreset_base_url('https://licence.example.com/?x=1'));
t_eq('quotes refused', '', pwreset_base_url('https://licence.example.com/"><script>'));
t_eq('spaces inside refused', '', pwreset_base_url('https://licence.example.com/a b'));

// ── Link secret ──
$tok = bin2hex(random_bytes(32));
t_ok('a fresh secret has the right shape', pwreset_token_ok($tok));
t_ok('too short refused', !pwreset_token_ok(substr($tok, 1)));
t_ok('upper case refused (the page lower-cases first)', !pwreset_token_ok(strtoupper($tok)) || ctype_digit($tok));
t_ok('non-hex refused', !pwreset_token_ok(str_repeat('g', 64)));
t_ok('array-ish / empty refused', !pwreset_token_ok(''));
t_eq('link', 'https://x.example/reset.php?t=' . $tok, pwreset_link('https://x.example', $tok));

// ── On only with an https APP_URL and working email settings ──
pwreset_load_mailer();
$env = &env_store();
$env['APP_URL'] = 'http://licence.example.com';
$env['SMTP_HOST'] = 'smtp.example.com';
$env['MAIL_FROM_EMAIL'] = 'info@example.com';
$env['MAIL_SETTINGS_FROM_DB'] = '0';
$env['MAIL_TEST_TO'] = '';
mail_config(true);
t_ok('off when APP_URL is not https', !pwreset_available());
t_ok('the reason names APP_URL', strpos(pwreset_off_reason(), 'APP_URL') !== false);
$env['APP_URL'] = 'https://licence.example.com';
mail_config(true);
t_ok('on with https APP_URL and SMTP', pwreset_available());
t_eq('no reason when on', '', pwreset_off_reason());
$env['SMTP_HOST'] = '';
mail_config(true);
t_ok('off without an SMTP server', !pwreset_available());
$env['SMTP_HOST'] = 'smtp.example.com';
$env['MAIL_TEST_TO'] = 'not an email';
mail_config(true);
t_ok('off when MAIL_TEST_TO is broken (mail_send would refuse anyway)', !pwreset_available());
$env['MAIL_TEST_TO'] = '';
mail_config(true);

// ── Emails ──
$link = pwreset_link('https://licence.example.com', $tok);
$m = pwreset_link_email($link, '203.0.113.9');
t_ok('subject', $m['subject'] === 'Reset your Licence panel password');
t_ok('html has the link as a button', strpos($m['html'], 'href="' . $link . '"') !== false);
t_ok('text has the link', strpos($m['text'], $link) !== false);
t_ok('names the internet address', strpos($m['text'], '203.0.113.9') !== false);
t_ok('says the 6-digit code is still needed', strpos($m['text'], '6-digit code') !== false);
t_ok('says the lifetime', strpos($m['text'], PWRESET_TTL_MINUTES . ' minutes') !== false);
t_ok('html ends with the ignore-if-not-you note', strpos($m['html'], 'Did not ask for this?') > strpos($m['html'], 'href="'));
$evil = pwreset_email_html('T <b>', ['<script>x</script>'], ['go', 'https://x.example/?a="b"&c'], ['after <i>']);
t_ok('paragraphs escaped', strpos($evil, '<script>') === false && strpos($evil, '&lt;script&gt;') !== false);
t_ok('title escaped', strpos($evil, 'T <b>') === false);
t_ok('button address escaped', strpos($evil, 'href="https://x.example/?a=&quot;b&quot;&amp;c"') !== false);
t_ok('after-paragraph escaped', strpos($evil, 'after <i>') === false);
$n = pwreset_notice_email('9 Nov 2026 at 10:00', '198.51.100.7');
t_ok('notice subject', $n['subject'] === 'Your Licence panel password was changed');
t_ok('notice names when and where', strpos($n['text'], '9 Nov 2026 at 10:00') !== false && strpos($n['text'], '198.51.100.7') !== false);
t_ok('notice says what to do if it was not you', strpos($n['text'], 'NOT you') !== false);
t_ok('notice carries no link', strpos($n['html'], 'reset.php') === false);

t_done('pwreset');
