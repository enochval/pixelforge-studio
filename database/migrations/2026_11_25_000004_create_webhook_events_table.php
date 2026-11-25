<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The deduplication key. A repeated delivery fails this constraint,
            // and that failure is the mechanism rather than an error to report.
            $table->string('provider_event_id')->unique();

            $table->string('event_type');

            // Deliberately not a foreign key to generations.
            //
            // The provider can dispatch its first event before the response to
            // the submission has been written, so an event can legitimately
            // arrive for a generation this table cannot yet identify. A foreign
            // key would reject exactly that event, and the endpoint would have
            // to answer with an error — which the provider would treat as a
            // reason to redeliver indefinitely.
            //
            // The association is resolved in application code, and an event
            // that cannot be matched is stored as orphaned and retried by the
            // reconciler once the identifier is known.
            $table->string('provider_generation_id')->nullable()->index();

            // The raw delivery, retained. When a figure is questioned later,
            // what the provider actually said about that generation is on file.
            $table->jsonb('payload');

            // What processing decided. Null until the event has been processed.
            $table->string('outcome')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
        });

        // Events still awaiting processing, oldest first. Partial, because a
        // processed event is never looked up this way: rows leave the index
        // once they are handled, so it stays the size of the backlog rather
        // than growing with the table.
        DB::statement(<<<'SQL'
            CREATE INDEX webhook_events_unprocessed_index
                ON webhook_events (received_at)
                WHERE processed_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE webhook_events
                ADD CONSTRAINT webhook_events_outcome_check
                CHECK (outcome IS NULL OR outcome IN ('applied', 'ignored_stale', 'orphaned'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
