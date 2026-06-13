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

        $middleware->alias([
            'tenant.user'   => \App\Http\Middleware\SetTenantFromUser::class,
            'tenant.domain' => \App\Http\Middleware\IdentifyTenantByDomain::class,
        ]);
        // WhatsApp webhook is called by Evolution/Meta — exclude from CSRF.
        $middleware->validateCsrfTokens(except: ['api/webhook/*', 'papi/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {})
    ->create();
