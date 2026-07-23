<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Dependency-free error alerting (Constitution v1.0 · P0 Reliability — observability).
 *
 * Sentry is already wired in bootstrap/app.php (a no-op until the package + DSN exist). This complements
 * it so you are not blind BEFORE Sentry is set up: on a real server-side (>=500) exception it writes a
 * STRUCTURED log line (request id, user, tenant, route) and fires a rate-limited webhook (Slack/Discord/
 * any HTTP) if ERROR_WEBHOOK_URL is set. With no webhook it just enriches the log — zero behaviour change.
 *
 * Iron rule: an error notifier must NEVER cause a second error — everything is wrapped and swallowed.
 */
class ErrorNotifier
{
    public static function report(\Throwable $e, ?Request $request = null): void
    {
        try {
            if (self::shouldSkip($e)) {
                return;
            }
            $request = $request ?: (function_exists('request') ? request() : null);
            $ctx = self::context($e, $request);
            Log::channel((string) config('errors.log_channel', 'stack'))->error('unhandled @ ' . $ctx['where'], $ctx);

            $url = (string) config('errors.webhook', '');
            if ($url === '' || ! (bool) config('errors.enabled', true)) {
                return; // log-only mode
            }

            // Throttle identical errors so a hot loop can't flood the channel.
            $key = 'errnotify:' . md5(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine());
            $mins = max(1, (int) config('errors.throttle_minutes', 10));
            if (! Cache::add($key, 1, now()->addMinutes($mins))) {
                return; // already alerted recently
            }

            Http::timeout(4)->post($url, ['text' => self::text($ctx)]);
        } catch (\Throwable $ignored) {
            // never throw from here
        }
    }

    /** Only alert on genuine server-side failures. */
    private static function shouldSkip(\Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) return true;
        if ($e instanceof \Illuminate\Auth\AuthenticationException) return true;
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) return true;
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) return true;
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < (int) config('errors.min_status', 500);
        }
        return false;
    }

    private static function context(\Throwable $e, ?Request $r): array
    {
        $u = $r ? $r->user() : null;
        return [
            'where'      => basename($e->getFile()) . ':' . $e->getLine(),
            'type'       => get_class($e),
            'message'    => $e->getMessage(),
            'url'        => $r ? ($r->method() . ' ' . ltrim($r->path(), '/')) : 'cli',
            'request_id' => $r ? (string) $r->attributes->get('request_id', '') : '',
            'user_id'    => $u->id ?? null,
            'tenant'     => $u->tenant_id ?? null,
        ];
    }

    private static function text(array $c): string
    {
        return sprintf(
            "🔴 %s error @ %s\n%s: %s\n%s · user %s · tenant %s · rid %s",
            (string) config('errors.app_name', 'CloudBSS'),
            $c['where'],
            class_basename($c['type']),
            mb_substr((string) $c['message'], 0, 300),
            $c['url'],
            $c['user_id'] ?? '-',
            $c['tenant'] ?? '-',
            $c['request_id'] !== '' ? $c['request_id'] : '-'
        );
    }
}
