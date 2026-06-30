<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // EasyPanel/Traefik sits in front of the app — trust its forwarded headers.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo('/app/login');

        // Stamp every request with an id + the running build (traceability / deploy proof).
        $middleware->append(\App\Http\Middleware\RequestId::class);

        $middleware->alias([
            'tenant.user'   => \App\Http\Middleware\SetTenantFromUser::class,
            'tenant.domain' => \App\Http\Middleware\IdentifyTenantByDomain::class,
        ]);
        // WhatsApp webhook is called by Evolution/Meta — exclude from CSRF.
        $middleware->validateCsrfTokens(except: ['api/webhook/*', 'papi/*', '*/order', '*/auth/*', '*/account/*', 'r/*', 't/*', 'a/*', 'ops/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Error tracking: capture exceptions to Sentry if the package is installed AND a DSN is set.
        // Fully guarded — a no-op when Sentry isn't present, so it can ship before you create an account.
        if (class_exists(\Sentry\Laravel\Integration::class)) {
            \Sentry\Laravel\Integration::handles($exceptions);
        }

        // For API-ish paths, return JSON with a request id instead of an HTML error page,
        // so the frontend and support get a real message + a traceable id (not "Not Found" HTML).
        $exceptions->render(function (\Throwable $e, $request) {
            $path = ltrim($request->path(), '/');
            $apiish = $request->expectsJson()
                || str_starts_with($path, 'papi/')
                || str_starts_with($path, 'ops/')
                || str_starts_with($path, 'api/');
            if (! $apiish) return null; // let web pages use the default error view

            $status = 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
            } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                $status = 422;
            } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $status = 401;
            } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $status = 404;
            }

            $rid = (string) ($request->attributes->get('request_id') ?? '');
            $payload = [
                'ok'         => false,
                'error'      => $status >= 500 ? 'Something went wrong on our side.' : $e->getMessage(),
                'status'     => $status,
                'request_id' => $rid,
            ];
            // Only expose the raw exception detail when app debug is on.
            if (config('app.debug') && $status >= 500) {
                $payload['detail'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            }
            return response()->json($payload, $status);
        });
    })
    ->create();
