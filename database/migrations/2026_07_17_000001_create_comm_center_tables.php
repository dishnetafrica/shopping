<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Center — Milestone 1 (Email transport).
 *
 * Five tables, all tenant-scoped (Constitution: tenancy.column = tenant_id):
 *
 *   comm_providers    — per-tenant transport accounts (SMTP / Mailgun / SendGrid /
 *                       Postmark / Brevo). Ordered by priority for failover.
 *   comm_templates    — per-tenant, per-locale message templates ({{var}} syntax).
 *   comm_messages     — the queue AND the audit log in one table (UCRM-style
 *                       email history). Every send lives here forever.
 *   comm_events       — provider callbacks + pixel events (delivered / opened /
 *                       clicked / bounced / complained / unsubscribed).
 *   comm_suppressions — do-not-contact list (hard bounce, complaint, unsubscribe,
 *                       manual). Checked before every enqueue.
 *
 * The `channel` column exists on every table so WhatsApp / SMS / Push reuse the
 * exact same spine later (Notification Engine) without another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comm_providers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('channel', 20)->default('email');
            $t->string('name');                        // "Primary M365", "SES backup"
            $t->string('driver', 20);                  // smtp|mailgun|sendgrid|postmark|brevo
            $t->text('config');                        // encrypted JSON (creds) — cast handles crypto
            $t->unsignedInteger('priority')->default(10); // lower = tried first
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('daily_limit')->nullable(); // null = unlimited
            $t->unsignedInteger('sent_today')->default(0);
            $t->date('sent_date')->nullable();         // window the counter belongs to
            $t->text('last_error')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'channel', 'is_active', 'priority'], 'comm_providers_pick_idx');
        });

        Schema::create('comm_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('channel', 20)->default('email');
            $t->string('key', 60);                     // welcome|invoice|receipt|otp|…
            $t->string('locale', 10)->default('en');
            $t->string('name');
            $t->string('subject')->nullable();
            $t->longText('html_body')->nullable();
            $t->longText('text_body')->nullable();
            $t->json('variables')->nullable();         // documented placeholders for the editor UI
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'channel', 'key', 'locale'], 'comm_templates_key_uq');
        });

        Schema::create('comm_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('channel', 20)->default('email');
            $t->string('token', 32)->unique();         // public handle: pixel / unsubscribe / webhooks
            $t->string('to_email');
            $t->string('to_name')->nullable();
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->string('reply_to')->nullable();
            $t->string('subject')->nullable();
            $t->longText('html_body')->nullable();
            $t->longText('text_body')->nullable();
            $t->string('template_key', 60)->nullable();
            $t->json('vars')->nullable();
            $t->json('attachments')->nullable();       // [{name,mime,data(b64)}] or [{name,path}] — keep small
            $t->string('status', 15)->default('pending'); // pending|sending|sent|failed|retry|cancelled
            $t->unsignedBigInteger('provider_id')->nullable();
            $t->string('provider_message_id')->nullable()->index();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedTinyInteger('max_attempts')->default(5);
            $t->timestamp('scheduled_at')->nullable(); // null = send asap
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->unsignedInteger('opens')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->timestamp('bounced_at')->nullable();
            $t->timestamp('complained_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->string('related_type', 40)->nullable(); // invoice|quotation|order|… (loose link, no FK)
            $t->unsignedBigInteger('related_id')->nullable();
            $t->json('meta')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'created_at'], 'comm_messages_list_idx');
            $t->index(['status', 'next_attempt_at'], 'comm_messages_sweep_idx');
        });

        Schema::create('comm_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('message_id')->nullable()->index();
            $t->string('event', 20);                   // delivered|opened|clicked|bounced|complained|unsubscribed|webhook
            $t->string('provider', 20)->nullable();
            $t->json('payload')->nullable();           // raw provider payload — the audit trail
            $t->timestamp('occurred_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('comm_suppressions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('channel', 20)->default('email');
            $t->string('address');                     // email (later: phone for sms/wa)
            $t->string('reason', 20);                  // bounced|complained|unsubscribed|manual
            $t->string('source')->nullable();          // provider / user id / webhook
            $t->timestamp('created_at')->nullable();
            $t->unique(['tenant_id', 'channel', 'address'], 'comm_suppressions_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comm_suppressions');
        Schema::dropIfExists('comm_events');
        Schema::dropIfExists('comm_messages');
        Schema::dropIfExists('comm_templates');
        Schema::dropIfExists('comm_providers');
    }
};
