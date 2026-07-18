<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;

/**
 * Help & Training — the UCRM-style built-in manual, as a v3 workspace.
 *
 * Content lives in config/guide.php (edit wording there, no code changes). Sections
 * are ROLE-AWARE: each carries a menu key, and a user only sees the chapters for
 * screens they can actually open — Jayesh gets the buyer manual, not the Email
 * Center admin guide. Every section ends with deep links into the real workspace
 * it teaches, so reading and doing are one tap apart.
 */
final class GuideCapability implements ProcessCapability
{
    public function key(): string { return 'guide'; }

    public function permission(): string { return ''; }   // the manual is for everyone

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $sections = (array) config('guide', []);

        $tabs = [];
        $tabSections = [];
        foreach ($sections as $s) {
            $menu = $s['menu'] ?? null;
            if ($menu !== null && ! $ctx->can('menu:'.$menu)) {
                continue;   // role-aware: hide chapters for screens this user can't open
            }

            $cards = [];
            foreach ((array) ($s['cards'] ?? []) as $card) {
                $cards[] = [
                    'kind'  => 'dl',
                    'title' => (string) $card['title'],
                    'rows'  => array_map(
                        fn ($r) => [(string) $r[0], (string) $r[1]],
                        (array) $card['rows']
                    ),
                ];
            }
            if ($links = (array) ($s['links'] ?? [])) {
                $cards[] = [
                    'kind'  => 'links',
                    'title' => 'Try it now',
                    'items' => array_map(
                        fn ($key, $label) => ['label' => $label, 'href' => '/v3#/'.$key],
                        array_keys($links), array_values($links)
                    ),
                ];
            }

            $tabs[] = ['key' => $s['key'], 'label' => $s['title']];
            $tabSections[$s['key']] = $cards;
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:guide', 'title' => 'Help & Training',
                'subtitle' => count($tabs).' chapters for your role · reading and doing, one tap apart',
            ])
            ->tabSections($tabSections)
            ->tabs($tabs)
            ->meta(['capability' => 'guide', 'ttl' => 3600, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['chapters' => count((array) config('guide', []))];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
