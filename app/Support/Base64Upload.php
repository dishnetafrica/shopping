<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Store a base64 data-URL (image or PDF) that a schema-driven form's `file` field submits inline,
 * returning a public URL — or null if it's empty/invalid/too big. The form field value looks like
 * `data:image/jpeg;base64,....`; the extension is derived from the MIME type (the inline value carries
 * no filename). Mirrors the limits of the panel/link proof-upload paths, in one reusable place.
 */
final class Base64Upload
{
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'application/pdf' => 'pdf',
    ];

    /** @param int $maxBytes decoded size cap (default 20 MB) */
    public static function store(int $tenantId, ?string $dataUrl, int $maxBytes = 20971520): ?string
    {
        $dataUrl = trim((string) $dataUrl);
        if ($dataUrl === '') return null;

        // data:<mime>[;charset=...][;base64],<payload>
        if (! preg_match('#^data:([^;,]+)[^,]*,(.*)$#s', $dataUrl, $m)) return null;
        $ext = self::MIME_EXT[strtolower(trim($m[1]))] ?? null;
        if (! $ext) return null;                       // only images + pdf are allowed

        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) === 0 || strlen($bin) > $maxBytes) return null;

        $file = 'ops/' . $tenantId . '/' . uniqid('task_', true) . '.' . $ext;
        Storage::disk('public')->put($file, $bin);
        return url(Storage::url($file));
    }
}
