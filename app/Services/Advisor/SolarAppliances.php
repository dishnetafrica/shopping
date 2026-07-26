<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Experience Center — the appliance library.
 *
 * Customers do not know kWh; they know their appliances. This maps common household/business appliances
 * to typical power (W) and daily run-hours so a customer can pick "TV, fridge, AC" and the deterministic
 * {@see SolarSizingService} sizes the system behind the scenes. Duty-cycle appliances (fridge/freezer)
 * use an effective daily-hours figure. Data-only; editable per deployment.
 */
class SolarAppliances
{
    /** key => [name, icon, watts, hours (effective/day), critical (needs backup), surge, category]. */
    public const APPLIANCES = [
        'lights'    => ['name' => 'LED lights (per bulb)', 'icon' => '💡', 'watts' => 10,   'hours' => 5,   'critical' => true,  'surge' => 1, 'category' => 'Essentials'],
        'phone'     => ['name' => 'Phone charging',        'icon' => '🔌', 'watts' => 10,   'hours' => 3,   'critical' => true,  'surge' => 1, 'category' => 'Essentials'],
        'tv'        => ['name' => 'TV',                    'icon' => '📺', 'watts' => 80,   'hours' => 4,   'critical' => true,  'surge' => 1, 'category' => 'Essentials'],
        'fridge'    => ['name' => 'Refrigerator',          'icon' => '🧊', 'watts' => 150,  'hours' => 8,   'critical' => true,  'surge' => 2, 'category' => 'Essentials'],
        'freezer'   => ['name' => 'Deep freezer',          'icon' => '❄️', 'watts' => 200,  'hours' => 10,  'critical' => true,  'surge' => 2, 'category' => 'Essentials'],
        'fan'       => ['name' => 'Fan',                   'icon' => '🌀', 'watts' => 60,   'hours' => 8,   'critical' => false, 'surge' => 1, 'category' => 'Comfort'],
        'ac'        => ['name' => 'Air conditioner',       'icon' => '🌡️', 'watts' => 1200, 'hours' => 6,   'critical' => false, 'surge' => 3, 'category' => 'Comfort'],
        'router'    => ['name' => 'WiFi router',           'icon' => '📶', 'watts' => 15,   'hours' => 24,  'critical' => true,  'surge' => 1, 'category' => 'Work'],
        'computer'  => ['name' => 'Computer / laptop',     'icon' => '💻', 'watts' => 150,  'hours' => 6,   'critical' => false, 'surge' => 1, 'category' => 'Work'],
        'printer'   => ['name' => 'Printer',               'icon' => '🖨️', 'watts' => 300,  'hours' => 1,   'critical' => false, 'surge' => 1, 'category' => 'Work'],
        'cctv'      => ['name' => 'CCTV / security',       'icon' => '📹', 'watts' => 40,   'hours' => 24,  'critical' => true,  'surge' => 1, 'category' => 'Security'],
        'pump'      => ['name' => 'Water pump',            'icon' => '💧', 'watts' => 750,  'hours' => 3,   'critical' => false, 'surge' => 3, 'category' => 'Water'],
        'borehole'  => ['name' => 'Borehole pump',         'icon' => '🚰', 'watts' => 1500, 'hours' => 5,   'critical' => false, 'surge' => 3, 'category' => 'Water'],
        'microwave' => ['name' => 'Microwave',             'icon' => '🍲', 'watts' => 1000, 'hours' => 0.5, 'critical' => false, 'surge' => 1, 'category' => 'Heavy'],
        'kettle'    => ['name' => 'Electric kettle',       'icon' => '☕', 'watts' => 1500, 'hours' => 0.3, 'critical' => false, 'surge' => 1, 'category' => 'Heavy'],
        'iron'      => ['name' => 'Iron',                  'icon' => '👔', 'watts' => 1000, 'hours' => 0.5, 'critical' => false, 'surge' => 1, 'category' => 'Heavy'],
        'washer'    => ['name' => 'Washing machine',       'icon' => '🧺', 'watts' => 500,  'hours' => 1,   'critical' => false, 'surge' => 2, 'category' => 'Heavy'],
        'welder'    => ['name' => 'Welder / workshop tool','icon' => '🛠️', 'watts' => 3000, 'hours' => 2,   'critical' => false, 'surge' => 3, 'category' => 'Heavy'],
    ];

    /** All appliances as a list (each with its key), for the picker UI. */
    public static function all(): array
    {
        $out = [];
        foreach (self::APPLIANCES as $key => $a) {
            $out[] = ['key' => $key] + $a;
        }
        return $out;
    }

    /**
     * Map a customer selection to engine loads.
     *
     * @param array $selection each: ['key' => string, 'qty' => int]
     * @return array loads for SolarSizingService (name, watts, qty, hours_per_day, critical, surge_factor)
     */
    public static function toLoads(array $selection): array
    {
        $loads = [];
        foreach ($selection as $sel) {
            $key = is_array($sel) ? (string) ($sel['key'] ?? '') : (string) $sel;
            $qty = is_array($sel) ? (int) ($sel['qty'] ?? 0) : 1;
            $a = self::APPLIANCES[$key] ?? null;
            if (! $a || $qty <= 0) {
                continue;
            }
            $loads[] = [
                'name'          => $a['name'],
                'watts'         => $a['watts'],
                'qty'           => $qty,
                'hours_per_day' => $a['hours'],
                'critical'      => $a['critical'],
                'surge_factor'  => $a['surge'],
            ];
        }
        return $loads;
    }
}
