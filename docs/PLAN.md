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

- **`Money`** — integer micro-dollars (1 USD = 1,000,000). Immutable. `fromUsd()` converts published rate strings via `bcmath`; `withMarkup(int $bps)` applies the markup and rounds half-up exactly once.
- **`Pricing`** — table-driven from `config/pixelforge.php`. Two entry points, `estimate()` and `actual()`, used by both the interface and the charging path so the two cannot diverge.
- **`GenerationState`** — the status enum, its rank ordering, and `canTransitionTo()`.

**Tests**
- `money_never_loses_precision_converting_published_rate_strings` — table-driven over every published rate. Float conversion of a decimal rate string silently truncates; this asserts it does not happen.
- `markup_rounds_half_up_exactly_once` — asserts that marking up a raw cost differs from marking up an already-rounded cost, pinning the rounding point so it cannot drift later.
- `state_transition_matrix` — every from/to pair, including the transitions that must be refused.

**Closes with:** 73 unit tests covering the arithmetic and the transition matrix, running in 0.16 seconds with no database and no booted application, plus two feature tests asserting the shipped rate configuration is well formed and can price every size and quality it declares.

**Rates are provisional** until the published table is confirmed. They are read as configuration, so correcting them changes `config/pixelforge.php` alone. The unit tests fix their own rates rather than reading that file, so a corrected rate cannot quietly invalidate the arithmetic they assert.

---

## Phase 2 — Schema and models

Migrations for `generations`, `webhook_events` and `ledger_entries`, plus `balance_micros` on users. Every index carries a comment explaining what query or guarantee it serves. Models, casts, factories.

**Tests — these exercise the database rather than the code**
- `duplicate_provider_event_id_is_rejected_by_the_database`
- `second_charge_for_a_generation_is_rejected_by_the_database`
- `webhook_event_can_be_stored_for_an_unknown_generation`

The reason to test constraints directly is that the guarantees belong in the schema, not in application code. If a constraint is later dropped, a test fails rather than money quietly going missing.

---

## Phase 3 — Provider gateway

An `ImageProvider` interface with two implementations: `PixelforgeClient` for real calls, and `FakeImageProvider` for tests and local development. Retry policy with full jitter, `Retry-After` handling, a distinct exception type per error category, and a Redis semaphore for the provider's concurrency limit.

Full jitter rather than fixed backoff: when the provider sheds load, fixed backoff retries every queued job at the same instant and reproduces the overload that caused the failure.

The rest of the application depends on the interface, so this phase is buildable against the fake; the real client is completed once the API behaviour is confirmed from the documentation.

**Tests**
- `retries_on_429_and_honours_retry_after`
- `retries_on_503_with_jittered_backoff`
- `never_retries_a_422`
- `concurrency_limit_releases_the_job_rather_than_sleeping_the_worker`
- `submit_sends_an_idempotency_key_that_survives_retries`

---

## Phase 4 — Submit path

`SubmitGeneration` action, `SubmitGenerationToProvider` job, controller and form request.

**The generation row is written and committed before the provider is called.** Otherwise a webhook can arrive for a generation that has no row yet, and the event has nowhere to land.

**Tests**
- `generation_row_exists_before_the_provider_is_called`
- `timeout_marks_the_row_needs_reconcile_and_does_not_resubmit` — a timeout is an unknown outcome, not a failure; resubmitting risks a second generation the user pays for.
- `exhausted_retries_mark_the_generation_failed_and_charge_nothing`

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

A generation form with a live, server-computed estimate, and a history view showing status, estimated and actual cost, the image, and a failure reason where there is one. Status updates via Inertia partial reloads, polling only while something is in flight.

The estimate is computed server-side by the same `Pricing` class that performs the charge. A second rate table in JavaScript would eventually disagree with the first, and the price shown would stop matching the price charged.

**Tests**
- `estimate_endpoint_returns_the_same_figure_the_charge_would_use`
- `history_shows_estimated_and_actual_for_a_completed_generation`
- `failed_generation_is_shown_as_free`

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

Affordability is enforced by a conditional `UPDATE ... WHERE balance_micros >= :amount` checked against the affected row count, so the check and the deduction are a single atomic statement rather than a read followed by a write.

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
- [ ] `SUM(ledger_entries.amount_micros)` equals `users.balance_micros` for every user.
- [ ] Recorded provider costs match `GET /v1/usage` exactly for the deployed key.
- [ ] No generation is stuck in a non-terminal state.
- [ ] The full suite passes, static analysis is clean, and CI is green.

---

## Assumptions and open questions

Confirmed against the API documentation before phase 3 completes; the design is deliberately arranged so none of these answers changes its structure.

1. Whether the submit endpoint accepts an idempotency key.
2. Whether a provider generation id is returned synchronously.
3. Whether client-supplied metadata can be attached to a generation.
4. What uniquely identifies a webhook event, as distinct from a delivery attempt.
5. Whether events carry a sequence number or only a timestamp.
6. The exact signature scheme and timestamp tolerance.
7. The published rate table, and which token counts are known at submit time.
8. The error taxonomy, and whether `Retry-After` is sent.
