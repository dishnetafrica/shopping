<?php
use Illuminate\Support\Facades\Route;

// Public order-tracking page (custom Blade), resolved by tenant subdomain.
// Route::middleware(\App\Http\Middleware\IdentifyTenantByDomain::class)
//     ->get('/track/{order}/{token}', [\App\Http\Controllers\TrackController::class, 'show']);

Route::get('/', fn () => redirect('/app')); // Filament tenant panel
