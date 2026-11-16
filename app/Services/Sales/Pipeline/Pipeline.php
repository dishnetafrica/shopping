<?php

namespace App\Services\Sales\Pipeline;

/**
 * PURE rules of the standard sales pipeline for an Internet provider (DishNet South Sudan plan,
 * Phase 6). The unit is the LEAD (one sales enquiry, owned by one salesperson — Phase 1).
 *
 *   new → contacted → qualified → site check → quoted → negotiating → won → installation → active
 *   …and lost from any open stage (reason required).
 *
 * Gates: a stage can only be reached when what it needs is true (see requirements()). A move may skip
 * stages forward only if every gate on the way is met. Moving back needs a reason. Lost and active are
 * final; only an owner or manager reopens a lost lead. Billing and activation stay in UISP: "active"
 * means DishNet's installation job is completed and the customer is handed to UISP.
 *
 * The lead's existing `status` (new/assigned/contacted/qualified/won/lost — used by the Leads screen)
 * follows the stage, so older screens keep working; a lead with no stage yet takes it from its status.
 */
final class Pipeline
{
    public const STAGES = [
        'new'          => 'New enquiry',
        'contacted'    => 'Contacted',
        'qualified'    => 'Qualified',
        'site_check'   => 'Coverage / site check',
        'quoted'       => 'Quoted',
        'negotiating'  => 'Negotiating',
        'won'          => 'Won — customer accepted',
        'installation' => 'Installation booked',
        'active'       => 'Installed — handed to UISP',
        'lost'         => 'Lost',
    ];

    public const OPEN = ['new', 'contacted', 'qualified', 'site_check', 'quoted', 'negotiating'];

    public const SERVICE_LINES = ['starlink', 'fiber', 'both', 'unsure'];

    public const CUSTOMER_TYPES = ['home', 'business', 'organisation'];

    /** coverage_status values: from the published coverage, or a site survey's outcome. */
    public const COVERAGE = ['covered', 'survey', 'none', 'survey_ok', 'survey_failed'];

    /** Days until the follow-up, and what the salesperson should do next, after reaching each stage. */
    private const FOLLOW_UP = [
        'new'          => [0, 'Reply to the customer'],
        'contacted'    => [1, 'Qualify: service, exact area, home or business, number of users'],
        'qualified'    => [1, 'Check coverage or book a site survey'],
        'site_check'   => [2, 'Send the quotation'],
        'quoted'       => [2, 'Follow up the quotation'],
        'negotiating'  => [2, 'Follow up: answer the objection and ask for the decision'],
        'won'          => [1, 'Book the installation'],
        'installation' => [3, 'Check the installation is done'],
    ];

    public static function label(string $stage): string
    {
        return self::STAGES[$stage] ?? ucfirst($stage);
    }

    public static function tone(string $stage): string
    {
        return match ($stage) {
            'won', 'installation', 'active' => 'ok',
            'lost' => 'danger',
            'quoted', 'negotiating' => 'warn',
            default => 'default',
        };
    }

    /** The stage of a lead: its own stage, or — for older leads / legacy screens — from its status. */
    public static function stageOf(?string $stage, ?string $status): string
    {
        if ($status === 'lost') {
            return 'lost';
        }
        if ($status === 'won' && ($stage === null || in_array($stage, self::OPEN, true))) {
            return 'won';
        }
        if ($stage !== null && isset(self::STAGES[$stage])) {
            return $stage;
        }

        return match ((string) $status) {
            'contacted' => 'contacted',
            'qualified' => 'qualified',
            default => 'new',
        };
    }

    /** The lead status the Leads screen uses, for a stage. */
    public static function statusFor(string $stage, string $current = 'new'): string
    {
        return match ($stage) {
            'new' => in_array($current, ['new', 'assigned'], true) ? $current : 'assigned',
            'contacted' => 'contacted',
            'qualified', 'site_check', 'quoted', 'negotiating' => 'qualified',
            'won', 'installation', 'active' => 'won',
            'lost' => 'lost',
        };
    }

