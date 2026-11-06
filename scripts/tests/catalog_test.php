<?php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/catalog.php';
require __DIR__ . '/../../app/tenants.php';

$mods = catalog_modules();
t_eq('13 modules', 13, count($mods));
t_eq('module keys and order', [
    'bookings', 'housekeeping', 'pos', 'stock', 'conference', 'gym', 'finance', 'website_cms',
    'station_kds', 'station_bds', 'station_cds', 'station_room_service', 'ai_assistant',
], array_keys($mods));
t_eq('label bookings', 'Bookings & Reservations', $mods['bookings']);
t_eq('label pos', 'Point of Sale (till)', $mods['pos']);
t_eq('label ai', 'Assistant (add-on)', $mods['ai_assistant']);
t_eq('label room service', 'Room Service Station', $mods['station_room_service']);

$types = catalog_business_types();
$expected = [
    'full_hotel' => ['Full Hotel', ['bookings', 'housekeeping', 'pos', 'stock', 'conference', 'gym', 'finance', 'website_cms', 'station_kds', 'station_bds', 'station_cds', 'station_room_service']],
    'hotel_no_restaurant' => ['Hotel (no restaurant)', ['bookings', 'housekeeping', 'conference', 'gym', 'finance', 'website_cms']],
    'bar_restaurant' => ['Bar / Restaurant', ['pos', 'stock', 'finance', 'station_kds', 'station_bds']],
    'conference_venue' => ['Conference Venue', ['bookings', 'conference', 'finance', 'website_cms']],
    'gym_fitness' => ['Gym / Fitness', ['pos', 'gym', 'finance', 'website_cms']],
    'retail_shop' => ['Retail / Shop', ['pos', 'stock', 'finance']],
    'supermarket' => ['Supermarket', ['pos', 'stock', 'finance', 'website_cms']],
    'custom' => ['Custom', []],
];
t_eq('type keys', array_keys($expected), array_keys($types));
foreach ($expected as $k => [$label, $m]) {
    t_eq("label $k", $label, $types[$k][0]);
    t_eq("modules $k", $m, $types[$k][1]);
    t_ok("preset $k has no ai_assistant", !in_array('ai_assistant', $types[$k][1], true));
    foreach ($types[$k][1] as $mk) {
        t_ok("preset $k module $mk is known", isset($mods[$mk]));
    }
}
t_eq('type label lookup', 'Supermarket', catalog_type_label('supermarket'));

// clean_modules
t_eq('clean result in catalog order', ['pos', 'stock'], catalog_clean_modules(['stock', 'bogus', 'pos']));
t_eq('clean unique and catalog order', ['bookings', 'finance'], catalog_clean_modules(['finance', 'bookings', 'finance']));
t_eq('clean ignores non-strings', ['pos'], catalog_clean_modules(['pos', 5, null, ['stock']]));
t_eq('clean non-array', [], catalog_clean_modules('pos'));
t_eq('clean null', [], catalog_clean_modules(null));

// tenant_validate
function base(array $over = []): array
{
    return array_merge([
        'slug' => 'my-shop', 'name' => 'My Shop', 'domain' => 'shop.example.com', 'plan' => 'basic',
        'currency' => 'MWK', 'paid_until' => '2026-12-31', 'monthly_fee' => '1000', 'grace_days' => '14',
        'readonly_days' => '14', 'notes' => '',
    ], $over);
}
[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock', 'finance']]), true);
t_eq('preset match keeps type', 'retail_shop', $c['business_type']);
t_eq('preset match no errors', [], $err);
t_eq('modules json', '["pos","stock","finance"]', $c['modules_json']);

[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock', 'finance', 'ai_assistant']]), true);
t_eq('preset plus assistant keeps type', 'retail_shop', $c['business_type']);
t_eq('assistant kept in modules', ['pos', 'stock', 'finance', 'ai_assistant'], $c['modules']);

[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock', 'finance', 'gym']]), true);
t_eq('extra module switches to custom', 'custom', $c['business_type']);
t_eq('extra module is not an error', [], $err);

[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock']]), true);
t_eq('missing module switches to custom', 'custom', $c['business_type']);

[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock', 'finance', 'nonsense']]), true);
t_eq('unknown key dropped, still preset', 'retail_shop', $c['business_type']);

[$err, $c] = tenant_validate(base(['business_type' => 'retail_shop']), true);
t_eq('no modules posted switches to custom', 'custom', $c['business_type']);
t_eq('no modules posted -> empty list', [], $c['modules']);

[$err, $c] = tenant_validate(base(['business_type' => 'custom', 'modules' => ['gym']]), true);
t_eq('custom stays custom', 'custom', $c['business_type']);
t_eq('custom modules', ['gym'], $c['modules']);

[$err, $c] = tenant_validate(base(['business_type' => 'spaceship', 'modules' => ['gym']]), true);
t_eq('unknown type is an error', 1, count($err));
t_eq('unknown type becomes custom', 'custom', $c['business_type']);

[$err, $c] = tenant_validate(base(['business_type' => 'full_hotel', 'modules' => array_keys(catalog_modules())]), true);
t_eq('full hotel plus assistant ok', 'full_hotel', $c['business_type']);

[$err] = tenant_validate(base(['name' => '', 'business_type' => 'custom']), true);
t_ok('error says business, not hotel', count($err) === 1 && strpos($err[0], 'Business') === 0 && stripos($err[0], 'hotel') === false);
[$err] = tenant_validate(base(['domain' => '', 'business_type' => 'custom']), true);
t_ok('domain error says business', count($err) === 1 && stripos($err[0], 'hotel') === false);

// Tenant row -> modules
t_eq('null modules use preset', catalog_business_types()['supermarket'][1], catalog_tenant_modules(['business_type' => 'supermarket', 'modules' => null]));
t_eq('legacy row (no columns) is full hotel', catalog_business_types()['full_hotel'][1], catalog_tenant_modules([]));
t_eq('stored modules win', ['gym'], catalog_tenant_modules(['business_type' => 'custom', 'modules' => '["gym","bogus"]']));
t_eq('stored empty list stays empty', [], catalog_tenant_modules(['business_type' => 'custom', 'modules' => '[]']));

t_done('catalog_test');
