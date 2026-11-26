<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Money;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an account that can be signed into immediately, with credit.
 *
 * Registering through the interface works, but a new account has a zero
 * balance — so the first thing anyone evaluating this application would see is
 * a screen that cannot demonstrate charging. This gives them one that can.
 *
 * Refuses to run outside local development. The credentials are deliberately
 * guessable, which is the whole point locally and unacceptable anywhere else.
 */
final class CreateDemoUserCommand extends Command
{
    protected $signature = 'pixelforge:demo-user
                            {--email=demo@pixelforge.test}
                            {--password=password}
                            {--credit=5.00 : Starting balance in US dollars}';

    protected $description = 'Create or refresh a local demo account with credit';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing to create a demo account outside local development.');

            return self::FAILURE;
        }

        /** @var string $email */
        $email = $this->option('email');

        /** @var string $password */
        $password = $this->option('password');

        /** @var string $credit */
        $credit = $this->option('credit');

        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            'name' => 'Demo',
            'password' => Hash::make($password),
        ]);

        $user->email_verified_at = now();
        $user->save();

        // Credit enters through the ledger, never by setting the balance.
        // A balance written directly would be money with no entry behind it,
        // which breaks the invariant that the cached balance equals the sum of
        // the ledger — the one thing `pixelforge:verify-balances` checks.
        app(Ledger::class)->topUp($user, Money::fromUsd($credit));

        $user->refresh();

        $this->info("Demo account ready: {$email} / {$password}");
        $this->line('Balance: '.$user->balance_nanos->toUsdString(2));

        return self::SUCCESS;
    }
}
