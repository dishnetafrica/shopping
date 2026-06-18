<?php
namespace App\Services\Winworld;

/**
 * Loss-prevention alert rules. Pure logic: given a production context it
 * returns the alerts that should fire, each tagged with the responsible role,
 * the loss it prevents, and a ready-to-send WhatsApp message. The Notifier
 * decides delivery; this decides what and to whom.
 */
final class Alerts
{
    public const SLOW_DEFAULT = 70.0; // % of target below which a run is "slow"

    private static function mk(string $type, string $severity, string $role, string $prevents, string $text): array
    {
        return compact('type','severity','role','prevents','text');
    }

    /**
     * Event-driven alerts from a saved production entry.
     * @param array $c keys: indent_no, product, machine, process, stop_reason, qc_result, efficiency_pct
     * @return array<int,array>
     */
    public static function fromEntry(array $c, float $slowPct = self::SLOW_DEFAULT): array
    {
        $out  = [];
        $mach = $c['machine'] ?? '?';
        $proc = $c['process'] ?? '';
        $ind  = $c['indent_no'] ?? '';
        $prod = $c['product'] ?? '';
        $stop = trim((string) ($c['stop_reason'] ?? ''));

        if ($stop !== '') {
            $role = match ($stop) {
                'Material Shortage' => 'stores',
                'Machine Breakdown' => 'maintenance',
                default             => 'production',
            };
            $out[] = self::mk('stop', 'high', $role, 'machine downtime',
                "⛔ STOP — {$mach} ({$proc})\nReason: {$stop}\nIndent {$ind} · {$prod}");
        }

        if (strtolower((string) ($c['qc_result'] ?? '')) === 'reject') {
            $out[] = self::mk('qc_reject', 'high', 'production', 'scrap / rework',
                "⚠ QC REJECT — {$proc} on {$mach}\nIndent {$ind} · {$prod}");
        }

        $eff = $c['efficiency_pct'] ?? null;
        if ($stop === '' && $eff !== null && $eff !== '' && (float) $eff > 0 && (float) $eff < $slowPct) {
            $out[] = self::mk('slow', 'medium', 'production', 'speed loss',
                "🐢 SLOW RUN — {$mach} ({$proc}) at " . round((float) $eff) . "% of target\nIndent {$ind} · {$prod}");
        }

        return $out;
    }

    /**
     * Delivery delay risk: planned finish date is after the required date.
     * @param array $c keys: indent_no, product, planned_end (Y-m-d...), required_date (Y-m-d)
     */
    public static function delayRisk(array $c): ?array
    {
        $pe = $c['planned_end'] ?? null;
        $rd = $c['required_date'] ?? null;
        if (! $pe || ! $rd) return null;
        $peDate = substr((string) $pe, 0, 10);
        $rdDate = substr((string) $rd, 0, 10);
        if ($peDate > $rdDate) {
            return self::mk('delay_risk', 'high', 'sales', 'late delivery',
                "📦 DELAY RISK — Indent {$c['indent_no']} ({$c['product']})\nPlanned finish {$peDate}, required {$rdDate}");
        }
        return null;
    }
}
