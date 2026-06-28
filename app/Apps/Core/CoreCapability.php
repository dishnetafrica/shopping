<?php
namespace App\Apps\Core;

use App\Services\Knowledge\Contracts\Capability;
use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\Intent;
use App\Services\Knowledge\OperationalStateStore;

/** Cross-industry capability every shop gets: price, schedule, facility, policy. */
class CoreCapability implements Capability
{
    public function __construct(private OperationalStateStore $state) {}

    public function name(): string { return 'core'; }
    public function intents(): array { return [Intent::PRICE, Intent::SCHEDULE, Intent::FACILITY, Intent::POLICY]; }
    public function extractor(): Extractor { return new CoreExtractor(); }
    public function projector(): Projector { return new CoreProjector($this->state); }
}
