# Build Plan

## Approach

The brief describes an asynchronous provider, money that is only fully known after the fact, and delivery guarantees that are at-least-once. That combination has one dominant failure mode — **charging a user twice, or charging them the wrong amount** — so the plan is organised around closing that first and adding surface area afterwards.

Three principles drive the sequencing:

**Correctness before surface area.** The pricing arithmetic and the idempotency guarantees are built and tested before any screen exists. Everything visible is built on top of something already proven.

**Each phase ends green.** Migrations run, static analysis passes, the full suite passes, the work is committed. Nothing starts on a red build, and at any point the repository is in a coherent, demonstrable state.

**Deploy early.** Deployment is phase 8 of 11, not the last step. Infrastructure problems found late are expensive and tend to surface exactly when there is no time to absorb them.

Testing is not a phase of its own. Each phase below names the tests that close it; a phase with working code and no tests is not finished.

---

## Phase 0 — Foundation

**Status: complete.**

Laravel 12.74, a Vue 3 + Inertia + Tailwind starter kit, Pest, Larastan at level 6, Pint, and a CI workflow running style, static analysis and tests.

**Everything runs in Docker, PHP included.** Six services: the application, a queue worker, the scheduler, Vite, Postgres 16 and Redis 7. No `Makefile` target uses the host's PHP or Node, so a clone and `make up` produces the same environment on any machine.

The Dockerfile is multi-stage: a base image carrying only the extensions this application needs (`pdo_pgsql`, `bcmath` for converting published rate strings to integers without a float, `pcntl` so queue workers shut down cleanly on a signal, and `opcache`); a `vendor` stage that resolves PHP dependencies once and is reused by the asset build, which depends on Ziggy; and a `production` target with no dev dependencies, no Node and an authoritative classmap. CI builds that production target on every push, so it stays verified rather than assumed.

Queue workers run as their own service rather than inside the web container, so restarting one does not interrupt the other and concurrency scales with `--scale worker=N`.

**The tests run against Postgres, not SQLite.** The properties this application depends on are Postgres properties: partial unique indexes, `SELECT ... FOR UPDATE`, and the behaviour of unique-constraint violations under concurrent writes. SQLite reports those tests as passing without exercising them, which is worse than leaving them untested, because it hides the gap.

One configuration bug surfaced here and is worth recording, because it fails quietly. PHPUnit writes its `<env>` entries to `$_ENV` and `putenv()` but not to `$_SERVER`, and Laravel's environment repository reads `$_SERVER` first. Under Compose, which injects `.env` into the container as real environment variables, the test settings were therefore ignored: the suite ran with `SESSION_DRIVER=database`, which breaks authenticated requests because the session write is rolled back with the `RefreshDatabase` transaction, and against the development database rather than the test one. `tests/bootstrap.php` mirrors `$_ENV` over `$_SERVER` before the application boots.

**Closes with:** the starter-kit suite green against Postgres, and CI running `lint → analyse → test` through the same Compose stack used for development.

---

## Phase 1 — Domain layer

**Status: complete.**

Pure PHP in `app/Domain/`. No Eloquent, no facades, no container — so it is unit-testable in milliseconds and the pricing rules can be read without reference to the framework.

- **`Money`** — integer nanodollars (1 USD = 1,000,000,000). Immutable. `fromUsd()` and `fromUsdPerMillionTokens()` convert the provider's decimal strings via `bcmath`; `withMarkup(int $bps)` applies the markup and rounds half away from zero exactly once.
- **`RateCard`** — the four published per-token rates plus the token model, built either from `GET /v1/pricing` or from the rates recorded on a generation.
- **`Pricing`** — two entry points. `quote()` produces the ceiling shown before submission; `providerCost()` produces the exact cost from the counts the provider reports. Both are used by the interface and the charging path, so the two cannot diverge.
- **`TokenUsage`** — the four counts a generation is billed on.
- **`GenerationStatus`** — the status enum, its rank ordering, and `canTransitionTo()`.

