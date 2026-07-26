<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Project Advisor — predefined solution packages.
 *
 * Each package bundles what a salesperson needs to move fast on a common job: typical discovery answers
 * (so the deterministic engine can size it), a recommended equipment list, and marketing/sales copy.
 * Starting from a package pre-fills a project and produces a full proposal in one step, which the
 * salesperson then tailors. Data-only and generic — other industries ship their own package set.
 *
 * Equipment `sku` links to the catalogue: if a product with that SKU exists, the proposal uses its real
 * price and Product Specifications; otherwise the indicative `unit_price` here is used.
 */
class SolarPackages
{
    public const PACKAGES = [
        [
            'key' => 'home_starter', 'name' => 'Home Starter', 'segment' => 'Residential',
            'summary' => 'Keep lights, TV and phones running through blackouts for a typical home.',
            'marketing' => [
                'tagline' => 'Never sit in the dark again.',
                'points' => ['Runs lights, TV and phone charging during outages', 'Simple plug-and-play install', 'Expandable as your needs grow'],
                'whatsapp' => 'Hi! Our Home Starter solar kit keeps your lights, TV and phones on during blackouts. Would you like a quick quote?',
            ],
            'answers' => [
                'customer_type' => 'Residential', 'project_objective' => 'Backup during outages',
                'backup_hours' => 6, 'future_expansion' => 'Some (20%)',
                'loads' => [
                    ['name' => 'LED lights', 'watts' => 10, 'qty' => 5, 'hours_per_day' => 5, 'critical' => 1],
                    ['name' => 'TV', 'watts' => 80, 'qty' => 1, 'hours_per_day' => 4, 'critical' => 1],
                    ['name' => 'Phone charging', 'watts' => 10, 'qty' => 2, 'hours_per_day' => 3, 'critical' => 1],
                    ['name' => 'Fan', 'watts' => 60, 'qty' => 1, 'hours_per_day' => 6, 'critical' => 0],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 200W', 'sku' => 'SP-200', 'qty' => 2, 'unit_price' => 350000],
                ['name' => '1kVA inverter', 'sku' => 'INV-1K', 'qty' => 1, 'unit_price' => 600000],
                ['name' => 'Battery 12V 100Ah', 'sku' => 'BAT-100', 'qty' => 1, 'unit_price' => 900000],
                ['name' => 'Charge controller 30A', 'sku' => 'CC-30', 'qty' => 1, 'unit_price' => 180000],
                ['name' => 'Cables & mounting kit', 'sku' => 'INSTALL-KIT', 'qty' => 1, 'unit_price' => 200000],
            ],
        ],
        [
            'key' => 'small_business', 'name' => 'Small Business', 'segment' => 'Commercial',
            'summary' => 'Keep an office or shop running — computers, lights and a fridge — and cut the bill.',
            'marketing' => [
                'tagline' => 'Stay open, cut your power bill.',
                'points' => ['Powers computers, lighting and refrigeration', 'Reduces monthly electricity and generator costs', 'Hybrid: solar by day, backup at night'],
                'whatsapp' => 'Hi! Our Small Business solar package keeps your shop or office running and lowers your bill. Shall I prepare a proposal?',
            ],
            'answers' => [
                'customer_type' => 'Commercial', 'project_objective' => 'Reduce bill (hybrid)',
                'backup_hours' => 5, 'future_expansion' => 'Some (20%)',
                'loads' => [
                    ['name' => 'Computers', 'watts' => 150, 'qty' => 3, 'hours_per_day' => 8, 'critical' => 1],
                    ['name' => 'LED lighting', 'watts' => 15, 'qty' => 10, 'hours_per_day' => 8, 'critical' => 1],
                    ['name' => 'Refrigerator', 'watts' => 200, 'qty' => 1, 'hours_per_day' => 10, 'critical' => 1],
                    ['name' => 'Printer', 'watts' => 300, 'qty' => 1, 'hours_per_day' => 2, 'critical' => 0],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 6, 'unit_price' => 700000],
                ['name' => '5kVA hybrid inverter', 'sku' => 'INV-5K', 'qty' => 1, 'unit_price' => 3500000],
                ['name' => 'Battery 48V 200Ah (LiFePO4)', 'sku' => 'BAT-48-200', 'qty' => 1, 'unit_price' => 6500000],
                ['name' => 'Mounting & cabling', 'sku' => 'INSTALL-KIT-C', 'qty' => 1, 'unit_price' => 700000],
            ],
        ],
        [
            'key' => 'borehole', 'name' => 'Solar Borehole Pump', 'segment' => 'Water Pump',
            'summary' => 'Direct solar water pumping for a borehole — no fuel, no battery, pumps by day.',
            'marketing' => [
                'tagline' => 'Free water from the sun.',
                'points' => ['Pumps water straight from sunlight — no fuel', 'No batteries to replace', 'Fills a tank during the day for round-the-clock supply'],
                'whatsapp' => 'Hi! Our solar borehole pump gives you free daytime water with no fuel and no batteries. Want the details?',
            ],
            'answers' => [
                'customer_type' => 'Water Pump', 'project_objective' => 'Water pumping',
                'backup_hours' => 0, 'future_expansion' => 'None',
                'loads' => [
                    ['name' => 'Submersible pump', 'watts' => 750, 'qty' => 1, 'hours_per_day' => 6, 'critical' => 1, 'surge_factor' => 3],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 4, 'unit_price' => 700000],
                ['name' => 'Solar pump controller 1.5kW', 'sku' => 'PUMP-CTRL-15', 'qty' => 1, 'unit_price' => 1800000],
                ['name' => 'Submersible pump 0.75kW', 'sku' => 'PUMP-075', 'qty' => 1, 'unit_price' => 2600000],
                ['name' => 'Mounting & piping kit', 'sku' => 'PUMP-KIT', 'qty' => 1, 'unit_price' => 900000],
            ],
        ],
        [
            'key' => 'school', 'name' => 'School Power', 'segment' => 'School',
            'summary' => 'Reliable power for classrooms, offices and a computer lab — day and evening study.',
            'marketing' => [
                'tagline' => 'Power for better learning.',
                'points' => ['Lights classrooms and offices reliably', 'Runs a computer lab and projectors', 'Supports evening prep and boarding facilities'],
                'whatsapp' => 'Hi! Our School Power solar package keeps classrooms, offices and the computer lab running. Shall I send a proposal?',
            ],
            'answers' => [
                'customer_type' => 'School', 'project_objective' => 'Reduce bill (hybrid)',
                'backup_hours' => 6, 'future_expansion' => 'Significant (40%)',
                'loads' => [
                    ['name' => 'Classroom lighting', 'watts' => 15, 'qty' => 30, 'hours_per_day' => 6, 'critical' => 1],
                    ['name' => 'Computers (lab)', 'watts' => 150, 'qty' => 15, 'hours_per_day' => 5, 'critical' => 1],
                    ['name' => 'Projector', 'watts' => 300, 'qty' => 2, 'hours_per_day' => 4, 'critical' => 0],
                    ['name' => 'Office equipment', 'watts' => 500, 'qty' => 1, 'hours_per_day' => 6, 'critical' => 1],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 16, 'unit_price' => 700000],
                ['name' => '10kVA hybrid inverter', 'sku' => 'INV-10K', 'qty' => 1, 'unit_price' => 7000000],
                ['name' => 'Battery 48V 300Ah (LiFePO4)', 'sku' => 'BAT-48-300', 'qty' => 2, 'unit_price' => 9000000],
                ['name' => 'Mounting, combiner & cabling', 'sku' => 'INSTALL-KIT-L', 'qty' => 1, 'unit_price' => 2500000],
            ],
        ],
        [
            'key' => 'health_centre', 'name' => 'Health Centre', 'segment' => 'Government',
            'summary' => 'Uninterrupted power for a clinic — lights, refrigeration for vaccines, and equipment.',
            'marketing' => [
                'tagline' => 'Keep care running, day and night.',
                'points' => ['Protects the vaccine cold chain', 'Powers lighting, theatre and diagnostic equipment', '24-hour autonomy for critical wards'],
                'whatsapp' => 'Hi! Our Health Centre solar system keeps vaccines cold and critical equipment powered around the clock. May I prepare a proposal?',
            ],
            'answers' => [
                'customer_type' => 'Government', 'project_objective' => 'Full off-grid',
                'backup_hours' => 12, 'future_expansion' => 'Significant (40%)',
                'loads' => [
                    ['name' => 'Vaccine fridge', 'watts' => 200, 'qty' => 2, 'hours_per_day' => 24, 'critical' => 1],
                    ['name' => 'Ward & theatre lighting', 'watts' => 15, 'qty' => 40, 'hours_per_day' => 12, 'critical' => 1],
                    ['name' => 'Diagnostic equipment', 'watts' => 800, 'qty' => 1, 'hours_per_day' => 6, 'critical' => 1],
                    ['name' => 'Water pump', 'watts' => 750, 'qty' => 1, 'hours_per_day' => 3, 'critical' => 0, 'surge_factor' => 3],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 24, 'unit_price' => 700000],
                ['name' => '15kVA hybrid inverter', 'sku' => 'INV-15K', 'qty' => 1, 'unit_price' => 12000000],
                ['name' => 'Battery 48V 300Ah (LiFePO4)', 'sku' => 'BAT-48-300', 'qty' => 4, 'unit_price' => 9000000],
                ['name' => 'Mounting, combiner & cabling', 'sku' => 'INSTALL-KIT-XL', 'qty' => 1, 'unit_price' => 4000000],
            ],
        ],
        [
            'key' => 'hotel', 'name' => 'Hotel Backup', 'segment' => 'Hotel',
            'summary' => 'Protect the guest experience — rooms, kitchen and reception stay powered in outages.',
            'marketing' => [
                'tagline' => 'No blackout your guests will notice.',
                'points' => ['Seamless backup for rooms, kitchen and reception', 'Cuts diesel-generator running costs', 'Quiet, clean power that guests appreciate'],
                'whatsapp' => 'Hi! Our Hotel Backup solar system keeps rooms and reception running through outages and cuts generator costs. Shall I prepare a proposal?',
            ],
            'answers' => [
                'customer_type' => 'Hotel', 'project_objective' => 'Reduce bill (hybrid)',
                'backup_hours' => 8, 'future_expansion' => 'Some (20%)',
                'loads' => [
                    ['name' => 'Guest room sockets & lights', 'watts' => 200, 'qty' => 20, 'hours_per_day' => 6, 'critical' => 1],
                    ['name' => 'Kitchen refrigeration', 'watts' => 400, 'qty' => 3, 'hours_per_day' => 12, 'critical' => 1],
                    ['name' => 'Reception & common areas', 'watts' => 1000, 'qty' => 1, 'hours_per_day' => 10, 'critical' => 1],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 20, 'unit_price' => 700000],
                ['name' => '15kVA hybrid inverter', 'sku' => 'INV-15K', 'qty' => 1, 'unit_price' => 12000000],
                ['name' => 'Battery 48V 300Ah (LiFePO4)', 'sku' => 'BAT-48-300', 'qty' => 3, 'unit_price' => 9000000],
                ['name' => 'Mounting, combiner & cabling', 'sku' => 'INSTALL-KIT-L', 'qty' => 1, 'unit_price' => 2500000],
            ],
        ],
        [
            'key' => 'farm', 'name' => 'Farm Power', 'segment' => 'Farm',
            'summary' => 'Power farm operations — pumping, cold storage and lighting — off the grid.',
            'marketing' => [
                'tagline' => 'Grow more with free daytime power.',
                'points' => ['Runs irrigation pumps and cold storage', 'Lights sheds and processing areas', 'Cuts fuel costs across the season'],
                'whatsapp' => 'Hi! Our Farm Power solar package runs your pumps, cold storage and lighting without fuel. Want a proposal?',
            ],
            'answers' => [
                'customer_type' => 'Farm', 'project_objective' => 'Full off-grid',
                'backup_hours' => 6, 'future_expansion' => 'Significant (40%)',
                'loads' => [
                    ['name' => 'Irrigation pump', 'watts' => 1500, 'qty' => 1, 'hours_per_day' => 5, 'critical' => 1, 'surge_factor' => 3],
                    ['name' => 'Cold storage', 'watts' => 600, 'qty' => 1, 'hours_per_day' => 12, 'critical' => 1],
                    ['name' => 'Shed lighting', 'watts' => 15, 'qty' => 12, 'hours_per_day' => 5, 'critical' => 0],
                ],
            ],
            'equipment' => [
                ['name' => 'Solar panel 550W', 'sku' => 'SP-550', 'qty' => 12, 'unit_price' => 700000],
                ['name' => '10kVA hybrid inverter', 'sku' => 'INV-10K', 'qty' => 1, 'unit_price' => 7000000],
                ['name' => 'Battery 48V 200Ah (LiFePO4)', 'sku' => 'BAT-48-200', 'qty' => 2, 'unit_price' => 6500000],
                ['name' => 'Mounting & cabling', 'sku' => 'INSTALL-KIT-L', 'qty' => 1, 'unit_price' => 2000000],
            ],
        ],
        [
            'key' => 'street_light', 'name' => 'Solar Street Lighting', 'segment' => 'Street Lighting',
            'summary' => 'Standalone all-in-one solar street lights — automatic dusk-to-dawn, no grid, no wiring.',
            'marketing' => [
                'tagline' => 'Safer streets, zero running cost.',
                'points' => ['Automatic dusk-to-dawn lighting', 'No trenching, wiring or electricity bill', 'Each pole is fully independent'],
                'whatsapp' => 'Hi! Our solar street lights switch on automatically every night with no wiring and no bill. Shall I quote per pole?',
            ],
            'answers' => [
                'customer_type' => 'Street Lighting', 'project_objective' => 'Lighting',
                'backup_hours' => 12, 'future_expansion' => 'None',
                'loads' => [
                    ['name' => 'LED street light', 'watts' => 60, 'qty' => 1, 'hours_per_day' => 12, 'critical' => 1],
                ],
            ],
            'equipment' => [
                ['name' => 'All-in-one solar street light 60W', 'sku' => 'SSL-60', 'qty' => 1, 'unit_price' => 1200000],
                ['name' => 'Galvanised pole 6m', 'sku' => 'POLE-6', 'qty' => 1, 'unit_price' => 450000],
                ['name' => 'Installation (per pole)', 'sku' => 'SSL-INSTALL', 'qty' => 1, 'unit_price' => 250000],
            ],
        ],
    ];

    /** All packages, keyed by their `key`. */
    public static function all(): array
    {
        $out = [];
        foreach (self::PACKAGES as $p) {
            $out[$p['key']] = $p;
        }
        return $out;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
