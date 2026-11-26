<?php

declare(strict_types=1);

use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\OperatorReportController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canLogin' => Route::has('login'),
    'canRegister' => Route::has('register'),
]))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The generations screen is the application. The dashboard Breeze ships
    // with has nothing to show that this does not, so it redirects here rather
    // than existing as a second, emptier home.
    Route::get('/dashboard', fn () => redirect()->route('generations.index'))->name('dashboard');

    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');

    // Prices a request without submitting it, so the figure on screen comes
    // from the same code that performs the charge.
    Route::post('/generations/quote', [GenerationController::class, 'quote'])->name('generations.quote');

    // A fake purchase: adds credit, takes no payment.
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');

    // Shows every user's spend and the margin taken on it, so it is gated
    // rather than left to anyone who guesses the URL.
    Route::get('/operator', [OperatorReportController::class, 'index'])
        ->middleware('can:view-operator-report')
        ->name('operator.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
