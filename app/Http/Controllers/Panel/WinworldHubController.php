<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Win World launcher: one page, side-nav, every screen one click away. */
class WinworldHubController extends Controller
{
    private function serve(Request $r, string $file)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/' . $file);
        if (! is_file($path)) abort(500, 'Asset missing: ' . $file);
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function hub(Request $r)      { return $this->serve($r, 'winworld-hub.html'); }
    public function training(Request $r) { return $this->serve($r, 'training.html'); }
}
