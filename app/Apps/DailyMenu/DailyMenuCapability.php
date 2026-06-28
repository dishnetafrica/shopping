<?php
namespace App\Apps\DailyMenu;

use App\Services\Knowledge\Contracts\Capability;
use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\Intent;
use App\Services\Knowledge\OperationalStateStore;

/** The first application capability on OKE: daily menus, specials, availability. */
class DailyMenuCapability implements Capability
{
    public function __construct(private OperationalStateStore $state) {}

    public function name(): string { return 'daily_menu'; }
    public function intents(): array { return [Intent::MENU, Intent::AVAILABILITY, Intent::SPECIAL]; }
    public function extractor(): Extractor { return new MenuExtractor(); }
    public function projector(): Projector { return new MenuProjector($this->state); }
}
