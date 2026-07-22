<?php

namespace App\Http\Controllers\Panel;

use App\BOS\Projections\DailyToday;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * BOS Slice 3 — Today. GET /papi/bos-today returns the signed-in tenant's day: greeting, KPI tiles,
 * and ranked activities. Pure read (a projection); no writes, no rule logic.
 */
class BosTodayController extends Controller
{
    public function show(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        try {
            $today = (new DailyToday((int) $t->id))->build();
        } catch (\Throwable $e) {
            \Log::warning('bos-today failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'today_unavailable'], 200);
        }

        return response()->json(['ok' => true] + $today);
    }
}
