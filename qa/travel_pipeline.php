<?php declare(strict_types=1);
// Pure test of the travel pipeline: twelve stages and every allowed / refused move. Framework-free.
//   php qa/travel_pipeline.php
require __DIR__ . '/../app/Services/Travel/Enquiry/TravelPipeline.php';

use App\Services\Travel\Enquiry\TravelPipeline as P;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

chk('twelve stages', count(P::STAGES), 12);
chk('stages are unique', count(array_unique(P::STAGES)), 12);
chk('the twelve the brief lists, in order', P::STAGES, ['NEW_ENQUIRY', 'DETAILS_COLLECTED', 'FLIGHT_SEARCHED', 'QUOTATION_SENT', 'AWAITING_CUSTOMER',
    'READY_FOR_PAYMENT', 'PAYMENT_PENDING', 'BOOKING_CONFIRMED', 'TICKET_ISSUED', 'COMPLETED', 'CANCELLED', 'LOST']);

// The complete allowed table. Anything not listed here must be refused.
$allowed = [
    'NEW_ENQUIRY'       => ['DETAILS_COLLECTED', 'CANCELLED', 'LOST'],
    'DETAILS_COLLECTED' => ['NEW_ENQUIRY', 'FLIGHT_SEARCHED', 'QUOTATION_SENT', 'CANCELLED', 'LOST'],
    'FLIGHT_SEARCHED'   => ['DETAILS_COLLECTED', 'QUOTATION_SENT', 'CANCELLED', 'LOST'],
    'QUOTATION_SENT'    => ['AWAITING_CUSTOMER', 'READY_FOR_PAYMENT', 'DETAILS_COLLECTED', 'CANCELLED', 'LOST'],
    'AWAITING_CUSTOMER' => ['QUOTATION_SENT', 'READY_FOR_PAYMENT', 'DETAILS_COLLECTED', 'CANCELLED', 'LOST'],
    'READY_FOR_PAYMENT' => ['PAYMENT_PENDING', 'QUOTATION_SENT', 'CANCELLED', 'LOST'],
    'PAYMENT_PENDING'   => ['BOOKING_CONFIRMED', 'READY_FOR_PAYMENT', 'CANCELLED', 'LOST'],
    'BOOKING_CONFIRMED' => ['TICKET_ISSUED', 'CANCELLED'],
    'TICKET_ISSUED'     => ['COMPLETED', 'CANCELLED'],
    'COMPLETED'         => [],
    'CANCELLED'         => ['NEW_ENQUIRY'],
    'LOST'              => ['NEW_ENQUIRY'],
];
$wrong = [];
foreach (P::STAGES as $from) {
    foreach (P::STAGES as $to) {
        $want = in_array($to, $allowed[$from], true);
        if (P::canMove($from, $to) !== $want) { $wrong[] = "$from->$to"; }
    }
}
chk('all 144 from/to pairs match the table', $wrong, []);

chk('a stage cannot move to itself', P::canMove('NEW_ENQUIRY', 'NEW_ENQUIRY'), false);
chk('unknown stage refused', [P::canMove('NOPE', 'LOST'), P::canMove('NEW_ENQUIRY', 'NOPE'), P::isStage('NOPE')], [false, false, false]);
chk('cannot jump from a new enquiry straight to a ticket', P::canMove('NEW_ENQUIRY', 'TICKET_ISSUED'), false);
chk('cannot issue a ticket before the booking is confirmed', P::canMove('PAYMENT_PENDING', 'TICKET_ISSUED'), false);
chk('a confirmed booking cannot be marked lost', P::canMove('BOOKING_CONFIRMED', 'LOST'), false);
chk('completed is final', P::next('COMPLETED'), []);
chk('a lost enquiry can be reopened', P::canMove('LOST', 'NEW_ENQUIRY'), true);
chk('open stages', array_values(array_filter(P::STAGES, fn ($s) => P::isOpen($s))), ['NEW_ENQUIRY', 'DETAILS_COLLECTED', 'FLIGHT_SEARCHED', 'QUOTATION_SENT',
    'AWAITING_CUSTOMER', 'READY_FOR_PAYMENT', 'PAYMENT_PENDING', 'BOOKING_CONFIRMED', 'TICKET_ISSUED']);
chk('closed stages', P::CLOSED, ['COMPLETED', 'CANCELLED', 'LOST']);

// What the conversation itself may do.
chk('details complete moves a new enquiry forward', P::fromDetails('NEW_ENQUIRY', true), 'DETAILS_COLLECTED');
chk('details still missing: stay', P::fromDetails('NEW_ENQUIRY', false), null);
chk('a change that leaves a gap moves it back', P::fromDetails('DETAILS_COLLECTED', false), 'NEW_ENQUIRY');
chk('complete and already collected: stay', P::fromDetails('DETAILS_COLLECTED', true), null);
foreach (['FLIGHT_SEARCHED', 'QUOTATION_SENT', 'AWAITING_CUSTOMER', 'READY_FOR_PAYMENT', 'PAYMENT_PENDING', 'BOOKING_CONFIRMED', 'TICKET_ISSUED', 'COMPLETED', 'CANCELLED', 'LOST'] as $s) {
    chk("the conversation never moves a $s enquiry", [P::fromDetails($s, true), P::fromDetails($s, false)], [null, null]);
}
chk('every stage has a label and a tone', count(array_filter(P::STAGES, fn ($s) => P::label($s) !== '' && P::tone($s) !== '')), 12);
chk('label', P::label('DETAILS_COLLECTED'), 'Details collected');

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
