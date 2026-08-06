<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\OrderConfirmation;
use App\Models\Tenant;
use App\Services\Sales\OrderConfirmationService;
use Illuminate\Http\Request;

/**
 * v4.43 — the customer's one-tap "yes, that is my order" page.
 *
 * Public and unauthenticated: the 32-character token IS the key, exactly like /task/{nonce},
 * /receive/{nonce} and the quotation portal. It is deliberately NOT the order's track_token —
 * rotating or revoking a confirmation link must never break order tracking.
 *
 * The page shows the FROZEN snapshot, never the live order. If a colleague edits the order while
 * the customer is looking at this page, the customer still sees, and still agrees to, exactly what
 * was sent to them — and the staff screen will then report the order as changed-after-confirmation.
 * Showing the live order here would quietly launder an edit into an agreement.
 */
class OrderConfirmController extends Controller
{
    public function show(Request $request, string $token)
    {
        $row = $this->row($token);
        if (! $row) {
            return response($this->plain('Link not found', 'This confirmation link is not valid. Please contact us and we will send a new one.'), 404)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }
        $tenant = Tenant::find($row->tenant_id);
        $snap = (array) ($row->snapshot ?? []);

        $path = resource_path('storefront/order_confirm.html');
        if (! is_file($path)) {
            return response($this->plain('Order ' . ($snap['order_no'] ?? ''), $row->message_text ?: ''), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $cfg = [
            'token'      => $token,
            'business'   => (string) ($tenant->name ?? ''),
            'snapshot'   => $snap,
            'confirmed'  => $row->confirmed_at !== null,
            'confirmedAt' => $row->confirmed_at?->format('d M Y, H:i'),
            'superseded' => $row->superseded_at !== null,
            'contact'    => (string) ($snap['contact'] ?? ''),
        ];
        $html = str_replace('/*__CONFIRM_CFG__*/', 'window.CONFIRM_CFG=' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';',
            file_get_contents($path));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** POST — record the acceptance. Idempotent: a second tap is not an error, just a no-op. */
    public function accept(Request $request, string $token)
    {
        $row = $this->row($token);
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($row->superseded_at !== null) {
            return response()->json(['ok' => false, 'error' => 'superseded',
                'message' => 'This order has been updated since we sent it. Please check the newest message we sent you.'], 409);
        }
        if ($row->confirmed_at !== null) {
            return response()->json(['ok' => true, 'already' => true, 'at' => $row->confirmed_at->format('d M Y, H:i')]);
        }

        app(OrderConfirmationService::class)->accept($row, 'link', (string) $request->ip());
        $row->refresh();

        return response()->json(['ok' => true, 'already' => false, 'at' => $row->confirmed_at?->format('d M Y, H:i')]);
    }

    private function row(string $token): ?OrderConfirmation
    {
        $token = trim($token);
        if ($token === '' || ! preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
            return null;
        }

        return OrderConfirmation::where('token', $token)->first();
    }

    private function plain(string $title, string $body): string
    {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . '</title>'
            . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 18px;color:#12241a">'
            . '<h1 style="font-size:20px">' . e($title) . '</h1>'
            . '<pre style="white-space:pre-wrap;font:inherit">' . e($body) . '</pre></div>';
    }
}
