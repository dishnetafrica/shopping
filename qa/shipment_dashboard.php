<?php
/**
 * qa/shipment_dashboard.php — pure-logic proof of the Logistics dashboard rules
 * (metrics, view membership, search, exception breakdown). Mirrors
 * ShipmentController::dashboard() without booting the framework. Run: php qa/shipment_dashboard.php
 */

$IN_TRANSIT = ['sent_to_transporter', 'transport_confirmed', 'in_transit'];
$DELAY_HOURS = 24;
$now = strtotime('2026-06-22 12:00:00');
$today = date('Y-m-d', $now);

// ---- sample fleet: [status, updated_at, arrived_at, order_status, transport, origin, dest, openEx[type...], number, order_no, customer, phone]
$ships = [
    ['packed',              '-2 hours',  null,                'Confirmed', 'BusCo', 'Kampala', 'Juba',  [],                 'SH-0001', 'ORD-1', 'Aisha',  '256770000001'],
    ['sent_to_transporter', '-3 hours',  null,                'Confirmed', 'BusCo', 'Kampala', 'Juba',  [],                 'SH-0002', 'ORD-2', 'Bok',    '256770000002'],
    ['in_transit',          '-40 hours', null,                'Dispatched','RoadX', 'Kampala', 'Gulu',  [],                 'SH-0003', 'ORD-3', 'Chol',   '256770000003'], // delayed
    ['in_transit',          '-2 hours',  null,                'Dispatched','RoadX', 'Kampala', 'Juba',  [],                 'SH-0004', 'ORD-4', 'Deng',   '256770000004'],
    ['arrived',             '-1 hours',  'today',             'Dispatched','BusCo', 'Kampala', 'Juba',  ['missing_boxes'],  'SH-0005', 'ORD-5', 'Esha',   '256770000005'], // arrived+today+exception
    ['arrived',             '-5 hours',  '-2 days',           'Delivered', 'BusCo', 'Kampala', 'Gulu',  [],                 'SH-0006', 'ORD-6', 'Faiz',   '256770000006'], // delivered
    ['arrived',             '-1 hours',  'today',             'Dispatched','RoadX', 'Mbale',   'Juba',  ['extra_boxes'],    'SH-0007', 'ORD-7', 'Gora',   '256770000007'], // arrived+today+exception
    ['cancelled',          '-6 hours',  null,                'Cancelled', 'BusCo', 'Kampala', 'Juba',  [],                 'SH-0008', 'ORD-8', 'Hawa',   '256770000008'],
    ['arrived',             '-1 hours',  'today',             'Dispatched','BusCo', 'Kampala', 'Juba',  ['damaged_boxes'],  'SH-0009', 'ORD-9', 'Ivan',   '256770000009'], // arrived+today+damaged
];

$rows = array_map(function ($s) use ($IN_TRANSIT, $DELAY_HOURS, $now, $today) {
    [$status, $upd, $arr, $ostatus, $trans, $orig, $dest, $ex, $num, $ono, $cust, $phone] = $s;
    $updTs = strtotime($upd, $now);
    $arrTs = $arr === null ? null : ($arr === 'today' ? $now : strtotime($arr, $now));
    $delivered = strcasecmp($ostatus, 'Delivered') === 0;
    $delayed = in_array($status, $IN_TRANSIT, true) && (($now - $updTs) / 3600) >= $DELAY_HOURS;
    return [
        'number' => $num, 'order_no' => $ono, 'customer' => $cust, 'phone' => $phone,
        'status' => $status, 'transport' => $trans, 'origin' => $orig, 'destination' => $dest,
        'ex' => $ex, 'exceptions' => count($ex),
        'delivered' => $delivered, 'delayed' => $delayed,
        'arrived_today' => $arrTs !== null && date('Y-m-d', $arrTs) === $today,
    ];
}, $ships);

// ---- metrics
$metrics = [
    'active' => count(array_filter($rows, fn ($r) => in_array($r['status'], array_merge(['packed'], $IN_TRANSIT), true))),
    'in_transit' => count(array_filter($rows, fn ($r) => in_array($r['status'], $IN_TRANSIT, true))),
    'delayed' => count(array_filter($rows, fn ($r) => $r['delayed'])),
    'exceptions' => count(array_filter($rows, fn ($r) => $r['exceptions'] > 0)),
    'completed_today' => count(array_filter($rows, fn ($r) => $r['arrived_today'])),
];

