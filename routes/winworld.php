<?php
use Illuminate\Support\Facades\Route;

/* ===== WINWORLD MES ROUTES ===== */
Route::middleware(['web', 'auth', \App\Http\Middleware\SetTenantFromUser::class])->group(function () {
    Route::get('/panel/production', [\App\Http\Controllers\Panel\WinworldPanelController::class, 'production']);
    Route::get('/panel/indents',    [\App\Http\Controllers\Panel\WinworldIndentController::class, 'indentsPage']);
    Route::get('/panel/planning',   [\App\Http\Controllers\Panel\WinworldIndentController::class, 'planningPage']);
    Route::get('/panel/dashboard',  [\App\Http\Controllers\Panel\WinworldDashboardController::class, 'dashboardPage']);
    Route::get('/panel/oif',        [\App\Http\Controllers\Panel\WinworldOifController::class, 'oifPage']);
    Route::get('/panel/sales',      [\App\Http\Controllers\Panel\WinworldSalesController::class, 'salesPage']);
    Route::get('/panel/exceptions', [\App\Http\Controllers\Panel\WinworldExceptionController::class, 'exceptionsPage']);
    Route::get('/panel/winworld',   [\App\Http\Controllers\Panel\WinworldHubController::class, 'hub']);
    Route::get('/panel/training',   [\App\Http\Controllers\Panel\WinworldHubController::class, 'training']);

    Route::prefix('papi')->group(function () {
        Route::get('ww-machines',    [\App\Http\Controllers\Panel\WinworldApiController::class, 'machines']);
        Route::get('ww-jobs',        [\App\Http\Controllers\Panel\WinworldApiController::class, 'jobs']);
        Route::get('ww-entry-save',  [\App\Http\Controllers\Panel\WinworldApiController::class, 'entrySave']);
        Route::get('ww-options',     [\App\Http\Controllers\Panel\WinworldIndentController::class, 'options']);
        Route::get('ww-indents',     [\App\Http\Controllers\Panel\WinworldIndentController::class, 'indentList']);
        Route::get('ww-indent',      [\App\Http\Controllers\Panel\WinworldIndentController::class, 'indentGet']);
        Route::get('ww-indent-clone',[\App\Http\Controllers\Panel\WinworldIndentController::class, 'indentClone']);
        Route::post('ww-indent-save',[\App\Http\Controllers\Panel\WinworldIndentController::class, 'indentSave']);
        Route::get('ww-plan-list',   [\App\Http\Controllers\Panel\WinworldIndentController::class, 'planList']);
        Route::post('ww-plan-save',  [\App\Http\Controllers\Panel\WinworldIndentController::class, 'planSave']);
        Route::get('ww-dashboard',   [\App\Http\Controllers\Panel\WinworldDashboardController::class, 'data']);
        Route::get('ww-oif',         [\App\Http\Controllers\Panel\WinworldOifController::class, 'oifData']);
        Route::post('ww-qc-save',    [\App\Http\Controllers\Panel\WinworldOifController::class, 'qcSign']);
        Route::get('ww-sales-options',[\App\Http\Controllers\Panel\WinworldSalesController::class, 'options']);
        Route::get('ww-sales',       [\App\Http\Controllers\Panel\WinworldSalesController::class, 'list']);
        Route::post('ww-sales-save', [\App\Http\Controllers\Panel\WinworldSalesController::class, 'save']);
        Route::post('ww-sales-advance',[\App\Http\Controllers\Panel\WinworldSalesController::class, 'advance']);
        Route::post('ww-sales-approve',[\App\Http\Controllers\Panel\WinworldSalesController::class, 'approve']);
        Route::post('ww-sales-action',[\App\Http\Controllers\Panel\WinworldSalesController::class, 'action']);
        Route::get('ww-exc-options', [\App\Http\Controllers\Panel\WinworldExceptionController::class, 'options']);
        Route::get('ww-exceptions',  [\App\Http\Controllers\Panel\WinworldExceptionController::class, 'list']);
        Route::post('ww-exc-save',   [\App\Http\Controllers\Panel\WinworldExceptionController::class, 'save']);
        Route::post('ww-exc-approve',[\App\Http\Controllers\Panel\WinworldExceptionController::class, 'approve']);
        Route::post('ww-exc-resolve',[\App\Http\Controllers\Panel\WinworldExceptionController::class, 'resolve']);
        Route::post('ww-exc-action', [\App\Http\Controllers\Panel\WinworldExceptionController::class, 'action']);
    });
});