**Tests**
- `money_never_loses_precision_converting_published_rate_strings` — table-driven over every published rate. Float conversion of a decimal rate string silently truncates; this asserts it does not happen.
- `markup_rounds_half_up_exactly_once` — asserts that marking up a raw cost differs from marking up an already-rounded cost, pinning the rounding point so it cannot drift later.
- `state_transition_matrix` — every from/to pair, including the transitions that must be refused.

**Closes with:** 98 unit tests covering the arithmetic and the transition matrix, running in 0.2 seconds with no database and no booted application, plus feature tests asserting the committed rate table is well formed, that it can quote every size and quality it accepts, and that a quote survives the pricing endpoint being unreachable.

**The strongest of these reproduces the worked example from the provider's own documentation** — $0.373875000 for a high quality 1536x1024 generation with a 400-character prompt and two reference images — to the last digit. Matching a figure the provider published is better evidence that the pricing model was understood than any number this project could assert against itself.

The unit tests write out the rates they assert against rather than reading configuration. These tests exist to catch a change in the arithmetic, not to agree with whatever the arithmetic currently produces.

---

## Phase 2 — Schema and models

**Status: complete.**

Implements the model documented in [ERD.md](ERD.md): migrations for `generations`, `webhook_events` and `ledger_entries`, plus `balance_nanos` on users. Every index carries a comment explaining what query or guarantee it serves. Models, casts, factories.

**Tests — these exercise the database rather than the code**
- `duplicate_provider_event_id_is_rejected_by_the_database`
- `second_charge_for_a_generation_is_rejected_by_the_database`
- `webhook_event_can_be_stored_for_an_unknown_generation`

The reason to test constraints directly is that the guarantees belong in the schema, not in application code. If a constraint is later dropped, a test fails rather than money quietly going missing.

Beyond the two unique indexes, check constraints hold the status vocabulary, the sign of each ledger kind, and the agreement between a generation's outcome and its recorded costs, so a half-applied outcome cannot be written by any path.

**Closes with:** 23 constraint tests and 122 passing overall.

Two things surfaced while writing them. Postgres aborts the surrounding transaction when a statement fails, and `RefreshDatabase` runs each test inside one — so asserting that a write is refused and then querying the result needs the attempt wrapped in a savepoint. And `balance_nanos` is deliberately absent from the model's fillable attributes, because a balance must never be settable from request input.

---

## Phase 3 — Provider gateway

**Status: complete.** Built first against the in-memory implementation, then corrected against the published documentation — see [PROVIDER-NOTES.md](PROVIDER-NOTES.md) for what each answer changed.

An `ImageProvider` interface with two implementations: `PixelforgeClient` for real calls, and `FakeImageProvider` for tests and local development. Retry policy with full jitter, `Retry-After` handling, a distinct exception type per error category, and a Redis semaphore for the provider's concurrency limit of three.

Full jitter rather than fixed backoff: when the provider sheds load, fixed backoff retries every queued job at the same instant and reproduces the overload that caused the failure.

Failures are dispatched on the provider's **error code**, not the HTTP status. Three unrelated conditions share status 429 — a per-minute rate limit, a cap on concurrent generations, and an exhausted daily quota — and they need waiting, releasing and stopping respectively. The status is kept only as a fallback for codes the provider might add later.

The rest of the application depends on the interface rather than on the client, which is what let the phases above this one be built and tested before the provider was reachable at all.

**Tests**
- `retries_on_429_and_honours_retry_after`
- `retries_on_503_with_jittered_backoff`
- `never_retries_a_422`
- `concurrency_limit_releases_the_job_rather_than_sleeping_the_worker`
- `submit_sends_an_idempotency_key_that_survives_retries`

**Closes with:** 47 tests across the retry policy, the HTTP client, the in-memory provider and the concurrency limiter. 169 passing overall.

The gateway sits in `app/Integrations/Pixelforge/` rather than `app/Providers/`, which in a Laravel application means service providers and nothing else.

