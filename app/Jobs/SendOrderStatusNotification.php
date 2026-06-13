<?php
namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public int $orderId, public string $status) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $order = Order::find($this->orderId);
        $tenant = Tenant::find($this->tenantId);
        if (!$order || !$tenant || !$order->customer_phone) return;

        $first = trim(explode(' ', (string) $order->customer_name)[0] ?: 'there');
        $name  = $tenant->name;

        $messages = [
            'Confirmed'        => "Hi {$first}! \u{1F389} Your {$name} order *{$order->order_no}* is confirmed. We're preparing it now.",
            'Packed'           => "\u{1F4E6} {$first}, your order *{$order->order_no}* is packed and ready to go!",
            'Out for delivery' => "\u{1F6F5} Your {$name} order is on the way! Our rider will reach you shortly.",
            'Delivered'        => "\u{2705} Your order *{$order->order_no}* has been delivered. Thank you for shopping with {$name}! \u{1F6D2}",
            'Cancelled'        => "Your {$name} order *{$order->order_no}* has been cancelled. Reply here if you need help.",
        ];
        $text = $messages[$this->status] ?? null;
        if (!$text) return;

        $wa->forTenant($tenant)
           ->sendText($tenant->whatsapp_instance, $order->customer_phone, $text);

        MessageLog::record(
            $this->tenantId, $order->customer_phone, $tenant->whatsapp_instance,
            'out', 'system', $text
        );
    }
}
