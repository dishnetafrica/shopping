<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Starter catalogues by business type. A brand-new shop with an empty catalogue is the #1 place
 * merchants stall, so we offer a one-tap set of realistic sample products they can edit — the
 * Dukaan "pick your type → products appear" activation trick. Everything here is a placeholder the
 * owner edits; prices are round sample numbers, lightly scaled up for zero-decimal currencies
 * (e.g. UGX) so "5" doesn't render as a fraction of a shilling.
 */
class StarterCatalogue
{
    /** Which business types have a tailored set (others fall back to 'other'). */
    public static function kinds(): array
    {
        return ['grocery', 'manufacturer', 'restaurant', 'pharmacy', 'electronics', 'other'];
    }

    /**
     * Sample products for a tenant's business type, priced in the tenant's currency scale.
     * @return array<int,array{name:string,category:string,price:float,unit_label:?string,moq:?int,pack_size:?int,keywords:string}>
     */
    public static function for(Tenant $tenant): array
    {
        $kind = strtolower((string) $tenant->setting('bot_kind', 'other'));
        if (! in_array($kind, self::kinds(), true)) $kind = 'other';

        $rows  = self::raw()[$kind] ?? self::raw()['other'];
        $scale = self::priceScale($tenant);

        return array_map(function (array $r) use ($scale) {
            $r['price']      = self::scalePrice((float) $r['price'], $scale);
            $r['unit_label'] = $r['unit_label'] ?? null;
            $r['moq']        = $r['moq'] ?? null;
            $r['pack_size']  = $r['pack_size'] ?? null;
            $r['keywords']   = $r['keywords'] ?? '';
            return $r;
        }, $rows);
    }

    public static function countFor(Tenant $tenant): int
    {
        return count(self::for($tenant));
    }

    /** 0-decimal currencies (UGX/SSP/RWF…) get sample prices ~×1000 so they aren't laughably small. */
    private static function priceScale(Tenant $tenant): int
    {
        $cur = (string) $tenant->setting('currency', 'UGX');
        return \App\Services\Pricing::decimalsForCurrency($cur) === 0 ? 1000 : 1;
    }

    private static function scalePrice(float $base, int $scale): float
    {
        if ($scale === 1) return $base;
        return round($base * $scale / 100) * 100; // nearest 100 in the big-unit currency
    }

