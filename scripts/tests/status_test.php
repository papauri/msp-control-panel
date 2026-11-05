<?php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../app/domain.php';

$t = ['paid_until' => '2026-03-10', 'grace_days' => 14, 'readonly_days' => 14, 'status_override' => null];
// paid_until 03-10; grace ends 03-24; read-only ends 04-07.
foreach ([
    '2026-01-01' => 'active', '2026-03-09' => 'active', '2026-03-10' => 'active',
    '2026-03-11' => 'grace', '2026-03-24' => 'grace',
    '2026-03-25' => 'read_only', '2026-04-07' => 'read_only',
    '2026-04-08' => 'suspended', '2027-01-01' => 'suspended',
] as $today => $want) {
    t_eq("status on $today", $want, status_compute($t, $today));
}

// Override wins in every direction.
foreach (status_list() as $ov) {
    foreach (['2026-01-01', '2026-03-15', '2026-04-01', '2030-01-01'] as $today) {
        $x = $t;
        $x['status_override'] = $ov;
        t_eq("override $ov on $today", $ov, status_compute($x, $today));
    }
}
$x = $t;
$x['status_override'] = '';
t_eq('empty override ignored', 'active', status_compute($x, '2026-03-01'));
$x['status_override'] = 'vip';
t_eq('unknown override ignored', 'active', status_compute($x, '2026-03-01'));

// Zero-length windows.
$z = ['paid_until' => '2026-03-10', 'grace_days' => 0, 'readonly_days' => 0, 'status_override' => null];
t_eq('zero windows: due day', 'active', status_compute($z, '2026-03-10'));
t_eq('zero windows: next day', 'suspended', status_compute($z, '2026-03-11'));

// Year / leap boundaries.
t_eq('add_days across year', '2027-01-04', status_add_days('2026-12-31', 4));
t_eq('add_days leap', '2028-02-29', status_add_days('2028-02-28', 1));

// Month arithmetic clamps to month end.
t_eq('31 Jan +1', '2026-02-28', status_add_months('2026-01-31', 1));
t_eq('31 Jan +1 leap', '2028-02-29', status_add_months('2028-01-31', 1));
t_eq('15 Jan +1', '2026-02-15', status_add_months('2026-01-15', 1));
t_eq('30 Nov +3', '2027-02-28', status_add_months('2026-11-30', 3));
t_eq('31 Dec +12', '2027-12-31', status_add_months('2026-12-31', 12));
t_eq('31 Mar +6', '2026-09-30', status_add_months('2026-03-31', 6));
t_eq('+1 over year end', '2027-01-15', status_add_months('2026-12-15', 1));

// Rank: unknown is strictest.
t_ok('rank order', status_rank('active') < status_rank('grace') && status_rank('grace') < status_rank('read_only') && status_rank('read_only') < status_rank('suspended'));
t_eq('unknown rank', 3, status_rank('bogus'));

// Domain normalisation.
foreach ([
    'Hotel.Example.com' => 'hotel.example.com',
    'https://www.hotel.example.com/admin?x=1' => 'hotel.example.com',
    'hotel.example.com:8443' => 'hotel.example.com',
    'hotel.example.com.' => 'hotel.example.com',
    'HTTP://WWW.Hotel.Example.com:80/' => 'hotel.example.com',
    '  hotel.example.com  ' => 'hotel.example.com',
    'https://user:pw@hotel.example.com/x' => 'hotel.example.com',
    'sub.www.example.com' => 'sub.www.example.com',
] as $in => $out) {
    t_eq("normalise $in", $out, licence_normalise_domain($in));
}
t_ok('valid domain', licence_domain_valid('hotel.example.com'));
t_ok('invalid domain: no dot', !licence_domain_valid('localhost'));
t_ok('invalid domain: spaces', !licence_domain_valid('bad domain.com'));
t_ok('invalid domain: slash', !licence_domain_valid('a.com/x'));
t_done('status_test');
