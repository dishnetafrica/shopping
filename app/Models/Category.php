<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'name', 'image_url', 'sort', 'active'];
    protected $casts = ['active' => 'boolean'];

    /**
     * v4.54 — `active = false` means "hidden from customers", and since v4.54 that is enforced on
     * every customer-facing surface rather than only the storefront feed. Toggling it therefore has
     * to drop the same caches a product change does, or a department hidden at 9:00 is still being
     * browsed, printed and quoted at 9:10.
     */
    protected static function booted(): void
    {
        $flush = function (self $c): void {
            try {
                $id = (int) $c->tenant_id;
                if (! $id) {
                    return;
                }
                \App\Services\Catalog\Visibility::forgetCategories($id);
                foreach ([
                    "catalogue:{$id}", "sf_cats:{$id}", "bot_catalog:{$id}",
                    "ai_catalogue:{$id}", "ai_catalogue_overview:{$id}", "ai_calc_catalogue:{$id}",
                    "catalog_pdf:{$id}:p", "catalog_pdf:{$id}:np", "trending:{$id}",
                ] as $key) {
                    \Illuminate\Support\Facades\Cache::forget($key);
                }
                $h = md5(mb_strtolower(trim((string) $c->name), 'UTF-8'));
                \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:cat:{$h}:p");
                \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:cat:{$h}:np");
            } catch (\Throwable $e) {
                // best-effort, exactly as on Product — never block the save
            }
        };
        static::saved($flush);
        static::deleted($flush);
    }
}
