<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Staff never see the Filament dashboard — visiting /app sends them straight to
 * the simple mobile Seller Panel. (The operator console at /admin is unaffected.)
 */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $this->redirect('/panel/m');
    }
}
