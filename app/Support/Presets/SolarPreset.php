<?php declare(strict_types=1);

namespace App\Support\Presets;

use App\Models\Tenant;
use App\Services\Catalog\ProductSpecService;

/**
 * Solar preset — the FIRST industry preset on the generic Asset Lifecycle platform.
 *
 * A preset does not add behaviour; it enables the relevant generic modules and seeds starter data.
 * Here it seeds the product-spec catalogue a solar dealer expects. Electrical / HVAC / generator
 * presets would seed a different catalogue against the SAME generic capability. Idempotent
 * (defineDefinition upserts by key), so re-running never duplicates.
 */
class SolarPreset
{
    /** Starter spec catalogue for solar equipment. Editable by the tenant afterward. */
    public const SPEC_CATALOGUE = [
        ['key' => 'wattage',           'label' => 'Wattage',            'unit' => 'W',  'data_type' => 'number', 'group' => 'Electrical', 'display_order' => 10],
        ['key' => 'voltage',           'label' => 'Voltage',            'unit' => 'V',  'data_type' => 'number', 'group' => 'Electrical', 'display_order' => 20],
        ['key' => 'current',           'label' => 'Current',            'unit' => 'A',  'data_type' => 'number', 'group' => 'Electrical', 'display_order' => 30],
        ['key' => 'power_rating',      'label' => 'Power Rating',       'unit' => 'W',  'data_type' => 'number', 'group' => 'Electrical', 'display_order' => 40],
        ['key' => 'capacity_ah',       'label' => 'Capacity',           'unit' => 'Ah', 'data_type' => 'number', 'group' => 'Battery',    'display_order' => 50],
        ['key' => 'battery_chemistry', 'label' => 'Battery Chemistry',  'unit' => null, 'data_type' => 'enum',   'group' => 'Battery',    'display_order' => 60,
            'options' => ['LiFePO4', 'Lead-Acid', 'AGM', 'Gel', 'Li-ion']],
        ['key' => 'lumens',            'label' => 'Lumens',             'unit' => 'lm', 'data_type' => 'number', 'group' => 'Lighting',   'display_order' => 70],
        ['key' => 'color_temperature', 'label' => 'Colour Temperature', 'unit' => 'K',  'data_type' => 'number', 'group' => 'Lighting',   'display_order' => 80],
        ['key' => 'cable_length',      'label' => 'Cable Length',       'unit' => 'm',  'data_type' => 'number', 'group' => 'Physical',   'display_order' => 90],
        ['key' => 'ip_rating',         'label' => 'IP Rating',          'unit' => null, 'data_type' => 'text',   'group' => 'Physical',   'display_order' => 100],
    ];

    /** Seed the solar spec catalogue for a tenant. Idempotent. Returns the number of definitions. */
    public static function seedSpecCatalogue(Tenant $t): int
    {
        $svc = ProductSpecService::forTenant($t);
        foreach (self::SPEC_CATALOGUE as $def) {
            $svc->defineDefinition($def);
        }
        return count(self::SPEC_CATALOGUE);
    }
}
