<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an operator out of the customer application.
 *
 * A redirect rather than a 403, because landing on the wrong application is
 * not an attempt at anything — it is following a link, or a bookmark, or the
 * default route after signing in. Sending them where they belong is a more
 * useful answer than refusing them where they do not.
 *
 * The authorisation itself is still a Gate, checked wherever credit is spent.
 * This middleware only decides where a browser ends up; it is not what makes
 * the separation safe.
 */
final class SendOperatorsToTheirConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isOperator() === true) {
            return redirect()->route('operator.index');
        }

        return $next($request);
    }
}
