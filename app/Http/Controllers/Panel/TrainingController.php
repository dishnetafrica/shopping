<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * GET /panel/training — the in-product Training Center (2026-07-31).
 *
 * One searchable page teaching every menu + the known confusion spots (bot behaviour,
 * catalogue images, credit sales, stock counts, trust queue, "why can't I see a menu").
 * Static content served from resources/panel/help-center.html — deliberately NOT the
 * pre-existing resources/panel/training.html, which belongs to the Win World MES vertical.
 *
 * Screens deep-link into sections: /panel/training#pos, #trust, #settings, #faq …
 */
class TrainingController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/help-center.html');
        if (! is_file($path)) {
            abort(500, 'Training asset missing.');
        }
        $html = str_replace(
            '{{TENANT}}',
            e((string) ($user->tenant->name ?? 'CloudBSS')),
            file_get_contents($path)
        );

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
