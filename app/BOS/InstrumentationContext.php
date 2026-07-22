<?php

namespace App\BOS;

use Illuminate\Support\Str;

/**
 * Per-request instrumentation for every event (ADR-015). Supplies a correlation id that groups all
 * events of one user action, a causation chain (which event caused which), and the device context.
 * Read once here so EventStore stays clean and every emit is measured consistently.
 */
class InstrumentationContext
{
    private static ?string $correlationId = null;
    /** @var string[] causation stack — the event currently being handled */
    private static array $causation = [];

    /** One id per request/action; groups all its events. Honours a client-supplied header. */
    public static function correlationId(): string
    {
        if (self::$correlationId === null) {
            self::$correlationId = self::header('X-BOS-Correlation') ?: (string) Str::uuid();
        }
        return self::$correlationId;
    }

    /** The event currently being handled (so subscriber-emitted events chain their causation). */
    public static function currentCausation(): ?string
    {
        return end(self::$causation) ?: null;
    }

    /** Run $cb with $eventId marked as the cause, so anything it emits records causation. */
    public static function within(string $eventId, callable $cb): void
    {
        self::$causation[] = $eventId;
        try { $cb(); } finally { array_pop(self::$causation); }
    }

    public static function device(): ?string       { return self::header('X-BOS-Device') ?: (self::isConsole() ? 'server' : null); }
    public static function connectivity(): ?string  { return self::header('X-BOS-Connectivity'); } // client marks 'offline' on queued writes
    public static function appVersion(): ?string    { return self::header('X-App-Version'); }

    private static function isConsole(): bool
    {
        try { return app()->runningInConsole(); } catch (\Throwable $e) { return false; }
    }

    private static function header(string $name): ?string
    {
        try {
            $r = request();
            $v = $r?->header($name);
            return is_string($v) && $v !== '' ? substr($v, 0, 60) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Test/CLI reset. */
    public static function reset(): void
    {
        self::$correlationId = null;
        self::$causation = [];
    }
}
