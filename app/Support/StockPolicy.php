<?php

namespace App\Support;

/**
 * Does this business track stock? Setting `track_stock` (default TRUE — every existing shop keeps
 * today's behaviour). A business that does not keep stock counts in ShopBot (e.g. DishNet South Sudan,
 * which orders kits as it sells them) sets it to false: its products are then never refused or tagged
 * "out of stock" by the bot just because their stock figure is 0. The bot's prompt still tells the
 * model not to promise availability it cannot confirm (InventoryState::botTag "stock unknown").
 */
final class StockPolicy
{
    public static function tracked($tenant): bool
    {
        return $tenant === null || (bool) $tenant->setting('track_stock', true);
    }
}
