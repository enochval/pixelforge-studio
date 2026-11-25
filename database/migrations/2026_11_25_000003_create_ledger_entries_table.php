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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null for a credit purchase, which belongs to a user but to no
            // particular generation.
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind');

            // Signed: negative amounts leave the user. Holding the sign on the
            // amount rather than inferring it from the kind means a balance is
            // a plain SUM with nothing to get wrong at the point of reading.
            $table->bigInteger('amount_micros');

            // Append-only: there is no updated_at because no row is ever
            // modified. A correction is a new compensating entry.
            $table->timestampTz('created_at');

            // Balance derivation and the per-user daily grouping in the
            // operator report.
            $table->index(['user_id', 'created_at']);
        });

        // The second guarantee against double-charging, independent of the
        // webhook inbox. Even if a duplicate event reached the charging code —
        // through a bug, a manual redelivery, or the reconciler racing a
        // webhook — the database refuses the second entry.
        //
        // Partial, because credit purchases carry no generation and several of
        // them for one user must remain possible.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_entries_one_per_generation_kind_index
                ON ledger_entries (generation_id, kind)
                WHERE generation_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT ledger_entries_kind_check
                CHECK (kind IN ('topup', 'hold', 'hold_release', 'charge'))
        SQL);

        // Each kind moves money in one direction only. A charge that credited a
        // user, or a top-up that debited one, would be a sign error rather than
        // a legitimate entry.
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT ledger_entries_direction_check
                CHECK (
                    (kind IN ('topup', 'hold_release') AND amount_micros >= 0)
                    OR (kind IN ('hold', 'charge') AND amount_micros <= 0)
                )
        SQL);

        // Every entry other than a top-up settles a generation.
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT ledger_entries_generation_required_check
                CHECK (kind = 'topup' OR generation_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
