<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an uploaded product image and returns a full public URL. Mirrors the legacy panel's
 * uploadImage exactly (base64 -> public disk under products/<tenant>/, absolute URL out) so v3
 * photos land in the same place the bot and catalogue already read from. No business logic.
 */
final class ImageUploadService
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** @throws \InvalidArgumentException on empty/invalid data */
    public function store(Tenant $t, string $data, string $name = 'upload'): string
    {
        if ($data === '') {
            throw new \InvalidArgumentException('no_data');
        }
        // Strip an optional data-URI prefix ("data:image/png;base64,....").
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }
        $bin = base64_decode($data, true);
        if ($bin === false) {
            throw new \InvalidArgumentException('bad_base64');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
        if (! in_array($ext, self::ALLOWED, true)) {
            $ext = 'jpg';
        }

        $file = 'products/' . ((int) $t->id) . '/' . uniqid('p_', true) . '.' . $ext;
        Storage::disk('public')->put($file, $bin);

        return url(Storage::url($file));
    }
}
