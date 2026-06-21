<?php

namespace App\Services\Bot\Validation;

use App\Services\Bot\Discovery\DiscoveryReport;
use App\Services\Bot\Discovery\MessageCorpus;
use App\Services\Bot\Discovery\WhatsAppExportParser;
use App\Services\Bot\Readiness\BusinessReadinessEvaluator;

/**
 * Platform Validation — the runner. Pure logic.
 *
 * Executes the real onboarding pipeline on a business's history and compares the result to a known
 * ground truth:
 *   1. import history (export)  2. discovery scan  3. Business DNA report
 *   4. Go-Live evaluation       5. compare discovered vs actual
 *
 * "readiness_score" tracked here is *operational readiness* — the coverage the system learned
 * (the Business DNA readiness, not the approval-gated go-live ceiling) — which is what the
 * 70-90%-within-15-min success metric refers to.
 */
class ValidationRunner
{
    public static function run(string $businessType, string $export, array $actual, array $ownerNames = [], array $orders = []): array
    {
        $rows   = WhatsAppExportParser::parse($export, $ownerNames);
        $corpus = MessageCorpus::fromRows($rows);
        $report = DiscoveryReport::build($corpus, $orders, (string) ($actual['name'] ?? $businessType));

        $coverageReadiness = (int) $report['readiness_score'];

        // Go-Live mode (approval-gated) — informational; readiness tracked is coverage.
        $golive = BusinessReadinessEvaluator::evaluate(0, $report, [
            'areas_seen'          => (array) ($actual['delivery_areas'] ?? []),
            'supported_languages' => ['English', 'Gujlish', 'Swahili', 'Hindi'],
        ]);

        $metrics = ValidationComparator::compare($report['sections'], $actual, $coverageReadiness);

        $sec = $report['sections'];
        $deliveryRules = count($sec['delivery']['areas'] ?? [])
            + (! empty($sec['delivery']['fee']) ? 1 : 0)
            + (! empty($sec['delivery']['free_threshold']) ? 1 : 0);

        return [
            'business_type'          => $businessType,
            'messages_scanned'       => $corpus->total(),
            'products_found'         => count($sec['top_products'] ?? []),
            'faq_found'              => count($sec['faqs'] ?? []),
            'delivery_rules_found'   => $deliveryRules,
            'readiness_score'        => $coverageReadiness,
            'accuracy_score'         => $metrics['overall_accuracy'],
            'recommended_mode'       => $golive['recommended_mode'],
            'products_discovery_pct' => $metrics['products']['recall'],
            'faq_discovery_pct'      => $metrics['faqs']['recall'],
            'can_go_live'            => $coverageReadiness >= 70,
            'metrics'                => $metrics,
        ];
    }
}
