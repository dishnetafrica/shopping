<?php
/**
 * qa/customer_track_ui.php — proves the Phase 5 customer-page rules: rider reveal gating, delivery-proof
 * visibility, ETA label/visibility, and the ✓/●/○ timeline classes. Mirrors TrackController::show()
 * presentation logic without the framework. Run: php qa/customer_track_ui.php
 */

function collected(?string $dlv, string $order): bool {
    return ($dlv !== null && in_array($dlv, ['picked', 'out', 'delivered'], true))
        || in_array($order, ['Out for delivery', 'Delivered'], true);
}
function delivered_done(?string $dlv, string $order): bool {
    return ($dlv === 'delivered') || strcasecmp($order, 'Delivered') === 0;
}
function rider_shown(bool $hasRider, ?string $dlv, string $order): bool {
    return $hasRider && collected($dlv, $order) && ! delivered_done($dlv, $order);
}
function proof_shown(?string $dlv, string $order): bool { return delivered_done($dlv, $order); }
function eta_label(int $idx): string { return $idx >= 2 ? 'Expected delivery' : 'Estimated arrival'; }
function eta_shown(?string $dlv, string $order, bool $hasEta): bool { return ! delivered_done($dlv, $order) && $hasEta; }
function step_class(int $i, int $idx): string { return $i < $idx ? 'done' : ($i === $idx ? 'cur' : 'todo'); }
function step_icon(int $i, int $idx): string { return $i < $idx ? '✓' : ($i === $idx ? '●' : ''); }

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== customer_track_ui QA ===\n";

// rider reveal — hidden until collected, hidden again once delivered
check('rider hidden while in transit (no delivery)',      ! rider_shown(true, null, 'Confirmed'));
check('rider hidden when only assigned (not collected)',  ! rider_shown(true, 'assigned', 'Confirmed'));
check('rider hidden when awaiting',                       ! rider_shown(true, 'awaiting', 'Confirmed'));
check('rider SHOWN once collected (picked)',                rider_shown(true, 'picked', 'Confirmed'));
check('rider SHOWN when out',                               rider_shown(true, 'out', 'Confirmed'));
check('rider hidden again after delivered',               ! rider_shown(true, 'delivered', 'Delivered'));
check('rider hidden when there is no rider',              ! rider_shown(false, 'out', 'Confirmed'));

// delivery proof
check('proof hidden before delivery',     ! proof_shown('out', 'Out for delivery'));
check('proof shown when delivery delivered', proof_shown('delivered', 'Confirmed'));
check('proof shown when order marked Delivered', proof_shown(null, 'Delivered'));

// ETA — never shown once delivered; label depends on stage
check('eta hidden after delivered',       ! eta_shown('delivered', 'Delivered', true));
check('eta hidden when no ETA set',       ! eta_shown('out', 'Confirmed', false));
check('eta shown when set + not delivered', eta_shown('picked', 'Confirmed', true));
check('label = Estimated arrival pre-arrival (idx<2)', eta_label(1) === 'Estimated arrival');
check('label = Expected delivery at/after arrival (idx>=2)', eta_label(2) === 'Expected delivery');

// timeline classes/icons for idx=2 over a 5-step chain (✓✓●○○)
$cls = []; $ic = [];
for ($i = 0; $i < 5; $i++) { $cls[] = step_class($i, 2); $ic[] = step_icon($i, 2); }
check('classes ✓✓●○○ → done,done,cur,todo,todo', $cls === ['done', 'done', 'cur', 'todo', 'todo']);
check('icons → ✓ ✓ ● (blank) (blank)', $ic === ['✓', '✓', '●', '', '']);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