Transport, retry policy and concurrency control are separate classes because each is a separate decision. The policy is unit-tested with a seeded randomizer, so its jitter is asserted rather than timed, and the limiter is tested against the real Redis, because what it coordinates is behaviour between processes that a mock could not exercise.

Failures are typed by category rather than by status code, since the correct response differs per category and branching on a code at each call site would let those branches drift. A 429 is inspected further: rate limits and concurrency limits can arrive under the same status, and only one of them is improved by waiting.

---

## Phase 4 — Submit path

**Status: complete.**

`SubmitGeneration` action, `SubmitGenerationToProvider` job, controller and form request.

**The generation row is written and committed before the provider is called.** Otherwise a webhook can arrive for a generation that has no row yet, and the event has nowhere to land.

**Tests**
- `generation_row_exists_before_the_provider_is_called`
- `timeout_marks_the_row_needs_reconcile_and_does_not_resubmit` — a timeout is an unknown outcome, not a failure; resubmitting risks a second generation the user pays for.
- `exhausted_retries_mark_the_generation_failed_and_charge_nothing`

**Closes with:** 22 tests covering the action, the job and the endpoint. 191 passing overall.

Three decisions worth noting. The job is dispatched `afterCommit`, because a job queued inside an uncommitted transaction can start before the row it refers to is visible — or, after a rollback, refer to a row that never existed. The idempotency key is stored on the generation rather than minted inside the job, so every attempt presents the same key; a key generated per attempt would turn retrying into a way to create a second generation rather than a way to avoid one. And `WithoutOverlapping` discards a blocked duplicate rather than releasing it, since whichever job holds the lock is already doing the work the duplicate would repeat.

The tests fake the queue in `beforeEach`. The test connection is synchronous, so a dispatched job would otherwise run inside the action that queued it, and a failure staged for the job would be consumed before the test reached it — which is exactly what happened on the first run.

---

## Phase 5 — Webhooks and charging

Signature verification middleware, the inbox insert, `ProcessWebhookEvent`, and an `ApplyProviderEvent` action shared with the reconciler in phase 7.

Three separate mechanisms, each handling a distinct problem:

| Mechanism | Problem it solves |
|---|---|
| `provider_event_id` unique index | The same event delivered more than once |
| Monotonic status rank | A stale event arriving after a later one |
| `SELECT ... FOR UPDATE` | Two workers processing the same generation concurrently |

Charging is guarded twice — once at the event boundary by the inbox, and again at the ledger boundary by a unique index on `(generation_id, kind)`. The two fail independently, so neither alone is relied on.

**Tests**
- `duplicate_webhook_delivery_charges_once`
- `out_of_order_processing_after_succeeded_does_not_regress_state`
- `failed_generation_creates_no_ledger_entry`
- `concurrent_webhooks_for_same_generation_charge_once` — two database connections contending on the row lock, not a simulated race
- `invalid_signature_is_rejected_and_stores_nothing`
- `replayed_event_outside_the_timestamp_tolerance_is_rejected`

---

## Phase 6 — Interface

A generation form with a live, server-computed quote, and a history view showing status, the quote and the actual cost, the image, and a failure reason where there is one. Status updates via Inertia partial reloads, polling only while something is in flight.

The quote is computed server-side by the same `Pricing` class that performs the charge. A second rate table in JavaScript would eventually disagree with the first, and the price shown would stop matching the price charged.

It is presented as a ceiling — "up to" — because that is what it is. Two of the four token counts are only known once a generation completes, so quoting a single confident figure would be a claim the provider's own documentation does not support.

**Tests**
- `quote_endpoint_returns_the_same_ceiling_the_charge_is_measured_against`
- `history_shows_the_quote_and_the_actual_cost_for_a_completed_generation`
- `failed_generation_is_shown_as_free`
- `an_expired_image_url_is_not_offered_as_a_live_link`

---

## Phase 7 — Reconciliation

