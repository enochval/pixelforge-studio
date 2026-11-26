# The provider's contract, and what this application does about it

What the Pixelforge API documentation guarantees, and where each guarantee shows up in
the code. Read against the published docs on 25 November 2026.

This is here because almost every design decision in [SYSTEM-DESIGN.md](SYSTEM-DESIGN.md)
traces back to a line in those docs, and the trace is worth making explicit. Where the
provider's contract decided something, the decision belongs to the contract and not to
taste.

Where a guarantee is answered by code that has not been written yet, it says so.
[PLAN.md](PLAN.md) tracks which phase each part belongs to.

---

## The three statements that shaped the design

> *"A webhook can arrive before your `POST /v1/generations` request has returned."*

This is why the generation row is written and committed before the provider is contacted
at all, in `App\Actions\SubmitGeneration`. A row that does not exist yet has nowhere for
an event to land, and an event with nowhere to land is a generation the user paid for and
never saw.

> *"Events can arrive out of order. A retried `generation.processing` can arrive after
> `generation.succeeded`. Go by the generation's status and never move a generation back
> from succeeded or failed."*

There is no sequence number to order by, only a timestamp, and ordering by a timestamp is
unsafe across clock skew and equal values. So ordering is resolved by state: each status
carries a rank in `App\Domain\GenerationStatus`, the rank is denormalised onto the row,
and a transition is only applied when it moves forward.

> *"Costs are exact: one token at $1.00 per 1M is $0.000001, and amounts are never
> rounded. Floating-point arithmetic will drift; use integers (for example nanodollars)
> or decimals."*

Money is an integer number of nanodollars throughout, in `App\Domain\Money`. The unit is
not a preference: one of the four published rates, $12.50 per million reasoning tokens,
is 12.5 micro-dollars per token, so anything coarser loses money on every generation.

---

## The pricing model

The provider never returns a price. It reports token counts and the rates a generation
was fixed at, and computing the cost is ours.

| Token type | Rate per 1M | Known at submission? |
|---|---|---|
| Input text | $5.00 | Estimated: `ceil(characters / 4)`, real count within ±15% |
| Input image | $10.00 | **Exact** — 765 per reference image |
| Output | $40.00 | **Exact** — published table by quality and size |
| Reasoning | $12.50 | Range only, by quality (high: 1,000–8,000) |

Two of the four are not exact before a generation runs — text tokens only within a
published 15% band, reasoning tokens only within a range — so **no exact figure exists
up front.** What the user is shown is therefore a ceiling: the top of the variance band
and the top of the range. Not an average, which would be wrong about half the time, and
in the direction that leaves a generation unaffordable once it has already run.

`Pricing::quote()` produces that ceiling. `Pricing::providerCost()` produces the exact
cost once the provider has reported the real counts.

In nanodollars every rate is a whole number per token, which means the provider cost is a
sum of integer products with nothing to round. It reconciles against `GET /v1/usage`
digit for digit rather than within a tolerance. The only rounding anywhere in the pricing
path is the markup, applied once.

`tests/Unit/PricingTest.php` reproduces the worked example from the documentation —
$0.373875000 for a high quality 1536x1024 generation with a 400-character prompt and two
reference images — to the last digit.

### Quotes read the published rates; charges read the generation's own

The documentation says rates can change and should be read from `GET /v1/pricing` rather
than hardcoded. It also says a generation's rates are **fixed when it is created** and
returned on the generation itself.

Those two facts point at different sources, so the application uses both:

- **A quote** is priced from the pricing endpoint, cached for an hour by
  `RateCardRepository`, falling back to the table committed in `config/pixelforge.php`
  when the endpoint cannot be reached. Refusing to show a price because a pricing
  endpoint is down would take the product offline for a reason the user cannot act on.
- **A charge** is priced from the `rates` recorded on the generation row at submission.

So a stale cache, or a fallback standing in for a rate that has since moved, can make a
quote slightly wrong. It can never make a charge wrong.

---

## Exactly-once charging

Four separate problems, each with its own mechanism. Conflating any two of them is the
usual way a system like this ends up charging twice.

| Problem | Mechanism | Built |
|---|---|---|
| The same event delivered twice | `webhook_events.provider_event_id` UNIQUE — the transactional inbox | Schema in place |
| A charge written twice for one generation | Partial unique index on `(generation_id, kind)` in `ledger_entries` | Schema in place |
| Events applied out of order | Monotonic `status_rank` guard inside the `UPDATE` | Column and rank ordering in place; the guarded `UPDATE` lands with the webhook phase |
| Two workers processing one generation at once | `SELECT … FOR UPDATE` on the generation row | With the webhook phase |

The two unique constraints are deliberately in the schema rather than in application
code. A uniqueness check performed by reading and then writing is not a guarantee; two
requests can both read, both find nothing, and both write. The database is the only place
the check and the write can be the same operation.

**Deduplication is on the event id, not the delivery attempt.** The provider sends both:
`Pixelforge-Event-Id` identifies the event, `Pixelforge-Delivery-Attempt` counts
deliveries of it, *including resends from the dashboard*. Deduplicating on the attempt
would let a dashboard resend through as new work and charge for it a second time.

