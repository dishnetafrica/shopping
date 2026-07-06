<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Decision\Policies;

use CloudBSS\Kernel\Decision\{Decision, DecisionPolicy};
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** Picks lowest LANDED cost; escalates when the best candidate is un-vetted. */
final class SupplierSelectionPolicy implements DecisionPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function name(): string { return 'SupplierSelection'; }
    public function version(): int { return 2; }   // v2: added vetting escalation

    public function evaluate(array $context): Decision
    {
        $cands = $context['candidates'] ?? [];
        if (!$cands) throw new \InvalidArgumentException('No candidates to select from');
        usort($cands, fn($a, $b) => $a['landed']->compareTo($b['landed']));
        $best = $cands[0]; $second = $cands[1] ?? null;

        $confidence = $second
            ? max(60, min(99, 60 + intdiv($second['landed']->minus($best['landed'])->minor * 40, max(1, $best['landed']->minor))))
            : 85;
        $requiresHuman = !($best['vetted'] ?? true);

        return new Decision(
            $context['tenantId'] ?? 't_default', $context['entityId'] ?? 'e_default',
            'SupplierSelection', 'policy', $this->name(), $this->name(), $this->version(),
            ['rule' => 'lowest_landed_cost', 'requiresVetting' => true],
            array_map(fn($c) => ['name' => $c['name'], 'landedMinor' => $c['landed']->minor, 'currency' => $c['landed']->currency->code, 'vetted' => $c['vetted'] ?? true], $cands),
            $context['evidenceRefs'] ?? [],
            $best['name'],
            "Lowest landed cost {$best['landed']->format()}" . ($second ? " vs {$second['landed']->format()}" : '') . ($requiresHuman ? ' — supplier not vetted, human sign-off required' : ''),
            $confidence, $requiresHuman, $context['linkedRef'] ?? null, $this->clock, $this->ids
        );
    }
}
