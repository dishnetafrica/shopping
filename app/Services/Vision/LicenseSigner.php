<?php declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Signs device licenses with ShopBot's Ed25519 PRIVATE key (libsodium). The private key lives ONLY on
 * the server (config/secret) — never on a box. The box carries only the public key and verifies offline
 * (app/licensing/license.py). Canonicalisation is byte-identical to the edge's Python canonical()
 * (recursive ksort of objects, list order preserved, compact, slashes unescaped) — proven interoperable.
 *
 * NOT a licensing platform: just the signer the activation/heartbeat endpoints use. No reconciliation,
 * movement, shelf, or ERP code is touched.
 */
final class LicenseSigner
{
    /** Deterministic bytes to sign — must match app/licensing/license.py:canonical(). */
    public function canonical(array $payload): string
    {
        $this->ksortRecursive($payload);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function ksortRecursive(array &$a): void
    {
        if (! array_is_list($a)) {
            ksort($a);
        }
        foreach ($a as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    /** Return ['payload'=>..., 'signature'=>base64] — the exact shape the edge caches and verifies. */
    public function sign(array $payload): array
    {
        $sk = base64_decode((string) config('vision.license_secret', env('VISION_LICENSE_SECRET', '')), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('VISION_LICENSE_SECRET missing/invalid — cannot sign licenses');
        }
        $json = $this->canonical($payload);
        $sig  = sodium_crypto_sign_detached($json, $sk);

        return ['payload' => $payload, 'signature' => base64_encode($sig)];
    }

    /** The public key the edge image bundles (base64). Safe to expose. */
    public function publicKeyB64(): string
    {
        return (string) config('vision.license_public', env('VISION_LICENSE_PUBLIC', ''));
    }

    /** Verify a signature the DEVICE made (proves it holds its registered private key). */
    public function verifyDeviceSignature(string $devicePubkeyB64, string $message, string $signatureB64): bool
    {
        $pk  = base64_decode($devicePubkeyB64, true);
        $sig = base64_decode($signatureB64, true);
        if ($pk === false || $sig === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $message, $pk);
    }
}