A scheduled command covering the cases webhooks cannot: deliveries that never arrive, and events received before their generation could be identified. It polls non-terminal generations older than 60 seconds, retries orphaned events, and sweeps anything still unresolved after 30 minutes to a terminal failed state at no cost.

It calls `ApplyProviderEvent` — the same code path as the webhook handler. Two implementations of the same state transition would drift, and the drift would show up as a double charge.

**Tests**
- `reconciler_completes_a_generation_whose_webhook_never_arrived`
- `reconciler_does_not_double_charge_a_generation_already_charged_by_a_webhook`
- `stale_generation_is_failed_and_charged_nothing`

---

## Phase 8 — Deployment

Deployed to Laravel Cloud with a separate API key for the deployed environment, a queue worker and the scheduler running, and the webhook endpoint registered. Seeded with real generations, including deliberate failures and duplicate deliveries.

**Closes with:** recorded provider costs matching `GET /v1/usage` for that key exactly.

---

## Phase 9 — Credit balances

Holds and settlement rather than a direct deduction: a `hold` for the estimate at submit, then `hold_release` plus `charge` on success, or `hold_release` alone on failure. A failed generation therefore costs nothing, and the user's money is reserved only for as long as the generation runs.

Affordability is enforced by a conditional `UPDATE ... WHERE balance_nanos >= :amount` checked against the affected row count, so the check and the deduction are a single atomic statement rather than a read followed by a write.

**Tests**
- `two_concurrent_submits_cannot_both_spend_the_same_balance`
- `failed_generation_releases_the_hold_in_full`
- `balance_cache_always_equals_the_sum_of_the_ledger` — over a randomised sequence of operations

---

## Phase 10 — Operator report

Credit spend, provider cost and profit by day and by user, grouped in SQL rather than in PHP. Includes a reconciliation row comparing recorded provider costs against `GET /v1/usage` and showing the difference.

**Tests**
- `report_totals_equal_the_ledger`
- `reconciliation_row_reports_zero_delta_when_records_agree`

---

## Phase 11 — Documentation and final verification

README covering the API behaviours the design depends on, the schema, the two flow diagrams, the money representation, and the decisions deliberately left out with the reasoning for each.

---

## Definition of done

Verified against the **deployed** application, not a local environment:

- [ ] A duplicate webhook delivery results in exactly one ledger entry.
- [ ] A failed generation is recorded as failed and charged nothing.
- [ ] `SUM(ledger_entries.amount_nanos)` equals `users.balance_nanos` for every user.
- [ ] Recorded provider costs match `GET /v1/usage` exactly for the deployed key.
- [ ] No generation is stuck in a non-terminal state.
- [ ] The full suite passes, static analysis is clean, and CI is green.

---

## The provider's contract

Eight questions were identified as the ones that could affect the design, and the design
was arranged so that none of their answers would change its structure. They are now all
answered against the published documentation, and the answers are recorded in
[PROVIDER-NOTES.md](PROVIDER-NOTES.md) alongside where each one shows up in the code.

Three of them changed real decisions rather than confirming them:

| What the docs said | What changed |
|---|---|
| Costs are reported to nine decimal places and never rounded, and one rate is 12.5 micro-dollars per token | Money is nanodollars, not micro-dollars. Every rate then lands on a whole number per token, so a provider cost is exact and one rounding point disappears entirely |
| Rates are read from `GET /v1/pricing`, but a generation's rates are fixed when it is created and returned on the generation | A quote prices from the cached endpoint; a charge prices from the rates recorded on the row. A stale table can make a quote wrong, never a charge |
| Three unrelated conditions share HTTP status 429 | Failures dispatch on the provider's error code, not the status. A daily quota is no longer retried for ten minutes against a limit that resets at midnight |

The five that confirmed the existing design: the submit endpoint takes an idempotency key
and returns the generation id synchronously, client metadata is echoed on every webhook,
events carry a unique id distinct from the delivery attempt, there is no sequence number
so ordering is by state rank, and the signature is HMAC-SHA256 over
`<timestamp>.<raw body>` with a 300-second tolerance.
