<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.63 — visits, and what was asked for on them. ADR-016 decision 5.
 *
 * The single biggest gap in the system as it stood: nothing recorded that a rep went somewhere and
 * what happened. Everything downstream of that gap is blocked by it — a territory map with nothing
 * to colour, a call list that cannot tell "never visited" from "visited and refused", and any future
 * scoring at all, which needs NEGATIVES and has none because nobody has been writing them down.
 *
 * Two details in here are the whole point of the table:
 *
 *   `outcome` is a CLOSED vocabulary. Free text would be easier to write and would make this table
 *   worth nothing in a year, because a training label of "he said maybe next month?" cannot be
 *   counted. Enforced in App\Services\Field\VisitOutcome, not by a database constraint, so a
 *   vocabulary change is a code change with a test rather than a migration on a live table.
 *
 *   `visit_items.product_id` is NULLABLE ON PURPOSE. A product the rep was asked for that the shop
 *   does not stock is the most valuable row in this table — it is unmet demand arriving from the
 *   field instead of from the bot — and a foreign key would throw exactly those rows away.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visits')) {
            Schema::create('visits', function (Blueprint $t) {
                $jsonb = DB::connection()->getDriverName() === 'pgsql';

                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('customer_profile_id')->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();   // who went
                $t->string('user_name', 120)->nullable();                 // frozen: the user row may go

                $t->timestamp('checked_in_at')->nullable();
                $t->timestamp('checked_out_at')->nullable();
                // Where the rep actually was, from the phone. The one honest source of a business's
                // location — an address typed into a spreadsheet is a guess about a place.
                $t->decimal('latitude', 10, 7)->nullable();
                $t->decimal('longitude', 10, 7)->nullable();

                $t->string('outcome', 24)->index();      // the closed vocabulary — see VisitOutcome
                $t->text('notes')->nullable();
                $jsonb ? $t->jsonb('photos')->nullable() : $t->json('photos')->nullable();

                $t->string('next_action', 200)->nullable();
                $t->timestamp('next_action_at')->nullable();
                $t->boolean('next_action_done')->default(false);

                $t->timestamps();

                // "What has this rep been doing", and "what is overdue" — the two questions asked.
                $t->index(['tenant_id', 'user_id', 'checked_in_at']);
                $t->index(['tenant_id', 'next_action_done', 'next_action_at']);
            });
        }

        if (! Schema::hasTable('visit_items')) {
            Schema::create('visit_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('visit_id')->index();
                // Nullable, and NOT a foreign key. See the note above: the rows with nothing here are
                // the ones worth having.
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('name', 200);                 // what the rep wrote down, always
                $t->decimal('qty', 14, 3)->nullable();
                $t->boolean('stocked')->default(false);  // did we have it
                $t->string('note', 300)->nullable();
                $t->timestamps();

                // The unmet-demand report: this tenant's unstocked asks, most recent first.
                $t->index(['tenant_id', 'stocked', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_items');
        Schema::dropIfExists('visits');
    }
};
