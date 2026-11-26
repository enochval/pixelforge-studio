<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Money;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates one account per application, both signed-in-able immediately.
 *
 * Two accounts rather than one, because there are two applications and an
 * operator cannot generate. A single demo account would only ever show half
 * the product.
 *
 * The customer starts with credit. Registering through the interface works,
 * but a new account has a zero balance, so the first thing anyone evaluating
 * this would see is a screen that cannot demonstrate charging.
 *
 * Refuses to run outside local development: the credentials are deliberately
 * guessable, which is the point locally and unacceptable anywhere else.
 */
final class CreateDemoUserCommand extends Command
{
    protected $signature = 'pixelforge:demo-user
                            {--password=password}
                            {--credit=20.00 : Starting balance in US dollars}';

    protected $description = 'Create or refresh the local demo accounts';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing to create demo accounts outside local development.');

            return self::FAILURE;
        }

        /** @var string $password */
        $password = $this->option('password');

        /** @var string $credit */
        $credit = $this->option('credit');

        $customer = $this->account('demo@pixelforge.test', 'Demo Customer', $password, isOperator: false);
        $operator = $this->account('operator@pixelforge.test', 'Demo Operator', $password, isOperator: true);

        // Credit enters through the ledger, never by setting the balance. A
        // balance written directly is money with no entry behind it, which
        // breaks the invariant `pixelforge:verify-balances` checks.
        if ($customer->balance_nanos->isZero()) {
            app(Ledger::class)->topUp($customer, Money::fromUsd($credit));
        }

        $this->components->twoColumnDetail('<fg=green>Customer</>', $customer->email.' / '.$password);
        $this->components->twoColumnDetail('  balance', $customer->fresh()->balance_nanos->toUsdString(2));
        $this->components->twoColumnDetail('<fg=cyan>Operator</>', $operator->email.' / '.$password);
        $this->components->twoColumnDetail('  sees', 'the console only — operators cannot generate');

        return self::SUCCESS;
    }

    private function account(string $email, string $name, string $password, bool $isOperator): User
    {
        $user = User::firstOrNew(['email' => $email]);

        $user->fill(['name' => $name, 'password' => Hash::make($password)]);

        // Neither of these is fillable, on purpose: an account must not be
        // promoted, nor given credit, by anything that mass-assigns input.
        $user->is_operator = $isOperator;
        $user->email_verified_at = now();

        $user->save();

        return $user;
    }
}
