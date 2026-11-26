<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // One-off messages after a redirect, such as a credit purchase
            // confirming. Shared here rather than per-controller so any page
            // can show one without its controller having to know.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            // Shaped rather than serialised whole. The shell needs a name, an
            // address to show on hover, and which application this account
            // belongs to — not a balance on every page, nor whatever columns
            // the users table grows later.
            'auth' => [
                'user' => fn () => $request->user() === null ? null : [
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'is_operator' => $request->user()->isOperator(),
                ],
            ],
        ];
    }
}
