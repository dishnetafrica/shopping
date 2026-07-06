<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;

/** Opaque identifier. UUIDv4 in production. */
final class Id
{
    public static function new(string $prefix = ''): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $u = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        return $prefix ? "{$prefix}_{$u}" : $u;
    }
}
