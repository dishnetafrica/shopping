<?php

namespace App\Http\Middleware;

use App\Support\BuildInfo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every request a stable id and stamps the running build on every response.
 *
 * Why: when something breaks in production you want (1) an id you can grep in the logs and
 * quote to support, and (2) proof of WHICH build served the response — so "is the new code
 * live?" is answerable from any response's headers, not by injecting probe files.
 */
class RequestId
{
    public function handle(Request $request, Closure $next)
    {
        $rid = (string) $request->header('X-Request-Id', '');
        if ($rid === '' || strlen($rid) > 64) {
            $rid = (string) Str::uuid();
        }
        $request->attributes->set('request_id', $rid);

        // Attach to every log line for the rest of this request.
        Log::shareContext(['request_id' => $rid]);

        // If Sentry is installed, tag its scope with the same id so dashboard errors correlate.
        if (function_exists('Sentry\\configureScope')) {
            try {
                \Sentry\configureScope(function ($scope) use ($rid, $request) {
                    $scope->setTag('request_id', $rid);
                    $u = $request->user();
                    if ($u) {
                        $scope->setUser([
                            'id'        => $u->id ?? null,
                            'tenant_id' => $u->tenant_id ?? null,
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                // never let observability break the request
            }
        }

        $response = $next($request);

        try {
            $response->headers->set('X-Request-Id', $rid);
            $response->headers->set('X-App-Build', BuildInfo::id());
        } catch (\Throwable $e) {
            // never let header stamping break a response
        }

        return $response;
    }
}
