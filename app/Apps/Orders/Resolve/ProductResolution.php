<?php
namespace App\Apps\Orders\Resolve;

use App\Apps\Orders\Dto\CartLine;

/**
 * The resolver's answer: ranked candidates with scores + a decision (auto-resolve | clarify | none).
 * This is what makes resolution debuggable — you can see WHY a query resolved or asked.
 */
final class ProductResolution
{
    public const AUTO = 'auto';
    public const CLARIFY = 'clarify';
    public const NONE = 'none';

    /**
     * @param array<int,array{product_id:?int,name:string,score:float}> $candidates ranked, best first
     */
    public function __construct(
        public string $query,
        public array $candidates,
        public string $decision,
        public ?CartLine $line = null,        // filled when AUTO
        public ?string $weightIssue = null,   // min | no_reference | needs_weight_clarify | not_weight_priced
    ) {}

    public function top(): ?array { return $this->candidates[0] ?? null; }
}