// ---- view filter
function view_filter(array $rows, string $view, array $IN_TRANSIT, array $f = []): array
{
    return array_values(array_filter($rows, function ($r) use ($view, $IN_TRANSIT, $f) {
        switch ($view) {
            case 'awaiting_dispatch': return $r['status'] === 'packed';
            case 'in_transit':        return in_array($r['status'], $IN_TRANSIT, true);
            case 'arrived':           return $r['status'] === 'arrived' && ! $r['delivered'];
            case 'delivered':         return $r['delivered'];
            case 'exception':
                if ($r['exceptions'] <= 0) return false;
                if (! empty($f['transport']) && $r['transport'] !== $f['transport']) return false;
                if (! empty($f['origin']) && $r['origin'] !== $f['origin']) return false;
                if (! empty($f['destination']) && $r['destination'] !== $f['destination']) return false;
                return true;
            default: return true;
        }
    }));
}

// ---- search
function search(array $rows, string $q): array
{
    if ($q === '') return $rows;
    $n = mb_strtolower($q);
    return array_values(array_filter($rows, function ($r) use ($n) {
        foreach (['number', 'order_no', 'customer', 'phone'] as $k) {
            if ($r[$k] !== null && mb_strpos(mb_strtolower((string) $r[$k]), $n) !== false) return true;
        }
        return false;
    }));
}

// ---- breakdown over a filtered exception set
function breakdown(array $rows): array
{
    $b = ['missing_boxes' => 0, 'extra_boxes' => 0, 'damaged_boxes' => 0];
    foreach ($rows as $r) foreach ($r['ex'] as $t) if (isset($b[$t])) $b[$t]++;
    return $b;
}

// ---------------------------------------------------------------- assertions
$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "  XX  $label\n"; } }

echo "=== shipment_dashboard QA ===\n";

// metrics
check('active = packed + in-transit pipeline (4)', $metrics['active'] === 4);     // 0001,0002,0003,0004
check('in_transit = 3', $metrics['in_transit'] === 3);                            // 0002,0003,0004
check('delayed = 1 (the 40h-stale in-transit)', $metrics['delayed'] === 1);      // 0003
check('exceptions = 3 shipments', $metrics['exceptions'] === 3);                 // 0005,0007,0009
check('completed_today = 3 arrived today', $metrics['completed_today'] === 3);   // 0005,0007,0009

// views
check('awaiting_dispatch = 1', count(view_filter($rows, 'awaiting_dispatch', $IN_TRANSIT)) === 1);
check('in_transit view = 3', count(view_filter($rows, 'in_transit', $IN_TRANSIT)) === 3);
check('arrived view excludes delivered = 3', count(view_filter($rows, 'arrived', $IN_TRANSIT)) === 3); // 0005,0007,0009 (0006 is delivered)
check('delivered view = 1', count(view_filter($rows, 'delivered', $IN_TRANSIT)) === 1);                // 0006
check('exception view = 3', count(view_filter($rows, 'exception', $IN_TRANSIT)) === 3);

// exception facet filters
$exBusCo = view_filter($rows, 'exception', $IN_TRANSIT, ['transport' => 'BusCo']);
check('exception + transport=BusCo = 2', count($exBusCo) === 2);                  // 0005,0009
$exMbale = view_filter($rows, 'exception', $IN_TRANSIT, ['origin' => 'Mbale']);
check('exception + origin=Mbale = 1', count($exMbale) === 1);                     // 0007
$exGulu = view_filter($rows, 'exception', $IN_TRANSIT, ['destination' => 'Gulu']);
check('exception + destination=Gulu = 0 (none have ex)', count($exGulu) === 0);

// breakdown over full exception set vs filtered
check('breakdown all = 1 missing / 1 extra / 1 damaged', breakdown(view_filter($rows, 'exception', $IN_TRANSIT)) === ['missing_boxes' => 1, 'extra_boxes' => 1, 'damaged_boxes' => 1]);
check('breakdown BusCo = 1 missing / 0 extra / 1 damaged', breakdown($exBusCo) === ['missing_boxes' => 1, 'extra_boxes' => 0, 'damaged_boxes' => 1]);

// search
check('search "ORD-3" = 1', count(search($rows, 'ORD-3')) === 1);
check('search "SH-0006" = 1', count(search($rows, 'SH-0006')) === 1);
check('search "deng" (customer, case-insens) = 1', count(search($rows, 'deng')) === 1);
check('search "256770000007" (phone) = 1', count(search($rows, '256770000007')) === 1);
check('search "zzz" = 0', count(search($rows, 'zzz')) === 0);
check('search within view: in_transit + "ORD-4" = 1', count(search(view_filter($rows, 'in_transit', $IN_TRANSIT), 'ORD-4')) === 1);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
