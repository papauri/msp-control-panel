<?php
// Tiny shared test harness. Exits non-zero on any failure.
$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
function t_ok(string $name, bool $cond): void
{
    if ($cond) {
        $GLOBALS['t_pass']++;
    } else {
        $GLOBALS['t_fail']++;
        echo "FAIL: $name\n";
    }
}
function t_eq(string $name, $expected, $actual): void
{
    t_ok($name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')', $expected === $actual);
}
function t_done(string $suite): void
{
    echo "$suite: {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
}
date_default_timezone_set('UTC');