    /** What has to be true to stand at $stage (one gate, not cumulative). @return string[] unmet */
    public static function requirements(string $stage, array $f): array
    {
        $q = (array) ($f['qualification'] ?? []);
        $miss = [];
        switch ($stage) {
            case 'qualified':
                if (! in_array($q['service_line'] ?? '', self::SERVICE_LINES, true)) { $miss[] = 'which service (Starlink, Fiber, both or unsure)'; }
                if (trim((string) ($q['area'] ?? '')) === '') { $miss[] = 'the exact area or landmark'; }
                if (! in_array($q['customer_type'] ?? '', self::CUSTOMER_TYPES, true)) { $miss[] = 'home, business or organisation'; }
                if ((int) ($q['users'] ?? 0) < 1) { $miss[] = 'how many people or devices will use it'; }
                break;
            case 'site_check':
                if (! in_array($f['coverage_status'] ?? '', self::COVERAGE, true)) { $miss[] = 'a coverage check (or a site-survey result)'; }
                break;
            case 'quoted':
                if (! in_array($f['coverage_status'] ?? '', ['covered', 'survey_ok'], true)) { $miss[] = 'coverage confirmed (published as covered, or a passed site survey)'; }
                if (empty($f['open_quote'])) { $miss[] = 'a quotation sent to this customer'; }
                break;
            case 'won':
                if (empty($f['accepted_quote'])) { $miss[] = 'the customer\'s quotation marked accepted'; }
                break;
            case 'installation':
                if (empty($f['accepted_quote'])) { $miss[] = 'the customer\'s quotation marked accepted'; }
                break;
            case 'active':
                if (($f['work_order_status'] ?? '') !== 'completed') { $miss[] = 'the installation job completed'; }
                break;
        }

        return $miss;
    }

    /**
     * Can the lead move from $from to $to? Returns null if yes, else ['error'=>..,'missing'=>[..]].
     * Forward: every gate between them must be met. Back: a reason. Out of lost: owner/manager only.
     */
    public static function check(string $from, string $to, array $facts, string $reason, bool $manager): ?array
    {
        $order = array_keys(self::STAGES);
        if (! isset(self::STAGES[$to]) || $from === $to) {
            return ['error' => 'bad_stage'];
        }
        if ($from === 'active') {
            return ['error' => 'final'];
        }
        if ($to === 'lost') {
            return trim($reason) === '' ? ['error' => 'reason_required'] : null;
        }
        if ($from === 'lost') {
            if (! $manager) {
                return ['error' => 'manager_only'];
            }
            if (trim($reason) === '') {
                return ['error' => 'reason_required'];
            }
            $from = 'new';                                  // a reopened lead walks the gates again from the start
            if ($to === 'new') {
                return null;
            }
        }
        $i = array_search($from, $order, true);
        $j = array_search($to, $order, true);
        if ($j < $i) {
            return trim($reason) === '' ? ['error' => 'reason_required'] : null;     // going back
        }
        $missing = [];
        for ($k = $i + 1; $k <= $j; $k++) {
            foreach (self::requirements($order[$k], $facts) as $m) {
                $missing[] = $m;
            }
        }

        return $missing ? ['error' => 'gate', 'missing' => array_values(array_unique($missing))] : null;
    }

    /** After reaching $stage: [days until follow-up, next action], or null for final stages. */
    public static function followUp(string $stage): ?array
    {
        return self::FOLLOW_UP[$stage] ?? null;
    }

    /** Stages a person may pick from $from (for the screen): forward ones, back ones, lost. */
    public static function choices(string $from, bool $manager): array
    {
        if ($from === 'active') {
            return [];
        }
        if ($from === 'lost') {
            return $manager ? array_values(array_diff(array_keys(self::STAGES), ['lost', 'active'])) : [];
        }

        return array_values(array_diff(array_keys(self::STAGES), [$from]));
    }
}
