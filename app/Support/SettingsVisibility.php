<?php

namespace App\Support;

/**
 * Which settings groups this shop should actually see.
 *
 * A grocery shop had no business being asked which Bajaj motorbike models to show on
 * its website. Groups may now declare `'only' => ['auto_parts']`; a group with no
 * `only` key is universal and always shows.
 *
 * THE RULE THAT MAKES THIS SAFE:
 *
 *   A group is only hidden when it does not match the shop's type AND the shop has
 *   never configured anything in it.
 *
 * "Configured" means a stored value that DIFFERS FROM THE DECLARED DEFAULT — not
 * merely a key that exists. That distinction matters here for a specific reason: the
 * mobile settings screen renders every section at once and posts all of them, so a
 * grocery shop that ever saved settings on a phone already has `show_model_boxer100`
 * and friends sitting in its settings JSON at their default values. Testing for
 * existence would treat that noise as intent and hide nothing.
 *
 * The practical payoff: Family Shoppers is a grocery that also bids for tenders, so
 * `tender_enabled` is ON — differs from its default — and the Tenders group stays put
 * without anyone having to special-case it.
 *
 * Nothing here writes. Hiding a group is provably non-destructive because
 * SettingsWriteService::apply only touches keys present in the posted payload, so a
 * field that is not rendered is not posted and its stored value is left alone.
 */
final class SettingsVisibility
{
    /**
     * Filter the schema for one tenant.
     *
     * @param  array  $sections  SettingsSchema::sections()
     * @param  bool  $showAll  the "show settings for other business types" escape hatch
     * @return array{0:array,1:int}  [filtered sections, number of groups hidden]
     */
    public static function apply(array $sections, $tenant, bool $showAll = false): array
    {
        $vertical = self::verticalOf($tenant);
        $hidden = 0;

        foreach ($sections as $si => $section) {
            if (! isset($section['groups']) || ! is_array($section['groups'])) {
                continue;                                   // e.g. the Team section (special => team)
            }
            $keep = [];
            foreach ($section['groups'] as $g) {
                if ($showAll || self::visible($g, $vertical, $tenant)) {
                    // Mark it so the screen can say WHY an unrelated group is on show,
                    // rather than the shop wondering if the filter is broken.
                    if (! self::matches($g, $vertical)) {
                        $g['off_vertical'] = true;
                    }
                    $keep[] = $g;
                } else {
                    $hidden++;
                }
            }
            $sections[$si]['groups'] = array_values($keep);
        }

        // A section left with no groups at all would render as an empty page.
        $sections = array_values(array_filter($sections, fn ($s) => ! isset($s['groups']) || $s['groups'] !== []));

        return [$sections, $hidden];
    }

    /** Does this group belong to this business type? (No `only` key = belongs everywhere.) */
    public static function matches(array $group, string $vertical): bool
    {
        $only = $group['only'] ?? null;
        if (! is_array($only) || $only === []) {
            return true;
        }

        return in_array($vertical, $only, true);
    }

    /** Show it if it matches the business type, or if this shop has set something in it. */
    public static function visible(array $group, string $vertical, $tenant): bool
    {
        return self::matches($group, $vertical) || self::configured($group, $tenant);
    }

    /** Has this shop set any field in the group to something other than its default? */
    public static function configured(array $group, $tenant): bool
    {
        if ($tenant === null || ! method_exists($tenant, 'setting')) {
            return false;
        }
        foreach (($group['fields'] ?? []) as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $default = $f['default'] ?? '';
            try {
                $stored = $tenant->setting($key, null);
            } catch (\Throwable $e) {
                continue;                                    // a bad setting must not hide the screen
            }
            if ($stored === null) {
                continue;                                    // never written
            }
            if (self::norm($stored) !== self::norm($default)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare a stored value against a schema default without type games.
     * Settings come back as bool, int, float or string depending on how they were
     * written, so "false" from a checkbox and false from a default must compare equal.
     */
    public static function norm($v): string
    {
        // Empty, null, false, "false", "0" and 0 all mean "not set" — collapsing them to one
        // token stops a blank string being read as a deliberate change away from `false`.
        if ($v === null || $v === false) {
            return '0';
        }
        if ($v === true) {
            return '1';
        }
        if (is_array($v)) {
            return json_encode($v) ?: '0';
        }
        $s = trim((string) $v);
        $low = mb_strtolower($s);
        if ($s === '' || $low === 'false') {
            return '0';
        }
        if ($low === 'true') {
            return '1';
        }
        // 0.03 and "0.030" are the same setting; 5 and "5" likewise.
        if (is_numeric($s)) {
            $n = rtrim(rtrim(sprintf('%.6F', (float) $s), '0'), '.');

            return ($n === '' || $n === '-0') ? '0' : $n;
        }

        return $s;
    }

    /** The shop's business type, defaulting the way Vertical does. */
    public static function verticalOf($tenant): string
    {
        try {
            return $tenant ? Vertical::of($tenant) : Vertical::GROCERY;
        } catch (\Throwable $e) {
            return Vertical::GROCERY;
        }
    }
}
