<?php
namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/** For public tenant pages (storefront / tracking) resolved by subdomain. */
class IdentifyTenantByDomain
{
    public function __construct(protected TenantContext $ctx) {}

    public function handle(Request $request, Closure $next)
    {
        $root = config('tenancy.root_domain');
        $host = $request->getHost();
        $sub  = str_replace('.'.$root, '', $host);

        if ($sub && $sub !== $root && $sub !== 'app' && $sub !== 'admin') {
            $this->ctx->asSuperAdmin();                       // bypass scope to look up the tenant
            $tenant = Tenant::where('slug', $sub)->first();
            $this->ctx->asSuperAdmin(false);
            if ($tenant) $this->ctx->set($tenant->id);
        }
        return $next($request);
    }
}
