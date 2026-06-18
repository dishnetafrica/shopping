<?php
/** IntentRouter classification (lead | ticket | shopping). Pure logic. */

namespace App\Models {
    // Minimal stub so IntentRouter's Tenant type-hint resolves without the framework.
    class Tenant {
        public array $s = [];
        public function setting($k, $d = null) { return $this->s[$k] ?? $d; }
    }
}

namespace {
    require __DIR__ . '/../app/Services/Bot/IntentRouter.php';

    use App\Services\Bot\IntentRouter;
    use App\Models\Tenant;

    $r = new IntentRouter();
    $t = new Tenant();
    $pass = 0; $fail = 0;
    function is_(IntentRouter $r, Tenant $t, string $msg, string $want): void {
        global $pass, $fail;
        $got = $r->classify($t, $msg);
        if ($got === $want) { $pass++; }
        else { $fail++; echo "  FAIL: \"$msg\" → got $got, want $want\n"; }
    }

    // Leads
    is_($r, $t, 'Call me',                'lead');
    is_($r, $t, 'please call me back',    'lead');
    is_($r, $t, 'I need a quotation',     'lead');
    is_($r, $t, 'send me your price list','lead');
    is_($r, $t, 'I am interested',        'lead');
    is_($r, $t, 'Need Starlink at my home','lead');
    is_($r, $t, 'do you offer internet',  'shopping'); // no lead keyword → deterministic miss, falls to shopping (AI fallback is a later build)
    is_($r, $t, 'need internet',          'lead');
    is_($r, $t, 'can you visit my office','lead');
    is_($r, $t, 'I want a demo',          'lead');
    is_($r, $t, 'become a dealer',        'lead');

    // Tickets (and ticket outranks lead wording)
    is_($r, $t, 'my internet is down',    'ticket');
    is_($r, $t, 'no connection since morning','ticket');
    is_($r, $t, 'speed is slow',          'ticket');
    is_($r, $t, 'router not working',     'ticket');
    is_($r, $t, 'service not working, please call me', 'ticket'); // ticket beats lead

    // Shopping (must NOT be hijacked)
    is_($r, $t, 'need rice',              'shopping');
    is_($r, $t, 'need oil',               'shopping');
    is_($r, $t, 'add 2 milk',             'shopping');
    is_($r, $t, 'how much is sugar',      'shopping');
    is_($r, $t, 'checkout',               'shopping');
    is_($r, $t, 'hi',                     'shopping');
    is_($r, $t, 'menu',                   'shopping');
    is_($r, $t, '2',                      'shopping');

    // Tenant-tunable extra keywords
    $t->s['lead_keywords'] = 'wholesale, bulk order';
    is_($r, $t, 'I want a bulk order',    'lead');
    $t->s['ticket_keywords'] = 'card declined';
    is_($r, $t, 'my card declined',       'ticket');

    echo "lead_router: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
}
