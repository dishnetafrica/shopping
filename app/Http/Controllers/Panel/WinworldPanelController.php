<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Serves the Win World mobile Production Entry screen behind the /app web session. */
class WinworldPanelController extends Controller
{
    public function production(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');

        $path = resource_path('panel/production.html');
        if (! is_file($path)) abort(500, 'Production panel asset missing.');

        $name = (string) ($user->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