**And the provider misbehaves by design.** Even with no test keyword in the prompt, the
documentation states that some webhook events are deliberately delivered twice and a
small share of generations fail at random. Correctness under duplicate delivery is not an
edge case here; it is the normal operating condition.

---

## Idempotent submission

`POST /v1/generations` accepts an `Idempotency-Key`. Presented twice with identical
parameters it returns the original generation, creates nothing, bills nothing, and sets
`Idempotent-Replayed: true`. Presented with *different* parameters it returns
`409 idempotency_key_reused`.

The key is minted once and stored on the generation row before the first attempt, so
every retry presents the same key. A key minted per attempt would make retrying a way to
*create* a second generation rather than a way to avoid one.

Which also means a `409` is not a provider problem but a bug here — it can only happen if
a generation's parameters changed after submission. `IdempotencyKeyReused` is therefore
never retried and never absorbed: quietly minting a fresh key would turn a bug in this
application into a generation the user pays for twice.

A timeout is treated as an **unknown** outcome, not a failure. The request may have been
accepted. Closing the generation there would charge nothing for work the provider is
already doing, and the user would never see the image.

---

## Failures, and why there is a class per kind

The provider returns a `code` alongside every error, and **three unrelated conditions
share HTTP status 429**:

| Code | Status | What it needs |
|---|---|---|
| `rate_limit_exceeded` | 429 | Wait out `Retry-After` |
| `concurrency_limit_exceeded` | 429 | Release the job — waiting helps only once one of ours finishes |
| `daily_limit_exceeded` | 429 | Stop. The quota resets at UTC midnight |

So `PixelforgeClient` dispatches on the error code, with status only as a fallback for
codes added later. Dispatching on status would collapse the three, and a used-up daily
quota would be retried for ten minutes against a limit that cannot clear in that time —
spending every attempt against the 60-per-minute request allowance that other work needs.

On a concurrency limit the job is **released back to the queue, never slept**. Sleeping
holds a worker process hostage waiting for a condition another worker has to clear, and
under load every worker ends up asleep waiting on every other.

The published limits are 60 requests per minute, **3 concurrent generations**, and 500
generations per UTC day, held in `config/pixelforge.php`.

---

## Webhook delivery

The endpoint arrives with the webhook phase. What the contract requires of it:

Any `2xx` within **10 seconds** counts as delivered; anything else is retried after 10s,
30s, 2m, 10m and 15m, up to six attempts. So the endpoint must verify the signature,
insert the event, dispatch a job and return `204` — nothing slow inside the request. A
handler that charged inline would be fast enough most of the time and time out under
load, at which point the provider would redeliver the event it had already been charged
for.

Signatures are `Pixelforge-Signature: t=<unix>,v1=<hex>`, an HMAC-SHA256 of
`<timestamp>.<raw body>` keyed with the signing secret of the API key that created the
generation. Verification goes against the raw body **before** any JSON decoding, compares
in constant time, and rejects anything outside a 300-second window — the tolerance is
already configured as `pixelforge.webhooks.tolerance_seconds`. The route has to sit
outside the web middleware group, since the provider has no CSRF token.

**Delivery is explicitly not guaranteed.** `GET /v1/generations/{id}` is the documented
fallback, which is what the reconciler is for — not a belt-and-braces addition but the
other half of a contract that says webhooks may simply never arrive. The schema supports
it already: a partial index on `generations` covers exactly the rows that have not reached
a terminal status, so the reconciler's scan stays the size of the in-flight set however
large the table grows.

Each API key has its own webhook URL and signing secret, so one key is used per
environment and no environment sees another's events. Webhook URLs must be publicly
reachable — loopback and private ranges are rejected — so local development runs behind a
tunnel.

---

## Test keywords

The documentation calls these out, and they are the harness the provider expects to have
been used.

`FakeImageProvider` honours the four that change a generation's own outcome, so a
scenario written against the in-memory provider replays against the real one unchanged.
It deliberately does not implement `[succeed]` or `[duplicate]`: those suppress and
inject the provider's random misbehaviour, and a fake that misbehaved at random would
make the suite flaky without covering anything the explicit cases do not.

| Keyword | Behaviour |
|---|---|
| `[blocked]` | Rejected at submission, `400 content_policy_violation` |
| `[overloaded]` | Rejected at submission, `503 overloaded` |
| `[fail]` | Accepted, then fails while processing |
| `[succeed]` | Accepted and never fails at random |
| `[slow]` | 60–90 seconds instead of 5–30 |
| `[duplicate]` | Every webhook event delivered twice |

---

## Loose ends the contract leaves

**Signed image URLs expire 24 hours after completion**, and a fresh URL from
`GET /v1/generations/{id}` expires at the same moment. `image_url_expires_at` is recorded
rather than inferred, so the interface knows whether what it holds can still be shown.
Anything that must outlive the window has to be downloaded and stored.

**Usage is billed on the UTC day a generation completed, not the day it was created.**
That is the grouping the reconciliation report has to match, and getting it wrong would
produce a discrepancy that looks like a charging bug.
