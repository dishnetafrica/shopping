<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Project Advisor — proposal templates per customer segment.
 *
 * Supplies the *boilerplate* content of a proposal (the parts that don't come from the calculation
 * engine): the executive-summary lead line, standard assumptions, exclusions, terms & conditions, and a
 * warranty note. A base template covers everything; segments override only what differs. Data-only and
 * generic — a new industry ships its own template set and reuses the ProposalBuilder unchanged.
 */
class ProposalTemplate
{
    /** Base template — used for any segment without an override, and merged under every override. */
    public const BASE = [
        'exec_lead' => 'This proposal presents a solar power solution tailored to your requirements.',
        'assumptions' => [
            'Sizing is based on the loads and usage hours provided during discovery.',
            'Average productive sun hours are assumed for the stated location.',
            'Prices are valid for 30 days from the date of this proposal.',
            'Final quantities are confirmed after a site survey.',
        ],
        'exclusions' => [
            'Civil works, trenching and non-standard mounting structures.',
            'Grid-tie approvals or utility paperwork, unless stated.',
            'Major rewiring of the existing installation.',
            'Any items not explicitly listed in the equipment list.',
        ],
        'terms' => [
            'Payment: 70% deposit to confirm the order, 30% on commissioning, unless agreed otherwise.',
            'Lead time is confirmed on order and subject to stock availability.',
            'Installation is carried out by our certified technicians.',
            'Warranty is as stated per component; workmanship warranty applies to the installation.',
        ],
        'warranty_note' => 'Each component carries its manufacturer and supplier warranty as listed. Installation workmanship is warranted for 12 months.',
    ];

    /** Segment-specific overrides (merged over BASE). Only list what differs. */
    public const OVERRIDES = [
        'Government' => [
            'exec_lead' => 'This proposal presents a solar power solution structured to meet public-sector procurement and reliability requirements.',
            'terms' => [
                'Pricing and payment terms comply with the applicable procurement framework.',
                'Delivery, installation and commissioning against agreed milestones.',
                'Documentation, testing certificates and as-built drawings provided on handover.',
                'Warranty is as stated per component; workmanship warranty applies to the installation.',
            ],
        ],
        'NGO' => [
            'exec_lead' => 'This proposal presents a reliable, low-maintenance solar solution suited to programme and field operations.',
        ],
        'School' => [
            'exec_lead' => 'This proposal presents a solar solution to keep classrooms, offices and boarding facilities powered reliably and affordably.',
            'assumptions' => [
                'Usage reflects term-time operating hours; holiday periods reduce demand.',
                'Sizing is based on the loads and usage hours provided during discovery.',
                'Average productive sun hours are assumed for the stated location.',
                'Prices are valid for 30 days; final quantities confirmed after a site survey.',
            ],
        ],
        'Hotel' => [
            'exec_lead' => 'This proposal presents a solar-plus-backup solution to protect guest experience against outages while reducing running costs.',
        ],
        'Industrial' => [
            'exec_lead' => 'This proposal presents a solar solution sized for continuous operational loads and demand-charge reduction.',
            'exclusions' => [
                'Medium-voltage works and transformer changes.',
                'Civil works, trenching and non-standard mounting structures.',
                'Production downtime scheduling and shutdown coordination.',
                'Any items not explicitly listed in the equipment list.',
            ],
        ],
        'Farm' => [
            'exec_lead' => 'This proposal presents a solar solution for farm operations — pumping, cold storage, lighting and processing.',
        ],
        'Water Pump' => [
            'exec_lead' => 'This proposal presents a solar water-pumping solution sized for your daily water demand and head.',
            'assumptions' => [
                'Pump sizing is based on the daily water volume and head provided.',
                'Average productive sun hours are assumed for the stated location.',
                'Borehole yield is sufficient for the specified extraction rate.',
                'Prices are valid for 30 days; final quantities confirmed after a site survey.',
            ],
        ],
        'Street Lighting' => [
            'exec_lead' => 'This proposal presents a solar street-lighting solution with autonomous dusk-to-dawn operation.',
        ],
    ];

    /** Merged template for a segment: BASE with any segment override applied. */
    public static function for(string $segment): array
    {
        $override = self::OVERRIDES[$segment] ?? [];
        return array_merge(self::BASE, $override);
    }
}
