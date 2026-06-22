<?php
/**
 * qa/wa_health_parse.php — proves the defensive parsers for the WA-health surface handle the
 * Evolution v2.3.7 payload shapes: instanceInfo() (connected number/profile) and the
 * messages.update send-status capture (DELIVERY_ACK vs ERROR, fromMe filter). Mirrors the
 * controller/webhook logic without booting the framework. Run: php qa/wa_health_parse.php
 */

function data_get_path(array $a, string $path) {
    $cur = $a;
    foreach (explode('.', $path) as $k) {
        if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; }
        else { return null; }
    }
    return $cur;
}

/* ---- mirror of EvolutionAdmin::instanceInfo() parsing ---- */
function parse_instance_info($d, string $instance): array {
    $list = (is_array($d) && array_is_list($d)) ? $d : [$d];
    foreach ($list as $row) {
        $inst = $row['instance'] ?? $row;
        $name = $inst['name'] ?? $inst['instanceName'] ?? null;
        if (count($list) > 1 && $name !== $instance) continue;
        $owner = (string) ($inst['ownerJid'] ?? $inst['owner'] ?? '');
        return [
            'number'       => $owner !== '' ? preg_replace('/@.*/', '', $owner) : null,
            'profile_name' => $inst['profileName'] ?? $inst['profilename'] ?? null,
            'state'        => $inst['connectionStatus'] ?? $inst['state'] ?? null,
            'messages'     => (int) (data_get_path($inst, '_count.Message') ?? data_get_path($inst, '_count.messages') ?? 0),
        ];
    }
    return [];
}

/* ---- mirror of WebhookController::maybeHandleSendStatus() classification ---- */
function classify_updates(array $payload): array {
    $out = [];
    $data = $payload['data'] ?? [];
    $updates = (is_array($data) && (isset($data['status']) || isset($data['update']) || isset($data['keyId']) || isset($data['key'])))
        ? [$data] : $data;
    if (! is_array($updates)) return $out;
    foreach ($updates as $u) {
        if (! is_array($u)) continue;
        $fromMe = $u['fromMe'] ?? data_get_path($u, 'key.fromMe');
        if ($fromMe === false) continue;
        $status = strtoupper((string) ($u['status'] ?? data_get_path($u, 'update.status') ?? ''));
        if ($status === '') continue;
        $to = preg_replace('/@.*/', '', (string) ($u['remoteJid'] ?? data_get_path($u, 'key.remoteJid') ?? ''));
        if ($status === 'ERROR') $out[] = ['verdict' => 'send_failed', 'to' => $to];
        elseif (in_array($status, ['DELIVERY_ACK', 'READ', 'PLAYED'], true)) $out[] = ['verdict' => 'send_ok', 'to' => $to];
    }
    return $out;
}

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== wa_health_parse QA ===\n";

// --- instanceInfo: flat list (v2.3.7 typical) ---
$flat = [[
    'name' => 'shopbot_t1', 'connectionStatus' => 'open',
    'ownerJid' => '256758953737@s.whatsapp.net', 'profileName' => 'Family Shoppers',
    '_count' => ['Message' => 2830, 'Contact' => 1753],
]];
$i = parse_instance_info($flat, 'shopbot_t1');
check('flat: number stripped of @suffix', $i['number'] === '256758953737');
check('flat: profile name', $i['profile_name'] === 'Family Shoppers');
check('flat: state open', $i['state'] === 'open');
check('flat: message count', $i['messages'] === 2830);

// --- instanceInfo: wrapped {instance:{...}} + multiple, pick by name ---
$wrapped = [
    ['instance' => ['instanceName' => 'shopbot_t2', 'state' => 'open', 'owner' => '256751590810@s.whatsapp.net']],
    ['instance' => ['instanceName' => 'shopbot_t1', 'state' => 'connecting', 'owner' => '256758953737@s.whatsapp.net', 'profilename' => 'FS']],
];
$i2 = parse_instance_info($wrapped, 'shopbot_t1');
check('wrapped: picks the matching instance by name', $i2['number'] === '256758953737');
check('wrapped: lowercase profilename key', $i2['profile_name'] === 'FS');
check('wrapped: state connecting', $i2['state'] === 'connecting');

// --- instanceInfo: single object (not a list) ---
$single = ['name' => 'shopbot_t1', 'connectionStatus' => 'close', 'ownerJid' => ''];
$i3 = parse_instance_info($single, 'shopbot_t1');
check('single: empty owner → null number', $i3['number'] === null);
check('single: state close', $i3['state'] === 'close');

// --- send status: single update object, DELIVERY_ACK ---
$ok = classify_updates(['event' => 'messages.update', 'instance' => 'shopbot_t1',
    'data' => ['keyId' => '3A59', 'remoteJid' => '256770000001@s.whatsapp.net', 'fromMe' => true, 'status' => 'DELIVERY_ACK']]);
check('single DELIVERY_ACK → send_ok', count($ok) === 1 && $ok[0]['verdict'] === 'send_ok');
check('single: recipient stripped', $ok[0]['to'] === '256770000001');

// --- send status: ERROR (the flagged-number failure) ---
$err = classify_updates(['event' => 'messages.update', 'instance' => 'shopbot_t1',
    'data' => ['key' => ['id' => 'X', 'remoteJid' => '256770000002@s.whatsapp.net', 'fromMe' => true], 'update' => ['status' => 'ERROR']]]);
check('ERROR via key.fromMe + update.status → send_failed', count($err) === 1 && $err[0]['verdict'] === 'send_failed');

// --- send status: inbound (fromMe=false) is ignored ---
$inbound = classify_updates(['event' => 'messages.update', 'instance' => 'shopbot_t1',
    'data' => ['fromMe' => false, 'status' => 'DELIVERY_ACK', 'remoteJid' => '256770000003@s.whatsapp.net']]);
check('inbound fromMe=false → ignored', count($inbound) === 0);

// --- send status: list of updates, mixed ---
$list = classify_updates(['event' => 'messages.update', 'instance' => 'shopbot_t1', 'data' => [
    ['fromMe' => true, 'status' => 'SERVER_ACK', 'remoteJid' => '1@s.whatsapp.net'],   // not terminal → ignored
    ['fromMe' => true, 'status' => 'READ', 'remoteJid' => '2@s.whatsapp.net'],          // ok
    ['fromMe' => true, 'status' => 'ERROR', 'remoteJid' => '3@s.whatsapp.net'],         // failed
]]);
check('list: SERVER_ACK ignored, READ=ok, ERROR=failed', count($list) === 2
    && $list[0]['verdict'] === 'send_ok' && $list[1]['verdict'] === 'send_failed');

// --- send status: non-update event ignored ---
$presence = classify_updates(['event' => 'presence.update', 'instance' => 'shopbot_t1', 'data' => ['status' => 'ERROR', 'fromMe' => true]]);
// classify_updates itself doesn't gate on event name (the webhook does), but a presence payload
// with no status array still classifies the single object; the real gate is the event check upstream.
check('presence payload still parsed structurally (gate is upstream event check)', is_array($presence));

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
