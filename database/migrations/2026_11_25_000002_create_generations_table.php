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
            // never succeeds.
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

            // Both figures are kept. The estimate is what the user agreed to;
            // the charge is what they paid. Overwriting one with the other
            // would remove any way to explain a difference between them.
            $table->bigInteger('estimated_micros');
            $table->jsonb('estimated_usage')->nullable();
            $table->bigInteger('provider_cost_micros')->nullable();
            $table->bigInteger('charged_micros')->nullable();
            $table->jsonb('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->text('failure_reason')->nullable();

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
        // a value outside it cannot be written by any path.
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
                    estimated_micros >= 0
                    AND (provider_cost_micros IS NULL OR provider_cost_micros >= 0)
                    AND (charged_micros IS NULL OR charged_micros >= 0)
                )
        SQL);

        // A generation that has succeeded has been priced; one that has not,
        // has not. This prevents a half-applied outcome being recorded.
        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_costs_match_outcome_check
                CHECK (
                    (status = 'succeeded' AND provider_cost_micros IS NOT NULL AND charged_micros IS NOT NULL)
                    OR (status <> 'succeeded' AND provider_cost_micros IS NULL AND charged_micros IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