    /** Base sample sets (prices in small "USD-ish" units; scaled per currency at read time). */
    private static function raw(): array
    {
        return [
            'grocery' => [
                ['name' => 'Rice 5kg',              'category' => 'Staples',    'price' => 8,  'unit_label' => 'bag',    'keywords' => 'rice grain staple'],
                ['name' => 'Cooking Oil 1L',        'category' => 'Staples',    'price' => 3,  'unit_label' => 'bottle', 'keywords' => 'oil cooking vegetable'],
                ['name' => 'Sugar 1kg',             'category' => 'Staples',    'price' => 2,  'unit_label' => 'pack',   'keywords' => 'sugar sweet'],
                ['name' => 'Wheat Flour 2kg',       'category' => 'Staples',    'price' => 3,  'unit_label' => 'pack',   'keywords' => 'flour wheat baking'],
                ['name' => 'Fresh Milk 1L',         'category' => 'Dairy',      'price' => 2,  'unit_label' => 'packet', 'keywords' => 'milk dairy'],
                ['name' => 'Eggs (tray of 30)',     'category' => 'Dairy',      'price' => 5,  'unit_label' => 'tray',   'keywords' => 'eggs tray'],
                ['name' => 'Bread Loaf',            'category' => 'Bakery',     'price' => 1,  'unit_label' => 'loaf',   'keywords' => 'bread loaf bakery'],
                ['name' => 'Tea Leaves 500g',       'category' => 'Beverages',  'price' => 3,  'unit_label' => 'pack',   'keywords' => 'tea chai leaves'],
                ['name' => 'Bar Soap',              'category' => 'Household',  'price' => 1,  'unit_label' => 'bar',    'keywords' => 'soap wash laundry'],
                ['name' => 'Drinking Water 1.5L',   'category' => 'Beverages',  'price' => 1,  'unit_label' => 'bottle', 'keywords' => 'water bottle drink'],
            ],
            'manufacturer' => [
                ['name' => 'Toilet Tissue (pack of 10)',  'category' => 'Tissue',     'price' => 6,  'unit_label' => 'pack',   'moq' => 10, 'pack_size' => 10, 'keywords' => 'toilet tissue roll bathroom'],
                ['name' => 'Facial Tissue (box)',         'category' => 'Tissue',     'price' => 2,  'unit_label' => 'box',    'moq' => 20, 'keywords' => 'facial tissue box soft'],
                ['name' => 'Kitchen Towel (pack of 6)',   'category' => 'Tissue',     'price' => 5,  'unit_label' => 'pack',   'moq' => 10, 'pack_size' => 6, 'keywords' => 'kitchen towel paper'],
                ['name' => 'Serviettes (pack of 100)',    'category' => 'Tissue',     'price' => 2,  'unit_label' => 'pack',   'moq' => 20, 'pack_size' => 100, 'keywords' => 'serviette napkin'],
                ['name' => 'Carton Box (medium)',         'category' => 'Packaging',  'price' => 1,  'unit_label' => 'piece',  'moq' => 50, 'keywords' => 'carton box packaging'],
                ['name' => 'Paper Bag (pack of 50)',      'category' => 'Packaging',  'price' => 3,  'unit_label' => 'pack',   'moq' => 20, 'pack_size' => 50, 'keywords' => 'paper bag packaging'],
                ['name' => 'Cling Film Roll',             'category' => 'Packaging',  'price' => 2,  'unit_label' => 'roll',   'moq' => 12, 'keywords' => 'cling film wrap'],
                ['name' => 'Disposable Cups (pack of 50)','category' => 'Packaging',  'price' => 2,  'unit_label' => 'pack',   'moq' => 20, 'pack_size' => 50, 'keywords' => 'cups disposable paper'],
            ],
            'restaurant' => [
                ['name' => 'Chicken Biryani',   'category' => 'Mains',    'price' => 6,  'keywords' => 'biryani chicken rice'],
                ['name' => 'Beef Burger',       'category' => 'Mains',    'price' => 5,  'keywords' => 'burger beef'],
                ['name' => 'Margherita Pizza',  'category' => 'Mains',    'price' => 8,  'keywords' => 'pizza cheese'],
                ['name' => 'Vegetable Fried Rice','category' => 'Mains',  'price' => 4,  'keywords' => 'fried rice veg'],
                ['name' => 'Grilled Fish',      'category' => 'Mains',    'price' => 7,  'keywords' => 'fish grilled'],
                ['name' => 'Chapati',           'category' => 'Sides',    'price' => 1,  'keywords' => 'chapati bread side'],
                ['name' => 'Samosa (2 pcs)',    'category' => 'Sides',    'price' => 1,  'pack_size' => 2, 'keywords' => 'samosa snack'],
                ['name' => 'Soft Drink 500ml',  'category' => 'Drinks',   'price' => 1,  'unit_label' => 'bottle', 'keywords' => 'soda drink cola'],
                ['name' => 'Fresh Juice',       'category' => 'Drinks',   'price' => 2,  'unit_label' => 'glass',  'keywords' => 'juice fresh'],
                ['name' => 'Ice Cream Scoop',   'category' => 'Desserts', 'price' => 2,  'keywords' => 'ice cream dessert'],
            ],
            'pharmacy' => [
                ['name' => 'Paracetamol 500mg (strip)', 'category' => 'Over-the-counter', 'price' => 1, 'unit_label' => 'strip', 'keywords' => 'paracetamol pain fever'],
                ['name' => 'Vitamin C Tablets',         'category' => 'Wellness',         'price' => 3, 'unit_label' => 'bottle','keywords' => 'vitamin c immune'],
                ['name' => 'Antiseptic Liquid 500ml',   'category' => 'First Aid',        'price' => 3, 'unit_label' => 'bottle','keywords' => 'antiseptic wound clean'],
                ['name' => 'Bandage Roll',              'category' => 'First Aid',        'price' => 1, 'unit_label' => 'roll',  'keywords' => 'bandage dressing'],
                ['name' => 'Hand Sanitizer 250ml',      'category' => 'Hygiene',          'price' => 2, 'unit_label' => 'bottle','keywords' => 'sanitizer hygiene'],
                ['name' => 'Face Masks (box of 50)',    'category' => 'Hygiene',          'price' => 4, 'unit_label' => 'box', 'pack_size' => 50, 'keywords' => 'mask face protection'],
                ['name' => 'ORS Sachets (pack of 10)',  'category' => 'Wellness',         'price' => 2, 'unit_label' => 'pack','pack_size' => 10, 'keywords' => 'ors rehydration'],
                ['name' => 'Multivitamin Tablets',      'category' => 'Wellness',         'price' => 4, 'unit_label' => 'bottle','keywords' => 'multivitamin supplement'],
                ['name' => 'Digital Thermometer',       'category' => 'Devices',          'price' => 5, 'unit_label' => 'piece','keywords' => 'thermometer temperature'],
            ],
            'electronics' => [
                ['name' => 'USB-C Charger',           'category' => 'Power',       'price' => 6,  'keywords' => 'charger usb-c fast'],
                ['name' => 'Earphones',               'category' => 'Audio',       'price' => 5,  'keywords' => 'earphones headset'],
                ['name' => 'Power Bank 10000mAh',     'category' => 'Power',       'price' => 15, 'keywords' => 'power bank battery portable'],
                ['name' => 'HDMI Cable 2m',           'category' => 'Cables',      'price' => 4,  'keywords' => 'hdmi cable tv'],
                ['name' => 'LED Bulb 9W',             'category' => 'Lighting',    'price' => 2,  'keywords' => 'led bulb light'],
                ['name' => 'Extension Cord (4-way)',  'category' => 'Power',       'price' => 7,  'keywords' => 'extension cord socket'],
                ['name' => 'Phone Case',              'category' => 'Accessories', 'price' => 3,  'keywords' => 'phone case cover'],
                ['name' => 'Memory Card 32GB',        'category' => 'Storage',     'price' => 8,  'keywords' => 'memory card sd storage'],
                ['name' => 'Bluetooth Speaker',       'category' => 'Audio',       'price' => 18, 'keywords' => 'speaker bluetooth wireless'],
                ['name' => 'USB Flash Drive 16GB',    'category' => 'Storage',     'price' => 5,  'keywords' => 'flash drive usb storage'],
            ],
            'other' => [
                ['name' => 'Sample Product 1', 'category' => 'General', 'price' => 5, 'keywords' => 'sample'],
                ['name' => 'Sample Product 2', 'category' => 'General', 'price' => 10, 'keywords' => 'sample'],
                ['name' => 'Sample Product 3', 'category' => 'General', 'price' => 3, 'keywords' => 'sample'],
                ['name' => 'Sample Product 4', 'category' => 'General', 'price' => 8, 'keywords' => 'sample'],
                ['name' => 'Sample Service',   'category' => 'Services','price' => 20, 'keywords' => 'sample service'],
            ],
        ];
    }
}
