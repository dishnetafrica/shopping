<?php

namespace Tests\Feature\Sales;

use App\Services\WhatsApp\EvolutionGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sending to a customer whose number WhatsApp hides (DishNet, 18 Nov 2026). Such a chat is keyed by the
 * customer's hidden id (LID) digits; sent as plain digits Evolution answers 400 {"exists":false} and the
 * reply is lost — six bot replies to one customer were. Addressed as "<digits>@lid" it is accepted.
 * Ids and numbers are test values.
 */
class EvolutionHiddenIdSendTest extends TestCase
{
    private const LID = '234239681409999';

    private const PHONE = '211911000088';

    private array $known = [];      // what the fake WhatsApp accepts

    private function gateway(): EvolutionGateway
    {
        Http::fake(function (Request $r) {
            $n = (string) ($r->data()['number'] ?? '');
            if (in_array($n, $this->known, true)) {
                return Http::response(['key' => ['id' => 'OUT-' . $n]], 201);
            }
            if (str_contains($r->url(), 'broken')) {
                return Http::response(['status' => 400, 'response' => ['message' => ['instance not connected']]], 400);
            }

            return Http::response(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => [
                ['jid' => (str_contains($n, '@') ? $n : $n . '@s.whatsapp.net'), 'exists' => false, 'number' => $n],
            ]]], 400);
        });

        return new EvolutionGateway('https://evo.test', 'key');
    }

    private function numbersSent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->data()['number'] ?? null)->values()->all();
    }

    public function test_a_hidden_id_customer_gets_the_reply_and_later_sends_go_straight_there(): void
    {
        Cache::flush();
        $this->known = [self::LID . '@lid'];
        $gw = $this->gateway();

        $res = $gw->sendText('dishnet', self::LID, 'Hello Jel');

        $this->assertSame('OUT-' . self::LID . '@lid', $res['key']['id'] ?? null, 'delivered');
        $this->assertArrayNotHasKey('_ok', $res, 'reported as delivered, not failed');
        $this->assertSame([self::LID, self::LID . '@lid'], $this->numbersSent(), 'refused as a number, sent again as a hidden id');

        $gw->sendText('dishnet', self::LID, 'Second message');
        $this->assertSame([self::LID, self::LID . '@lid', self::LID . '@lid'], $this->numbersSent(), 'remembered: one request');

        // Documents (quotations), photos and pins take the same way.
        $gw->sendDocument('dishnet', self::LID, 'https://x.test/q.pdf', 'Quotation.pdf');
        $this->assertSame(self::LID . '@lid', $this->numbersSent()[3]);
    }

    public function test_a_normal_number_is_sent_once_exactly_as_before(): void
    {
        Cache::flush();
        $this->known = [self::PHONE];
        $res = $this->gateway()->sendText('dishnet', '+211 911 000 088', 'Hello');

        $this->assertSame('OUT-' . self::PHONE, $res['key']['id'] ?? null);
        $this->assertSame([self::PHONE], $this->numbersSent());
    }

    public function test_other_failures_are_not_retried_and_an_unknown_number_stays_a_failure(): void
    {
        Cache::flush();
        $this->known = [];
        $gw = $this->gateway();

        $down = $gw->sendText('broken', self::PHONE, 'Hello');
        $this->assertFalse($down['_ok']);
        $this->assertSame([self::PHONE], $this->numbersSent(), 'not a "no such number" refusal: sent once');

        $none = $gw->sendText('dishnet', '211900000000', 'Hello');
        $this->assertFalse($none['_ok'], 'unknown both ways: still a failure, so the recovery job retries later');
        $this->assertSame(400, $none['_status']);
        $this->assertFalse(Cache::has('wa_lid:211900000000'), 'nothing remembered');
    }

    public function test_a_remembered_hidden_id_that_is_refused_falls_back_to_the_number(): void
    {
        Cache::flush();
        Cache::forever('wa_lid:' . self::PHONE, 1);
        $this->known = [self::PHONE];

        $res = $this->gateway()->sendText('dishnet', self::PHONE, 'Hello');

        $this->assertSame('OUT-' . self::PHONE, $res['key']['id'] ?? null);
        $this->assertSame([self::PHONE . '@lid', self::PHONE], $this->numbersSent());
        $this->assertFalse(Cache::has('wa_lid:' . self::PHONE));
    }

    public function test_reading_the_refusal(): void
    {
        $this->assertTrue(EvolutionGateway::unknownNumber(['response' => ['message' => [['exists' => false, 'jid' => 'x']]]]));
        $this->assertFalse(EvolutionGateway::unknownNumber(['response' => ['message' => [['exists' => true]]]]));
        $this->assertFalse(EvolutionGateway::unknownNumber(['response' => ['message' => ['instance not connected']]]));
        $this->assertFalse(EvolutionGateway::unknownNumber(['error' => 'down']));
        $this->assertFalse(EvolutionGateway::unknownNumber([]));
    }
}
