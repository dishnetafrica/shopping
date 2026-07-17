<?php

namespace App\Http\Controllers\Comms;

use App\Http\Controllers\Controller;
use App\Models\CommEvent;
use App\Models\CommMessage;
use App\Models\CommSuppression;

/**
 * Public engagement endpoints — the message token is the key (same no-login,
 * token-is-auth pattern as /t/{token} shipment custody pages).
 *
 *   GET /c/e/o/{token}.gif  — 1×1 open pixel
 *   GET /c/e/u/{token}      — one-click unsubscribe (writes suppression)
 */
class EmailTrackController extends Controller
{
    public function open(string $token)
    {
        $m = CommMessage::withoutGlobalScope('tenant')->where('token', $token)->first();
        if ($m) {
            $m->opens = $m->opens + 1;
            if (! $m->opened_at) $m->opened_at = now();
            $m->save();
            CommEvent::create([
                'tenant_id' => $m->tenant_id, 'message_id' => $m->id,
                'event' => 'opened', 'provider' => 'pixel', 'occurred_at' => now(),
            ]);
        }
        // Serve the gif regardless — never 404 an email client.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return response($gif, 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function unsubscribe(string $token)
    {
        $m = CommMessage::withoutGlobalScope('tenant')->where('token', $token)->first();
        $name = 'this sender';
        if ($m) {
            CommSuppression::add($m->tenant_id, $m->to_email, 'unsubscribed', 'unsubscribe-link');
            if (! $m->unsubscribed_at) $m->unsubscribed_at = now();
            $m->save();
            CommEvent::create([
                'tenant_id' => $m->tenant_id, 'message_id' => $m->id,
                'event' => 'unsubscribed', 'provider' => 'link', 'occurred_at' => now(),
            ]);
            $t = $m->tenant()->first();
            if ($t) $name = e($t->name);
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
              .'<title>Unsubscribed</title></head>'
              .'<body style="font-family:system-ui,Arial,sans-serif;background:#f6f8f6;margin:0;padding:40px 16px;text-align:center">'
              .'<div style="max-width:440px;margin:0 auto;background:#fff;border:1px solid #e4e9e4;border-radius:14px;padding:28px">'
              .'<div style="font-size:34px">✅</div><h2 style="margin:10px 0 6px">You are unsubscribed</h2>'
              .'<p style="color:#6b7280;font-size:14px">You will no longer receive marketing emails from '.$name.'. '
              .'Transactional messages about your orders and invoices may still be sent.</p>'
              .'</div></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
