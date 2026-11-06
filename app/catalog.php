<?php
// Module and business-type catalog. Mirrors the PMS (admin/includes/module-presets.php).
// The panel records what each customer is licensed for and signs it into the licence token.

// key => label, in display order.
function catalog_modules(): array
{
    return [
        'bookings' => 'Bookings & Reservations',
        'housekeeping' => 'Housekeeping',
        'pos' => 'Point of Sale (till)',
        'stock' => 'Stock & Inventory',
        'conference' => 'Conference & Events',
        'gym' => 'Gym & Fitness',
        'finance' => 'Finance & Payments',
        'website_cms' => 'Website & CMS',
        'station_kds' => 'Kitchen Display',
        'station_bds' => 'Bar Display',
        'station_cds' => 'Coffee Bar Display',
        'station_room_service' => 'Room Service Station',
        'ai_assistant' => 'Assistant (add-on)',
    ];
}

// type => [label, modules ON]. ai_assistant is an add-on and is never part of a preset.
function catalog_business_types(): array
{
    return [
        'full_hotel' => ['Full Hotel', [
            'bookings', 'housekeeping', 'pos', 'stock', 'conference', 'gym', 'finance', 'website_cms',
            'station_kds', 'station_bds', 'station_cds', 'station_room_service',
        ]],
        'hotel_no_restaurant' => ['Hotel (no restaurant)', [
            'bookings', 'housekeeping', 'conference', 'gym', 'finance', 'website_cms',
        ]],
        'bar_restaurant' => ['Bar / Restaurant', ['pos', 'stock', 'finance', 'station_kds', 'station_bds']],
        'conference_venue' => ['Conference Venue', ['bookings', 'conference', 'finance', 'website_cms']],
        'gym_fitness' => ['Gym / Fitness', ['pos', 'gym', 'finance', 'website_cms']],
        'retail_shop' => ['Retail / Shop', ['pos', 'stock', 'finance']],
        'supermarket' => ['Supermarket', ['pos', 'stock', 'finance', 'website_cms']],
        'custom' => ['Custom', []],
    ];
}

function catalog_type_label(string $type): string
{
    $t = catalog_business_types();
    return isset($t[$type]) ? $t[$type][0] : $type;
}

// Keeps only known module keys, unique, in catalog order. Accepts any input; non-arrays give [].
function catalog_clean_modules($in): array
{
    if (!is_array($in)) {
        return [];
    }
    $want = [];
    foreach ($in as $k) {
        if (is_string($k)) {
            $want[$k] = true;
        }
    }
    $out = [];
    foreach (array_keys(catalog_modules()) as $key) {
        if (isset($want[$key])) {
            $out[] = $key;
        }
    }
    return $out;
}

// True when $modules is exactly the preset's modules, optionally plus the ai_assistant add-on.
function catalog_matches_preset(string $type, array $modules): bool
{
    $types = catalog_business_types();
    if (!isset($types[$type]) || $type === 'custom') {
        return false;
    }
    $got = catalog_clean_modules($modules);
    $got = array_values(array_diff($got, ['ai_assistant']));
    return $got === catalog_clean_modules($types[$type][1]);
}

// Modules licensed to a tenant row. A row with no stored list (created before modules existed)
// gets its business type's preset, so existing customers keep everything they had.
function catalog_tenant_modules(array $tenant): array
{
    $raw = $tenant['modules'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            return catalog_clean_modules($d);
        }
    }
    $types = catalog_business_types();
    $type = (string)($tenant['business_type'] ?? 'full_hotel');
    return isset($types[$type]) ? catalog_clean_modules($types[$type][1]) : [];
}
