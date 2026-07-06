<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Mobile app home — the tap-tile launcher Jayesh adds to his iPhone home screen. */
class LauncherController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/app_home.html');
        if (! is_file($path)) abort(500, 'App home asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }
}
