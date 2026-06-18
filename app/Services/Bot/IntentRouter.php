<?php
namespace App\Services\Bot;

use App\Models\Tenant;

/**
 * Routes an incoming message to one of: lead | ticket | shopping.
 *
 * Deterministic by design — fast, free, and predictable. Shopping is the default,
 * so the existing BotBrain keeps handling normal orders, greetings and price
 * questions; only clear sales-interest or service-problem phrasing is diverted.
 *
 * Keyword lists are tenant-tunable via settings.lead_keywords / settings.ticket_keywords
 * (comma or newline separated), letting an ISP tenant and a grocery tenant differ.
 */
class IntentRouter
{
    /** Sales-interest phrasing → a human should follow up to sell. */
    private const LEAD = [
        'call me', 'callme', 'please call', 'can you call', 'ring me',
        'quotation', 'quote', 'need a quote', 'price list', 'pricelist',
        'interested', 'i am interested', 'am interested', 'want to buy in bulk',
        'need internet', 'need wifi', 'need wi-fi', 'need starlink', 'need a connection',
        'need installation', 'installation', 'new connection', 'get connected',
        'can you visit', 'site visit', 'come and see', 'need a demo', 'demo',
        'become a dealer', 'become an agent', 'partnership', 'reseller',
        'sales', 'salesperson', 'speak to sales', 'talk to someone',
    ];

    /** Service-problem phrasing → an existing customer needs support. */
    private const TICKET = [
        'internet down', 'net is down', 'no internet', 'no connection', 'not connected',
        'no network', 'no service', 'not working', 'stopped working', 'down again',
        'slow speed', 'very slow', 'speed is slow', 'buffering',
        'router issue', 'router problem', 'router not', 'device not working',
        'no signal', 'offline', 'disconnected', 'complaint', 'not getting service',
    ];

    public function classify(Tenant $tenant, string $text): string
    {
        $lc = ' ' . $this->norm($text) . ' ';
        if ($lc === '   ') return 'shopping';

        $ticket = $this->merge(self::TICKET, $tenant->setting('ticket_keywords', ''));
        $lead   = $this->merge(self::LEAD,   $tenant->setting('lead_keywords', ''));

        // Tickets first: a service complaint outranks a vague sales phrase.
        if ($this->hits($lc, $ticket)) return 'ticket';
        if ($this->hits($lc, $lead))   return 'lead';

        return 'shopping';
    }

    private function hits(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $p) {
            $p = trim($p);
            if ($p !== '' && str_contains($haystack, $p)) return true;
        }
        return false;
    }

    private function merge(array $base, $extra): array
    {
        $add = [];
        if (is_string($extra) && trim($extra) !== '') {
            $add = preg_split('/[,\n]+/', strtolower($extra), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $add = array_map('trim', $add);
        } elseif (is_array($extra)) {
            $add = array_map(fn ($s) => trim(strtolower((string) $s)), $extra);
        }
        return array_values(array_filter(array_merge($base, $add)));
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s);
    }
}
