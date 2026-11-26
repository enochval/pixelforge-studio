<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\OperatorReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\SendOperatorsToTheirConsole;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canLogin' => Route::has('login'),
    'canRegister' => Route::has('register'),
]))->name('home');

/*
|--------------------------------------------------------------------------
| The customer application
|--------------------------------------------------------------------------
|
| Generating images, and buying the credit that pays for it. Closed to
| operators: an account that can read every user's margin should not also be
| spending on the same balance, so a figure in the console is never partly the
| operator's own activity.
|
| The redirect is a convenience; `can:generate-images` is the rule.
|
*/
Route::middleware(['auth', 'verified', SendOperatorsToTheirConsole::class, 'can:generate-images'])->group(function () {
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');

    // Prices a request without submitting it, so the figure on screen comes
    // from the same code that performs the charge.
    Route::post('/generations/quote', [GenerationController::class, 'quote'])->name('generations.quote');

    // A simulated card payment. No money moves anywhere real.
    Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
});

/*
|--------------------------------------------------------------------------
| The operator console
|--------------------------------------------------------------------------
|
| Every user's spend and the margin taken on it, so it is gated rather than
| left to anyone who guesses the URL.
|
*/
Route::middleware(['auth', 'verified', 'can:view-operator-report'])->group(function () {
    Route::get('/operator', [OperatorReportController::class, 'index'])->name('operator.index');
});

/*
|--------------------------------------------------------------------------
| Both
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    // Signing in lands here, which forwards to whichever application the
    // account belongs to. One place decides, rather than every link guessing.
    Route::get('/dashboard', fn () => redirect()->route(
        request()->user()?->isOperator() === true ? 'operator.index' : 'generations.index',
    ))->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
