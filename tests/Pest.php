<?php

use App\Domain\Money;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user with credit, funded through the ledger.
 *
 * Submitting now requires a balance that covers the quote, so most tests need
 * a funded user rather than a bare one. The top-up goes through Ledger rather
 * than setting the column, for the same reason production does: a balance
 * written directly is money with no entry behind it, and the invariant that
 * the cache equals the sum of the ledger is asserted in this suite.
 */
function fundedUser(string $credit = '10.00', array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    app(Ledger::class)->topUp($user, Money::fromUsd($credit));

    return $user->refresh();
}
