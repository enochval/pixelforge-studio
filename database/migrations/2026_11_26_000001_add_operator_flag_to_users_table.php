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
            // Which application a signed-in person sees, not merely whether one
            // page is visible to them. An operator reconciles spend and never
            // generates images; a customer generates images and never sees
            // another account's figures.
            //
            // On the user record rather than in configuration because it is a
            // property of the account: it decides where signing in takes you,
            // and a list of addresses in an environment variable cannot be
            // joined against, audited, or changed without a deployment.
            $table->boolean('is_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_operator');
        });
    }
};
