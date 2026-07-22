<?php

namespace App\BOS;

use App\BOS\Contracts\Subscriber;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * The append-only event spine (BOS v1.0). One entry point — emit() — writes a fact and fans it out
 * to subscribers. Idempotent: the same offline mutation, retried, is a no-op (ADR-010).
 *
 * A singleton so subscribers register once. Mirrors the existing registry pattern
 * (DashboardRegistry::default) — no service-provider edit required.
 */
class EventStore
{
    /** @var Subscriber[] */
    private array $subscribers = [];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= self::boot();
    }

    /** Register the default subscriber set here as they are built (kept empty in Slice 1). */
    private static function boot(): self
    {
        $s = new self();
        // $s->subscribe(new \App\BOS\Subscribers\TimelineSubscriber());  // added in later slices
        return $s;
    }

    public function subscribe(Subscriber $sub): void
    {
        $this->subscribers[] = $sub;
    }

    /**
     * Record a business fact and notify subscribers. Returns the Event (existing one if the
     * idempotency key was already seen — never a duplicate).
     *
     * @param array{subject_type?:string,subject_id?:int,actor?:array,from?:string,to?:string,idempotency_key?:string,occurred_at?:mixed,tenant_id?:int} $meta
     */
    public function emit(string $name, array $payload = [], array $meta = []): Event
    {
        $tenantId = (int) ($meta['tenant_id'] ?? $this->currentTenantId());
        $idem     = $meta['idempotency_key'] ?? null;

        // Idempotency — a retried offline mutation must not create a second fact.
        if ($idem !== null) {
            $existing = Event::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('idempotency_key', $idem)->first();
            if ($existing) return $existing;
        }

        $actor = (array) ($meta['actor'] ?? []);
        // Instrumentation (ADR-015): captured on EVERY event, from meta first, else from the request.
        $instr = InstrumentationContext::class;
        $event = new Event([
            'event_id'        => (string) Str::uuid(),
            'tenant_id'       => $tenantId,
            'name'            => $name,
            'subject_type'    => $meta['subject_type'] ?? null,
            'subject_id'      => isset($meta['subject_id']) ? (int) $meta['subject_id'] : null,
            'actor_type'      => (string) ($actor['type'] ?? 'system'),
            'actor_id'        => isset($actor['id']) ? (int) $actor['id'] : null,
            'actor_name'      => $actor['name'] ?? null,
            'from_state'      => $meta['from'] ?? null,
            'to_state'        => $meta['to'] ?? null,
            'payload'         => $payload ?: null,
            'idempotency_key' => $idem,
            'occurred_at'     => $meta['occurred_at'] ?? now(),
            'recorded_at'     => now(),
            'correlation_id'  => $meta['correlation_id'] ?? $instr::correlationId(),
            'causation_id'    => $meta['causation_id'] ?? $instr::currentCausation(),
            'device'          => $meta['device'] ?? $instr::device(),
            'connectivity'    => $meta['connectivity'] ?? $instr::connectivity(),
            'app_version'     => $meta['app_version'] ?? $instr::appVersion(),
            'latency_ms'      => isset($meta['latency_ms']) ? (int) $meta['latency_ms'] : null,
            'context'         => $meta['context'] ?? null,
        ]);
        $event->save();

        // Subscribers run "within" this event, so anything THEY emit records its causation → this event.
        InstrumentationContext::within($event->event_id, fn () => $this->dispatch($event));

        return $event;
    }

    private function dispatch(Event $event): void
    {
        foreach ($this->subscribers as $sub) {
            $wants = $sub->subscribesTo();
            if (! (in_array('*', $wants, true) || in_array($event->name, $wants, true))) {
                continue;
            }
            try {
                $sub->handle($event);
            } catch (\Throwable $e) {
                // A subscriber failure must NEVER unwind the recorded fact (best-effort fan-out).
                \Log::warning('BOS subscriber failed for ' . $event->name . ': ' . $e->getMessage());
            }
        }
    }

    private function currentTenantId(): int
    {
        try {
            $t = app(TenantContext::class);
            if (method_exists($t, 'id') && $t->id()) return (int) $t->id();
        } catch (\Throwable $e) { /* fall through */ }

        return (int) (auth()->user()->tenant_id ?? 0);
    }
}
