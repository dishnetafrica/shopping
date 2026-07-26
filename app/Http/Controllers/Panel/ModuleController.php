<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Capability\Process\ModulesCapability;
use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\Request;

/**
 * Module Manager API (V3 Migration — replaces legacy /panel/system). Reading the module list is open to
 * any tenant user; toggling a module is OWNER-ONLY (matches the modules.manage rule). Writes go to the
 * tenant's `settings.modules` map atomically via Tenant::putSetting — the same map the menu + API read.
 */
class ModuleController extends Controller
{
    private function tenantUser(Request $request)
    {
        $u = $request->user();
        return ($u && $u->tenant_id) ? $u : null;
    }

    /** GET /papi/modules — every module with its current on/off state. */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $out = [];
        foreach (ModulesCapability::MODULES as $key => [$label, $desc]) {
            $out[] = ['module' => $key, 'label' => $label, 'description' => $desc,
                'enabled' => Access::tenantModuleEnabled($u->tenant, $key)];
        }

        return response()->json(['ok' => true, 'modules' => $out]);
    }

    /** POST /papi/modules/toggle {module, enabled} — owner-only. Persists to settings.modules. */
    public function toggle(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $u->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $module = strtolower(trim((string) $request->input('module', '')));
        if (! array_key_exists($module, ModulesCapability::MODULES)) {
            return response()->json(['ok' => false, 'error' => 'bad_module'], 422);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);

        $t = $u->tenant;
        $mods = $t->setting('modules', []);
        if (! is_array($mods)) {
            $mods = [];
        }
        $mods[$module] = $enabled;
        $t->putSetting('modules', $mods);

        return response()->json(['ok' => true, 'module' => $module, 'enabled' => $enabled]);
    }
}
