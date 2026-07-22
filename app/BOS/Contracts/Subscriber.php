<?php

namespace App\BOS\Contracts;

use App\BOS\Event;

/**
 * A subscriber reacts to events. Subscribers are how the layers communicate (doc 5): accounting,
 * inventory, notifications, the timeline, and AI all subscribe — no object ever calls another.
 * Subscribers must be side-effecting only; they never mutate the event itself.
 */
interface Subscriber
{
    /** Event names this subscriber wants, or ['*'] for all. */
    public function subscribesTo(): array;

    /** React to an accepted event. Must be best-effort — a failure here never rolls back the fact. */
    public function handle(Event $event): void;
}
