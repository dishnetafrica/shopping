<?php

namespace App\Http\Middleware;

use App\Services\Sales\SalesOwnership;
use Closure;
use Illuminate\Http\Request;

/**
 * Sales team (Internet-provider businesses only): one salesperson per customer in the chat inbox.
 *
 * On the inbox endpoints that read or reply to one customer (`phone` in the request):
 *   - a salesperson may open and reply to their own customers and to unassigned ones;
 *   - replying to an unassigned customer makes that salesperson the owner (first reply wins);
 *   - another salesperson's customer is refused, with the owner's name, so two people never
 *     contact the same customer independently;
 *   - owners/admins and managers are never restricted.
 *
 * Every other business passes straight through (SalesOwnership::enabled is false).
 * Usage in routes: ->middleware(SalesOwnershipGuard::class . ':reply') or ':read'.
 */
class SalesOwnershipGuard
{
    public function handle(Request $request, Closure $next, string $mode = 'read')
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        if (! $user || ! SalesOwnership::enabled($tenant)) {
            return $next($request);
        }
        $phone = (string) ($request->input('phone', $request->query('phone', '')));
        if (preg_replace('/[^0-9]/', '', $phone) === '') {
            return $next($request);                       // the endpoint itself rejects an empty number
        }

        $access = app(SalesOwnership::class)->checkAccess($tenant, $user, $phone, $mode === 'reply');
        if (! $access['allowed']) {
            $owner = $access['owner']?->name ?: 'another salesperson';

            return response()->json([
                'ok' => false, 'error' => 'owned_by_other', 'owner' => $owner,
                'message' => "This customer is handled by {$owner}. Ask a manager to reassign them if needed.",
            ], 403);
        }

        return $next($request);
    }
}
