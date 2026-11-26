<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook delivery that the provider did not sign.
 *
 * The endpoint is a public URL that anyone can post to, and a forged
 * `generation.succeeded` would charge a real user for work that never happened.
 * The signature is the only thing separating the two, so it is checked before
 * the request reaches anything that can write.
 *
 * The scheme, as published: `Pixelforge-Signature: t=<unix>,v1=<hex>`, where
 * the signature is the hex HMAC-SHA256 of `<timestamp>.<raw body>` keyed with
 * the signing secret of the API key that created the generation.
 *
 * Three details matter more than they look:
 *
 *   The hash covers the **raw** body, so it is read with getContent() and
 *   never from a decoded array. Re-encoding JSON produces different bytes —
 *   different key order, different whitespace, different unicode escaping —
 *   and the check would fail against a legitimate delivery.
 *
 *   The comparison uses hash_equals. A plain === returns as soon as two bytes
 *   differ, and the time it takes leaks how much of a guessed signature was
 *   correct, one byte at a time.
 *
 *   The timestamp is checked independently of the signature. A captured
 *   delivery stays correctly signed forever, so without a freshness window a
 *   recorded request could be replayed indefinitely. The provider signs each
 *   delivery attempt with a fresh timestamp, so a legitimate retry — even the
 *   last of six, fifteen minutes later — is never caught by this.
 *
 * A rejection answers 400 rather than 401. The provider treats any non-2xx as
 * a reason to redeliver, and nothing here distinguishes a forgery from a
 * misconfigured secret, so the status is informational only.
 */
final class VerifyPixelforgeSignature
{
    public function __construct(private readonly string $secret, private readonly int $toleranceSeconds) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Pixelforge-Signature', '');
        $signature = $this->parse($header);

        $timestamp = (int) ($signature['t'] ?? 0);
        $provided = (string) ($signature['v1'] ?? '');

        if ($timestamp === 0 || $provided === '') {
            abort(400, 'The Pixelforge signature header is missing or malformed.');
        }

        if (abs(time() - $timestamp) > $this->toleranceSeconds) {
            abort(400, 'The Pixelforge signature timestamp is outside the accepted window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->secret);

        if (! hash_equals($expected, $provided)) {
            abort(400, 'The Pixelforge signature does not match.');
        }

        return $next($request);
    }

    /**
     * Split `t=…,v1=…` into its parts.
     *
     * Written to tolerate additional comma-separated elements rather than
     * assuming exactly two, since a provider adding a `v2` scheme alongside
     * `v1` is the usual way these headers evolve.
     *
     * @return array<string, string>
     */
    private function parse(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $element) {
            [$key, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
