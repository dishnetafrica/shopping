<?php
namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/** Tenant-panel guard: bind the active tenant to the logged-in staff user. */
class SetTenantFromUser
{
    public function __construct(protected TenantContext $ctx) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->is_super_admin) {
            $this->ctx->asSuperAdmin();         // super admin sees all tenants
        } elseif ($user && $user->tenant_id) {
            $this->ctx->set($user->tenant_id);  // staff scoped to their business
        }
        return $next($request);
    }
}
