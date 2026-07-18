<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dependency-free Web Push (same philosophy as the RFC 6238 TOTP build: standards
 * implemented directly, zero composer additions, nothing to break a Rebuild).
 *
 *  - VAPID (RFC 8292): ES256 JWT signed with a P-256 key we generate once and keep
 *    in the push_keys table.
 *  - Payload encryption (RFC 8291, aes128gcm): ECDH over P-256 + HKDF + AES-128-GCM,
 *    all via PHP's bundled openssl.
 *
 * Fail-soft EVERYWHERE: a push must never break a webhook or a page. Dead
 * subscriptions (404/410) are pruned automatically.
 */
class WebPushService
{
    private const CURVE_DER_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    // ------------------------------------------------------------ public API

    /** The VAPID public key (base64url, raw 65-byte point) — creates the pair on first call. */
    public function publicKey(): string
    {
        $row = DB::table('push_keys')->first();
        if ($row) return (string) $row->public_key;

        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) return '';
        openssl_pkey_export($res, $privPem);
        $det = openssl_pkey_get_details($res);
        $pub = self::b64u("\x04".$det['ec']['x'].$det['ec']['y']);

        DB::table('push_keys')->insert([
            'public_key' => $pub, 'private_pem' => $privPem,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $pub;
    }

    /** Store/refresh a browser subscription for this user. */
    public function subscribe(int $tenantId, int $userId, string $endpoint, string $p256dh, string $auth): void
    {
        PushSubscription::updateOrCreate(
            ['endpoint' => $endpoint],
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'p256dh' => $p256dh, 'auth' => $auth]
        );
    }

    /**
     * New inbound WhatsApp message → buzz every subscribed device on the tenant.
     * Throttled per phone (60s) so a burst of messages is ONE buzz, not ten.
     */
    public function notifyInbound(int $tenantId, string $phone, string $name, string $preview): void
    {
        try {
            $gate = 'push:in:'.$tenantId.':'.$phone;
            if (Cache::get($gate)) return;
            Cache::put($gate, 1, 60);

            $this->sendToTenant($tenantId,
                $name !== '' ? $name : 'New WhatsApp message',
                mb_substr($preview !== '' ? $preview : 'sent a message', 0, 90),
                '/v3#/chats');
        } catch (\Throwable $e) {
            // never let a push failure touch the message pipeline
        }
    }

    /** Push a notification to every subscription of a tenant; prune the dead ones. */
    public function sendToTenant(int $tenantId, string $title, string $body, string $url = '/v3'): int
    {
        $subs = PushSubscription::where('tenant_id', $tenantId)->get();
        $sent = 0;
        foreach ($subs as $s) {
            try {
                $code = $this->push($s, json_encode(['title' => $title, 'body' => $body, 'url' => $url]));
                if (in_array($code, [404, 410], true)) {
                    $s->delete();                    // browser revoked it — clean up
                } elseif ($code >= 200 && $code < 300) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::debug('webpush send failed: '.$e->getMessage());
            }
        }

        return $sent;
    }

    // -------------------------------------------------------- the standards

    private function push(PushSubscription $s, string $payload): int
    {
        $keys = DB::table('push_keys')->first();
        if (! $keys) { $this->publicKey(); $keys = DB::table('push_keys')->first(); }
        if (! $keys) return 0;

        $endpoint = (string) $s->endpoint;
        $aud = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);

        // ---- VAPID JWT (ES256) ------------------------------------------
        $jwtHead = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $jwtBody = self::b64u(json_encode([
            'aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:owner@mycloudbss.com',
        ]));
        openssl_sign($jwtHead.'.'.$jwtBody, $der, $keys->private_pem, OPENSSL_ALGO_SHA256);
        $jwt = $jwtHead.'.'.$jwtBody.'.'.self::b64u(self::derToRaw($der));

        // ---- RFC 8291 aes128gcm encryption ------------------------------
        $clientPub = self::b64uDec((string) $s->p256dh);       // 65-byte uncompressed point
        $authSecret = self::b64uDec((string) $s->auth);        // 16 bytes
        if (strlen($clientPub) !== 65 || strlen($authSecret) < 16) return 0;

        // ephemeral server key
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ephDet = openssl_pkey_get_details($eph);
        $srvPub = "\x04".$ephDet['ec']['x'].$ephDet['ec']['y'];

        // client point → PEM so openssl can ECDH against it
        $clientPem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::CURVE_DER_PREFIX).$clientPub), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($clientPem), $eph, 32);
        if ($shared === false) return 0;

        $ikm = self::hkdf($authSecret, $shared, 'WebPush: info'."\x00".$clientPub.$srvPub, 32);
        $salt = random_bytes(16);
        $cek   = self::hkdf($salt, $ikm, 'Content-Encoding: aes128gcm'."\x00", 16);
        $nonce = self::hkdf($salt, $ikm, 'Content-Encoding: nonce'."\x00", 12);

        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $body = $salt.pack('N', 4096).chr(strlen($srvPub)).$srvPub.$cipher.$tag;

        $resp = Http::withHeaders([
            'Authorization'    => 'vapid t='.$jwt.', k='.$keys->public_key,
            'Content-Encoding' => 'aes128gcm',
            'Content-Type'     => 'application/octet-stream',
            'TTL'              => '86400',
            'Urgency'          => 'high',
        ])->withBody($body, 'application/octet-stream')->post($endpoint);

        return $resp->status();
    }

    /** RFC 5869 HKDF (extract + one expand block — lengths here never exceed 32). */
    private static function hkdf(string $salt, string $ikm, string $info, int $len): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $len);
    }

    /** DER ECDSA signature → raw r||s (64 bytes) as JOSE requires. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;                                             // SEQUENCE header
        if (ord($der[1]) > 0x80) $pos += ord($der[1]) - 0x80; // long-form length
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++;                                           // INTEGER tag
            $l = ord($der[$pos++]);
            $int = substr($der, $pos, $l);
            $pos += $l;
            $int = ltrim($int, "\x00");
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }

        return $out;
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64uDec(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
