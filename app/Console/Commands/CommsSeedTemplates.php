<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * comms:seed-templates {tenant?} — install the default email template pack for
 * one tenant (or every tenant). Idempotent: existing (key, locale) rows are
 * never overwritten, so operator edits survive re-runs. Safe on deploy.
 */
class CommsSeedTemplates extends Command
{
    protected $signature   = 'comms:seed-templates {tenant? : Tenant id (omit for all tenants)}';
    protected $description = 'Seed the default Communication Center email templates (skip-if-exists)';

    public function handle(): int
    {
        $tenants = $this->argument('tenant')
            ? Tenant::where('id', (int) $this->argument('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $t) {
            $made = 0;
            foreach ($this->pack() as $tpl) {
                $exists = DB::table('comm_templates')
                    ->where('tenant_id', $t->id)->where('channel', 'email')
                    ->where('key', $tpl['key'])->where('locale', 'en')->exists();
                if ($exists) continue;

                DB::table('comm_templates')->insert([
                    'tenant_id'  => $t->id,
                    'channel'    => 'email',
                    'key'        => $tpl['key'],
                    'locale'     => 'en',
                    'name'       => $tpl['name'],
                    'subject'    => $tpl['subject'],
                    'html_body'  => $this->shell($tpl['body']),
                    'text_body'  => null,
                    'variables'  => json_encode($tpl['vars']),
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $made++;
            }
            // M3: short WhatsApp text versions of the same events (channel='whatsapp').
            foreach ($this->waPack() as $tpl) {
                $exists = DB::table('comm_templates')
                    ->where('tenant_id', $t->id)->where('channel', 'whatsapp')
                    ->where('key', $tpl['key'])->where('locale', 'en')->exists();
                if ($exists) continue;

                DB::table('comm_templates')->insert([
                    'tenant_id'  => $t->id,
                    'channel'    => 'whatsapp',
                    'key'        => $tpl['key'],
                    'locale'     => 'en',
                    'name'       => $tpl['name'].' (WhatsApp)',
                    'subject'    => null,
                    'html_body'  => null,
                    'text_body'  => $tpl['text'],
                    'variables'  => json_encode($tpl['vars']),
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $made++;
            }
            $this->info("tenant {$t->id} ({$t->name}): {$made} template(s) installed");
        }

        return self::SUCCESS;
    }

    /** Shared branded shell — clean, table-safe, works in Outlook/Gmail. */
    protected function shell(string $inner): string
    {
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f6f4">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1B211D">'
            .'<div style="background:#1B3A2B;color:#ffffff;border-radius:12px 12px 0 0;padding:18px 22px;font-size:18px;font-weight:bold">{{business_name}}</div>'
            .'<div style="background:#ffffff;border:1px solid #e4e9e4;border-top:0;border-radius:0 0 12px 12px;padding:22px;font-size:14px;line-height:1.6">'
            .$inner
            .'</div>'
            .'<div style="text-align:center;font-size:11px;color:#9ca3af;padding:14px">{{business_name}} &middot; {{today}}</div>'
            .'</div></body></html>';
    }

    /** WhatsApp text pack — concise, emoji-light, {{var}} rendered plain. */
    protected function waPack(): array
    {
        return [
            ['key' => 'welcome', 'name' => 'Welcome', 'vars' => ['customer_name'],
             'text' => "Hi {{customer_name}} 👋 Welcome to {{business_name}}! Your account is ready. Reply here any time — we're happy to help."],
            ['key' => 'quotation', 'name' => 'Quotation', 'vars' => ['customer_name', 'quotation_number', 'total', 'valid_until', 'portal_url'],
             'text' => "📄 Quotation *{{quotation_number}}* from {{business_name}}\nTotal: {{total}}\nValid until: {{valid_until}}\nView & approve: {{portal_url}}"],
            ['key' => 'sales_order', 'name' => 'Sales Order Confirmation', 'vars' => ['customer_name', 'order_number', 'total'],
             'text' => "✅ {{customer_name}}, your order *{{order_number}}* is confirmed. Total: {{total}}. Thank you for shopping with {{business_name}}!"],
            ['key' => 'invoice', 'name' => 'Invoice', 'vars' => ['customer_name', 'invoice_number', 'amount', 'due_date'],
             'text' => "🧾 Invoice *{{invoice_number}}* — {{amount}}\nDue: {{due_date}}\nFrom {{business_name}}. Reply here for any question."],
            ['key' => 'receipt', 'name' => 'Payment Receipt', 'vars' => ['customer_name', 'amount', 'method', 'reference', 'balance'],
             'text' => "🙏 Payment received: *{{amount}}* ({{method}}, ref {{reference}}).\nOutstanding balance: {{balance}}. — {{business_name}}"],
            ['key' => 'payment_reminder', 'name' => 'Payment Reminder', 'vars' => ['customer_name', 'invoice_number', 'amount', 'due_date'],
             'text' => "Hi {{customer_name}}, friendly reminder: invoice *{{invoice_number}}* for *{{amount}}* was due {{due_date}}. Kindly arrange payment — and ignore this if already paid. 🙏 {{business_name}}"],
            ['key' => 'delivery_update', 'name' => 'Delivery Update', 'vars' => ['customer_name', 'order_number', 'status', 'eta', 'track_url'],
             'text' => "🚚 Order *{{order_number}}*: {{status}}\nExpected: {{eta}}\nTrack: {{track_url}}"],
            ['key' => 'shipment', 'name' => 'Shipment Notice', 'vars' => ['customer_name', 'shipment_number', 'carrier', 'eta', 'track_url'],
             'text' => "📦 Shipment *{{shipment_number}}* is on the way via {{carrier}}. ETA {{eta}}. Track: {{track_url}}"],
            ['key' => 'seller_settlement', 'name' => 'Seller Settlement', 'vars' => ['seller_name', 'period', 'amount', 'orders_count'],
             'text' => "💰 {{seller_name}}, your settlement for *{{period}}* is complete: *{{amount}}* across {{orders_count}} orders. — {{business_name}}"],
            ['key' => 'password_reset', 'name' => 'Password Reset', 'vars' => ['customer_name', 'reset_url'],
             'text' => "🔐 Reset your {{business_name}} password (expires in 60 min): {{reset_url}}\nIf you didn't request this, ignore this message."],
            ['key' => 'otp', 'name' => 'One-Time Code', 'vars' => ['otp_code'],
             'text' => "🔑 Your {{business_name}} verification code: *{{otp_code}}*\nExpires in 10 minutes. Never share this code."],
            ['key' => 'support_ticket', 'name' => 'Support Ticket', 'vars' => ['customer_name', 'ticket_number', 'ticket_subject', 'status'],
             'text' => "🎫 Ticket *{{ticket_number}}* ({{ticket_subject}}) — status: {{status}}. We'll keep you posted. — {{business_name}}"],
        ];
    }

    protected function pack(): array
    {
        return [
            ['key' => 'welcome', 'name' => 'Welcome', 'subject' => 'Welcome to {{business_name}}',
             'vars' => ['customer_name'],
             'body' => '<p>Dear {{customer_name}},</p><p>Welcome to {{business_name}}! Your account is ready. We are glad to have you with us.</p><p>Warm regards,<br>{{business_name}}</p>'],

            ['key' => 'quotation', 'name' => 'Quotation', 'subject' => 'Quotation {{quotation_number}} from {{business_name}}',
             'vars' => ['customer_name', 'quotation_number', 'total', 'valid_until', 'portal_url'],
             'body' => '<p>Dear {{customer_name}},</p><p>Please find quotation <b>{{quotation_number}}</b> attached.</p><p>Total: <b>{{total}}</b><br>Valid until: {{valid_until}}</p><p><a href="{{portal_url}}" style="background:#15803D;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">View quotation online</a></p>'],

            ['key' => 'sales_order', 'name' => 'Sales Order Confirmation', 'subject' => 'Order {{order_number}} confirmed — {{business_name}}',
             'vars' => ['customer_name', 'order_number', 'total', 'lines_html'],
             'body' => '<p>Dear {{customer_name}},</p><p>Thank you — your order <b>{{order_number}}</b> is confirmed.</p>{{lines_html}}<p>Total: <b>{{total}}</b></p>'],

            ['key' => 'invoice', 'name' => 'Invoice', 'subject' => 'Invoice {{invoice_number}} — {{business_name}}',
             'vars' => ['customer_name', 'invoice_number', 'amount', 'due_date'],
             'body' => '<p>Dear {{customer_name}},</p><p>Invoice <b>{{invoice_number}}</b> for <b>{{amount}}</b> is attached.</p><p>Due date: <b>{{due_date}}</b></p><p>Thank you for your business.</p>'],

            ['key' => 'receipt', 'name' => 'Payment Receipt', 'subject' => 'Receipt — payment of {{amount}} received',
             'vars' => ['customer_name', 'amount', 'method', 'reference', 'balance'],
             'body' => '<p>Dear {{customer_name}},</p><p>We received your payment of <b>{{amount}}</b> ({{method}}, ref {{reference}}).</p><p>Outstanding balance: <b>{{balance}}</b></p><p>Thank you!</p>'],

            ['key' => 'payment_reminder', 'name' => 'Payment Reminder', 'subject' => 'Reminder: {{invoice_number}} — {{amount}} due',
             'vars' => ['customer_name', 'invoice_number', 'amount', 'due_date', 'days_overdue'],
             'body' => '<p>Dear {{customer_name}},</p><p>A friendly reminder that invoice <b>{{invoice_number}}</b> for <b>{{amount}}</b> was due on {{due_date}} ({{days_overdue}} days ago).</p><p>Kindly arrange payment at your earliest convenience. If already paid, please ignore this message.</p>'],

            ['key' => 'delivery_update', 'name' => 'Delivery Update', 'subject' => 'Your order {{order_number}}: {{status}}',
             'vars' => ['customer_name', 'order_number', 'status', 'eta', 'track_url'],
             'body' => '<p>Dear {{customer_name}},</p><p>Order <b>{{order_number}}</b> update: <b>{{status}}</b>.</p><p>Expected: {{eta}}</p><p><a href="{{track_url}}">Track your delivery</a></p>'],

            ['key' => 'shipment', 'name' => 'Shipment Notice', 'subject' => 'Shipment {{shipment_number}} — {{business_name}}',
             'vars' => ['customer_name', 'shipment_number', 'carrier', 'eta', 'track_url'],
             'body' => '<p>Dear {{customer_name}},</p><p>Shipment <b>{{shipment_number}}</b> is on its way via {{carrier}}.</p><p>ETA: {{eta}} &middot; <a href="{{track_url}}">Track shipment</a></p>'],

            ['key' => 'seller_settlement', 'name' => 'Seller Settlement', 'subject' => 'Settlement {{period}} — {{amount}}',
             'vars' => ['seller_name', 'period', 'amount', 'orders_count', 'statement_html'],
             'body' => '<p>Dear {{seller_name}},</p><p>Your settlement for <b>{{period}}</b> is complete: <b>{{amount}}</b> across {{orders_count}} orders.</p>{{statement_html}}'],

            ['key' => 'password_reset', 'name' => 'Password Reset', 'subject' => 'Reset your {{business_name}} password',
             'vars' => ['customer_name', 'reset_url'],
             'body' => '<p>Hello {{customer_name}},</p><p>Click below to reset your password. The link expires in 60 minutes.</p><p><a href="{{reset_url}}" style="background:#15803D;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Reset password</a></p><p>If you did not request this, you can safely ignore this email.</p>'],

            ['key' => 'otp', 'name' => 'One-Time Code', 'subject' => 'Your {{business_name}} verification code',
             'vars' => ['otp_code'],
             'body' => '<p>Your verification code:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">{{otp_code}}</p><p>It expires in 10 minutes. Never share this code with anyone.</p>'],

            ['key' => 'support_ticket', 'name' => 'Support Ticket', 'subject' => 'Ticket {{ticket_number}}: {{ticket_subject}}',
             'vars' => ['customer_name', 'ticket_number', 'ticket_subject', 'status', 'message_html'],
             'body' => '<p>Dear {{customer_name}},</p><p>Ticket <b>{{ticket_number}}</b> ({{ticket_subject}}) — status: <b>{{status}}</b>.</p>{{message_html}}'],
        ];
    }
}
