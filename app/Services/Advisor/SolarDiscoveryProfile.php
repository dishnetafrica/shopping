<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Project Advisor — discovery questionnaire + answer→brief mapping.
 *
 * Declares the questions a salesperson asks (customer type, objective, location, existing power,
 * consumption, appliances/loads, backup hours, budget, expansion) and converts the answers into the
 * neutral brief the {@see SolarSizingService} consumes. Pure: no DB, deterministic, unit-testable.
 */
class SolarDiscoveryProfile implements DiscoveryProfile
{
    /** Proposal segments — also selects the proposal template later. */
    public const CUSTOMER_TYPES = [
        'Residential', 'Commercial', 'Industrial', 'Government', 'NGO',
        'Hotel', 'School', 'Farm', 'Water Pump', 'Street Lighting',
    ];

    public const OBJECTIVES = [
        'Backup during outages', 'Full off-grid', 'Reduce bill (hybrid)', 'Water pumping', 'Lighting',
    ];

    public const EXISTING_POWER = ['Grid', 'Generator', 'Grid + generator', 'None / off-grid'];

    /** Region → average productive sun hours/day. Drives PV sizing. Editable per deployment. */
    public const PSH_BY_REGION = [
        'Central (Kampala)' => 5.0,
        'Eastern'           => 5.2,
        'Northern'          => 5.6,
        'Western'           => 5.1,
        'South West'        => 5.0,
        'Other'             => 5.0,
    ];

    /** Future-expansion answer → allowance fraction applied to PV and battery. */
    public const EXPANSION_MAP = [
        'None'              => 0.0,
        'Some (20%)'        => 0.20,
        'Significant (40%)' => 0.40,
    ];

    public function key(): string
    {
        return 'solar';
    }

    public function schema(): array
    {
        return [
            'profile' => 'solar',
            'title'   => 'Solar project discovery',
            'sections' => [
                [
                    'key' => 'customer', 'title' => 'Customer & objective',
                    'fields' => [
                        ['key' => 'customer_type', 'label' => 'Customer type', 'type' => 'enum', 'required' => true, 'options' => self::CUSTOMER_TYPES],
                        ['key' => 'project_objective', 'label' => 'Main objective', 'type' => 'enum', 'options' => self::OBJECTIVES],
                        ['key' => 'location', 'label' => 'Location / region', 'type' => 'enum', 'options' => array_keys(self::PSH_BY_REGION), 'help' => 'Sets the local sun hours used for panel sizing.'],
                    ],
                ],
                [
                    'key' => 'power', 'title' => 'Existing power & consumption',
                    'fields' => [
                        ['key' => 'existing_power_source', 'label' => 'Existing power source', 'type' => 'enum', 'options' => self::EXISTING_POWER],
                        ['key' => 'monthly_kwh', 'label' => 'Monthly consumption', 'type' => 'number', 'unit' => 'kWh', 'help' => 'Optional — used as a cross-check.'],
                        ['key' => 'monthly_bill', 'label' => 'Monthly electricity bill', 'type' => 'number', 'unit' => 'UGX', 'help' => 'Optional.'],
                    ],
                ],
                [
                    'key' => 'loads', 'title' => 'Appliances & loads',
                    'fields' => [
                        [
                            'key' => 'loads', 'label' => 'Appliances', 'type' => 'loads',
                            'help' => 'List each appliance with its power, quantity and daily run-time. Tick the ones that must keep running during a power cut.',
                            'item_fields' => [
                                ['key' => 'name', 'label' => 'Appliance', 'type' => 'text'],
                                ['key' => 'watts', 'label' => 'Watts (each)', 'type' => 'number', 'unit' => 'W'],
                                ['key' => 'qty', 'label' => 'Quantity', 'type' => 'number', 'default' => 1],
                                ['key' => 'hours_per_day', 'label' => 'Hours/day', 'type' => 'number', 'unit' => 'h'],
                                ['key' => 'critical', 'label' => 'Needs backup?', 'type' => 'boolean', 'default' => false],
                                ['key' => 'surge_factor', 'label' => 'Start-up surge ×', 'type' => 'number', 'default' => 1, 'help' => 'Motors/pumps ≈ 3; electronics ≈ 1.'],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => 'sizing', 'title' => 'Backup & expansion',
                    'fields' => [
                        ['key' => 'backup_hours', 'label' => 'Required backup hours', 'type' => 'number', 'unit' => 'h', 'required' => true, 'help' => 'How long the critical loads must run with no sun or grid.'],
                        ['key' => 'future_expansion', 'label' => 'Future expansion plans', 'type' => 'enum', 'options' => array_keys(self::EXPANSION_MAP), 'default' => 'Some (20%)'],
                    ],
                ],
                [
                    'key' => 'budget', 'title' => 'Budget',
                    'fields' => [
                        ['key' => 'budget', 'label' => 'Budget', 'type' => 'number', 'unit' => 'UGX', 'help' => 'Optional — helps tailor the proposal tier.'],
                    ],
                ],
            ],
        ];
    }

    public function toBrief(array $answers): array
    {
        $loads = [];
        foreach ((array) ($answers['loads'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name  = trim((string) ($row['name'] ?? ''));
            $watts = self::num($row['watts'] ?? 0);
            if ($watts <= 0) {
                continue;   // an appliance with no power can't be sized
            }
            $loads[] = [
                'name'          => $name !== '' ? $name : 'Load',
                'watts'         => $watts,
                'qty'           => max(1, (int) self::num($row['qty'] ?? 1)),
                'hours_per_day' => self::num($row['hours_per_day'] ?? 0),
                'critical'      => self::truthy($row['critical'] ?? false),
                'surge_factor'  => max(1.0, self::num($row['surge_factor'] ?? 1)),
            ];
        }

        $region = (string) ($answers['location'] ?? '');
        $psh = $answers['peak_sun_hours'] ?? (self::PSH_BY_REGION[$region] ?? self::PSH_BY_REGION['Other']);

        $exp = self::EXPANSION_MAP[(string) ($answers['future_expansion'] ?? '')] ?? self::EXPANSION_MAP['Some (20%)'];

        return [
            'loads'          => $loads,
            'backup_hours'   => self::num($answers['backup_hours'] ?? 0),
            'peak_sun_hours' => (float) $psh,
            'expansion_pct'  => (float) $exp,
        ];
    }

    /** Pull a number out of mixed input ("1,200", "300 W", 300) → float. */
    private static function num(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $clean = str_replace(',', '', (string) $v);
        return preg_match('/-?\d+(\.\d+)?/', $clean, $m) ? (float) $m[0] : 0.0;
    }

    /** Coerce a checkbox-style answer to bool. */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on', 'y'], true);
    }
}
