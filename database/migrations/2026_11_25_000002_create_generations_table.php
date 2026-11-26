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
        Schema::create('generations', function (Blueprint $table) {
            // The identifier is ours and is minted before the provider is
            // contacted, so a generation exists locally even if the submission
            // never succeeds. It is also sent to the provider as metadata and
            // comes back on every webhook, which is what lets a delivery be
            // matched to this row before the provider's own id is known.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent with the submission. A request that times out has an unknown
            // outcome rather than a failed one, and this is what makes retrying
            // it safe instead of a second generation the user pays for.
            $table->uuid('idempotency_key')->unique();

            // Null until the provider accepts the submission.
            $table->string('provider_generation_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_image_urls')->default('[]');

            $table->string('status');

            // The status ordering, denormalised so the transition guard can be
            // a condition inside the update rather than a read, a decision and
            // then a write. Kept in step with status by a saving hook on the
            // Generation model, which takes the rank from the enum, so no path
            // can write one value without the other.
            $table->smallInteger('status_rank');

            // The rates the provider fixed for this generation when it created
            // it, as returned on the create response. Published rates can
            // change; these cannot, so a charge stays reproducible and a
            // historical cost can still be explained months later.
            $table->jsonb('rates')->nullable();

            // Money is held in nanodollars throughout. The provider reports
            // costs to nine decimal places and never rounds them, and some
            // per-token rates are fractions of a nanodollar, so a coarser
            // unit would lose real money on every generation. See App\Domain\Money.
            //
            // Three figures are kept rather than one. The quote is the ceiling
            // the user agreed to; the provider cost is what the generation
            // actually cost us and is what reconciles against GET /v1/usage;
            // the charge is what the user paid. Collapsing any two of them
            // removes the ability to explain a difference.
            $table->bigInteger('quoted_nanos');
            $table->jsonb('quoted_usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            // The four token counts the provider reports on completion. Null
            // until then, and never set for a failed generation, which is never
            // billed.
            $table->jsonb('usage')->nullable();

            // The provider's image URL is signed and stops working 24 hours
            // after the generation completes, so the expiry is recorded rather
            // than inferred: it decides whether the stored URL can still be
            // shown or has to be refetched.
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();

            // Kept apart because they serve different readers. The code is what
            // the application branches on and groups by; the message is what a
            // user is shown.
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            // Serves the history view. Postgres can traverse a b-tree in either
            // direction, so this also satisfies ORDER BY created_at DESC and no
            // separate descending index is needed.
            $table->index(['user_id', 'created_at']);
        });

        // Only generations still awaiting an outcome are ever polled, so the
        // reconciler's index excludes the rest. Rows leave it when they reach a
        // terminal status, which keeps it the size of the in-flight set however
        // large the table grows.
        DB::statement(<<<'SQL'
            CREATE INDEX generations_in_flight_index
                ON generations (submitted_at)
                WHERE status IN ('creating', 'queued', 'processing')
        SQL);

        // The status vocabulary belongs to the schema, not only to the enum, so
        // a value outside it cannot be written by any path. 'creating' is this
        // application's own: it covers a row that exists before the provider
        // has accepted it, a state the provider has no name for.
        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_status_check
                CHECK (status IN ('creating', 'queued', 'processing', 'succeeded', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_status_rank_check
                CHECK (status_rank BETWEEN 0 AND 3)
        SQL);

        // Money moves one way here: a generation cannot cost a negative amount.
        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_amounts_non_negative_check
                CHECK (
                    quoted_nanos >= 0
                    AND (provider_cost_nanos IS NULL OR provider_cost_nanos >= 0)
                    AND (charged_nanos IS NULL OR charged_nanos >= 0)
                )
        SQL);

        // A generation that has succeeded has been priced; one that has not,
        // has not. The provider bills only successful generations, so a failed
        // one carrying a cost would be a charge with nothing behind it. This
        // stops a half-applied outcome being recorded at all.
        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_costs_match_outcome_check
                CHECK (
                    (status = 'succeeded' AND provider_cost_nanos IS NOT NULL AND charged_nanos IS NOT NULL)
                    OR (status <> 'succeeded' AND provider_cost_nanos IS NULL AND charged_nanos IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
