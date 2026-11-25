<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A cached sum of the user's ledger entries, maintained in the same
            // transaction as the entry that changes it. The ledger remains the
            // source of truth; this exists so a balance can be read without
            // aggregating every entry.
            //
            // No check constraint forbids a negative value. A generation's
            // actual cost can exceed the estimate that was held for it, and
            // when it does the charge is recorded honestly rather than refused.
            $table->bigInteger('balance_micros')->default(0)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_micros');
        });
    }
};
