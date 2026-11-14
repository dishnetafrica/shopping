<?php

namespace App\Services\Travel\Enquiry;

/**
 * The travel enquiry pipeline: the twelve stages and which moves between them are allowed.
 * PURE — no database, no framework — so every transition is provable in a harness.
 *
 * Milestone 1 moves an enquiry automatically only between NEW_ENQUIRY and DETAILS_COLLECTED (as the
 * customer supplies or changes details). Every other move is made by a member of staff, and is
 * still checked against this table. Later milestones add automatic moves (search, quotation,
 * payment, ticket) without changing the table.
 */
final class TravelPipeline
{
    public const NEW_ENQUIRY       = 'NEW_ENQUIRY';
    public const DETAILS_COLLECTED = 'DETAILS_COLLECTED';
    public const FLIGHT_SEARCHED   = 'FLIGHT_SEARCHED';
    public const QUOTATION_SENT    = 'QUOTATION_SENT';
    public const AWAITING_CUSTOMER = 'AWAITING_CUSTOMER';
    public const READY_FOR_PAYMENT = 'READY_FOR_PAYMENT';
    public const PAYMENT_PENDING   = 'PAYMENT_PENDING';
    public const BOOKING_CONFIRMED = 'BOOKING_CONFIRMED';
    public const TICKET_ISSUED     = 'TICKET_ISSUED';
    public const COMPLETED         = 'COMPLETED';
    public const CANCELLED         = 'CANCELLED';
    public const LOST              = 'LOST';

    public const STAGES = [
        self::NEW_ENQUIRY, self::DETAILS_COLLECTED, self::FLIGHT_SEARCHED, self::QUOTATION_SENT,
        self::AWAITING_CUSTOMER, self::READY_FOR_PAYMENT, self::PAYMENT_PENDING, self::BOOKING_CONFIRMED,
        self::TICKET_ISSUED, self::COMPLETED, self::CANCELLED, self::LOST,
    ];

    /** Stages in which the enquiry is finished. Nothing is "open" here. */
    public const CLOSED = [self::COMPLETED, self::CANCELLED, self::LOST];

    /** from => allowed next stages. */
    private const NEXT = [
        self::NEW_ENQUIRY       => [self::DETAILS_COLLECTED, self::CANCELLED, self::LOST],
        self::DETAILS_COLLECTED => [self::NEW_ENQUIRY, self::FLIGHT_SEARCHED, self::QUOTATION_SENT, self::CANCELLED, self::LOST],
        self::FLIGHT_SEARCHED   => [self::DETAILS_COLLECTED, self::QUOTATION_SENT, self::CANCELLED, self::LOST],
        self::QUOTATION_SENT    => [self::AWAITING_CUSTOMER, self::READY_FOR_PAYMENT, self::DETAILS_COLLECTED, self::CANCELLED, self::LOST],
        self::AWAITING_CUSTOMER => [self::QUOTATION_SENT, self::READY_FOR_PAYMENT, self::DETAILS_COLLECTED, self::CANCELLED, self::LOST],
        self::READY_FOR_PAYMENT => [self::PAYMENT_PENDING, self::QUOTATION_SENT, self::CANCELLED, self::LOST],
        self::PAYMENT_PENDING   => [self::BOOKING_CONFIRMED, self::READY_FOR_PAYMENT, self::CANCELLED, self::LOST],
        // Once a booking exists it can be cancelled but not "lost" — money and a supplier record are involved.
        self::BOOKING_CONFIRMED => [self::TICKET_ISSUED, self::CANCELLED],
        self::TICKET_ISSUED     => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED         => [],
        // A closed enquiry can be reopened; it starts again from the beginning.
        self::CANCELLED         => [self::NEW_ENQUIRY],
        self::LOST              => [self::NEW_ENQUIRY],
    ];

    private const LABELS = [
        self::NEW_ENQUIRY       => 'New enquiry',
        self::DETAILS_COLLECTED => 'Details collected',
        self::FLIGHT_SEARCHED   => 'Flights searched',
        self::QUOTATION_SENT    => 'Quotation sent',
        self::AWAITING_CUSTOMER => 'Awaiting customer',
        self::READY_FOR_PAYMENT => 'Ready for payment',
        self::PAYMENT_PENDING   => 'Payment pending',
        self::BOOKING_CONFIRMED => 'Booking confirmed',
        self::TICKET_ISSUED     => 'Ticket issued',
        self::COMPLETED         => 'Completed',
        self::CANCELLED         => 'Cancelled',
        self::LOST              => 'Lost',
    ];

    public static function isStage(string $s): bool
    {
        return in_array($s, self::STAGES, true);
    }

    public static function isOpen(string $s): bool
    {
        return self::isStage($s) && ! in_array($s, self::CLOSED, true);
    }

    /** @return string[] */
    public static function next(string $from): array
    {
        return self::NEXT[$from] ?? [];
    }

    public static function canMove(string $from, string $to): bool
    {
        return $from !== $to && in_array($to, self::next($from), true);
    }

    public static function label(string $s): string
    {
        return self::LABELS[$s] ?? ucfirst(strtolower(str_replace('_', ' ', $s)));
    }

    /** Badge tone for the panel. */
    public static function tone(string $s): string
    {
        return match ($s) {
            self::NEW_ENQUIRY => 'info',
            self::DETAILS_COLLECTED, self::FLIGHT_SEARCHED, self::READY_FOR_PAYMENT, self::PAYMENT_PENDING => 'warn',
            self::QUOTATION_SENT, self::AWAITING_CUSTOMER => 'info',
            self::BOOKING_CONFIRMED, self::TICKET_ISSUED, self::COMPLETED => 'ok',
            self::LOST, self::CANCELLED => 'danger',
            default => 'default',
        };
    }

    /**
     * The stage an enquiry should be in from its details alone, when it is still in the two stages
     * the conversation controls. Returns null when the current stage is one only staff (or a later
     * milestone) may change — a customer editing dates must never pull a quoted enquiry backwards
     * silently.
     */
    public static function fromDetails(string $current, bool $complete): ?string
    {
        if ($current === self::NEW_ENQUIRY && $complete) {
            return self::DETAILS_COLLECTED;
        }
        if ($current === self::DETAILS_COLLECTED && ! $complete) {
            return self::NEW_ENQUIRY;
        }

        return null;
    }
}
