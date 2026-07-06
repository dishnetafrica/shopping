<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Decision;

use CloudBSS\Kernel\Support\{EnterpriseObject, Clock, IdGenerator};

/**
 * A recorded CHOICE — the "why", as a first-class object.
 * Captures the policy VERSION + parameters + structured inputs + evidence event-ids,
 * so an old decision stays explainable even after the policy changes.
 */
final class Decision extends EnterpriseObject
{
    public function __construct(
        string $tenantId, string $entityId,
        public readonly string $type,
        public readonly string $makerKind,        // human | ai | policy
        public readonly string $makerId,
        public readonly string $policyName,
        public readonly int    $policyVersion,    // <-- explainable after policy change
        public readonly array  $parameters,       // policy params as-of decision time
        public readonly array  $inputs,           // STRUCTURED, not display strings
        public readonly array  $evidenceRefs,     // event ids the decision was based on
        public readonly string $outcome,
        public readonly string $reason,
        public readonly int    $confidence,
        public readonly bool   $requiresHuman,
        public readonly ?string $linkedRef = null,
        ?Clock $clock = null, ?IdGenerator $ids = null
    ) {
        parent::__construct($tenantId, $entityId, $clock, $ids);
        $this->status = $requiresHuman ? 'pending_human' : 'decided';
    }

    protected static function idPrefix(): string { return 'dec'; }

    /** The full why, serialisable into a DecisionMade event so it lives in the log. */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'makerKind' => $this->makerKind, 'makerId' => $this->makerId,
            'policyName' => $this->policyName, 'policyVersion' => $this->policyVersion, 'parameters' => $this->parameters,
            'inputs' => $this->inputs, 'evidenceRefs' => $this->evidenceRefs, 'outcome' => $this->outcome,
            'reason' => $this->reason, 'confidence' => $this->confidence, 'requiresHuman' => $this->requiresHuman,
            'linkedRef' => $this->linkedRef,
        ];
    }
}
