# Architecture

This document records the structure of the application and the reasoning behind the choices that shaped it. It describes the design as a whole; `docs/PLAN.md` tracks which parts are built.

## Context

The application submits image generations to an asynchronous provider and charges users for them. Three properties of that provider drive almost every decision here:

- Results arrive later, by webhook or by polling, not in the response to the request that created them.
- Part of the cost is only known once a generation finishes, so the price shown before submission is an estimate and the price charged afterwards is not.
- Webhook delivery is at-least-once and unordered. The same event can arrive twice, and a later event can arrive before an earlier one.

Taken together these produce one dominant failure mode: **charging a user twice, or charging them an amount that does not match what the provider actually billed.** The architecture is organised around making that failure structurally difficult rather than merely unlikely.

---

## Architectural decisions

### A layered monolith, with an isolated domain

The application is a single deployable with four layers and one rule that carries weight: the domain layer has no framework dependency.

```
app/
├── Http/              Controllers, form requests, middleware. Validate and delegate.
├── Actions/           Use cases. One public method each.
├── Domain/            Plain PHP. No Eloquent, no facades, no container.
├── Providers/         The provider gateway and its implementations.
├── Jobs/              Queued work.
└── Console/Commands/  Scheduled work.
```

`Money`, `Pricing` and `GenerationState` are ordinary PHP objects. They can be exercised without a database, an HTTP client or a booted application, which means the pricing rules — the part of the system with the least tolerance for error — are tested in isolation and in milliseconds.

Use cases are Actions rather than service classes. `SubmitGeneration` and `ApplyProviderEvent` each name a thing the system does and expose one method. A `GenerationService` would accumulate unrelated responsibilities over time, and its name would stop describing its contents.

### A server-driven interface

The user interface is rendered through Inertia. There is no REST or GraphQL API serving the application's own screens, no client-side router, and no client store mirroring server state. Controllers return props; Vue renders them.

This is a deliberate narrowing. The cost displayed before submission is computed by the same `Pricing` class that performs the charge, so the figure a user agrees to and the figure they are billed cannot drift apart. A JavaScript implementation of the rate table would be a second source of truth for the one value in the system that most needs a single one.

### An append-only ledger

Every movement of money is an immutable row in `ledger_entries`. Nothing is updated or deleted; a correction is a new compensating entry. `users.balance_micros` is a cached sum maintained in the same transaction as the entry that changes it, and an artisan command asserts that the cache and the ledger agree.

This makes a balance reconstructible at any point in time, and it makes "charged exactly once" a property the schema enforces rather than a claim the application makes.

---

## Backend patterns

| Pattern | Applied to | Purpose |
|---|---|---|
| Value object | `Money` | Immutable integer micro-dollars. Removes any path by which a monetary amount can become a float. |
| Table-driven policy | `Pricing` | Rates are configuration, not conditionals. One implementation serves both the estimate and the charge. |
| State machine | `GenerationState` | An explicit transition table with ranks, so out-of-order events are resolved by lookup rather than by nested conditions. |
| Command | `SubmitGeneration`, `ApplyProviderEvent`, `TopUpCredits` | One use case per class. `ApplyProviderEvent` is shared by the webhook handler and the reconciler so the transition logic exists once. |
| Gateway with a test double | `ImageProvider`, `PixelforgeClient`, `FakeImageProvider` | The application depends on the interface. Work that sits above the provider can be built and tested before the real client exists. |
| Transactional inbox | `webhook_events` | Events are persisted and deduplicated on arrival, then processed from storage. The standard response to at-least-once delivery. |
| Idempotency key | Generation submission | A timed-out request is an unknown outcome, not a failed one. The key makes retrying it safe. |
| Pessimistic locking | `SELECT … FOR UPDATE` on a generation | Serialises concurrent events for the same generation across a critical section spanning several reads and writes. |
| Compare-and-set | `UPDATE … WHERE balance_micros >= :amount` | Collapses the affordability check and the deduction into a single atomic statement, so two concurrent submissions cannot both pass. |
| Bulkhead | Redis semaphore around provider calls | A concurrency limit constrains simultaneous requests, which a rate limiter does not address. |
| Retry with full jitter | HTTP client and queued jobs | Fixed backoff retries every affected job at the same moment and reproduces the overload that caused the failure. |
| Reconciliation loop | `ReconcileGenerations` | Converges any state that webhooks failed to deliver, and resolves events received before their generation could be identified. |
| Authorisation and capture | `hold` → `charge` + `hold_release` | Reserves the estimate at submission and settles the actual cost on completion, so a failed generation costs nothing and funds are reserved only while the work runs. |

### Concurrency and delivery guarantees

Three distinct problems are handled by three distinct mechanisms, and conflating them is the usual source of double charges:

| Problem | Mechanism |
|---|---|
| The same event delivered more than once | Unique index on `webhook_events.provider_event_id` |
| A stale event arriving after a later one | Monotonic rank comparison in the state machine |
| Two workers processing one generation at the same time | Row-level lock for the duration of the transaction |

Charging is additionally guarded at the ledger boundary by a unique index on `(generation_id, kind)`. The inbox and the ledger constraint fail independently, so neither is relied on alone — a replayed event, a manual redelivery or a reconciler racing a webhook is refused by the database rather than by application logic.

Locking strategy is chosen per problem rather than uniformly. The webhook path holds a row lock because its critical section spans several statements; the balance path uses a conditional update because the check and the write collapse into one.

---

## Frontend patterns

| Pattern | Applied to | Purpose |
|---|---|---|
| Container and presentational split | `Pages/` receive props; `Components/` take props and emit events | Keeps data flowing in one direction and leaves components independently testable. |
| Persistent layout | `AuthenticatedLayout` | Survives navigation, so polling and transient UI state are not discarded on every visit. |
| Form object | Inertia's `useForm` | Submission, validation errors and pending state handled in one place rather than in hand-written refs. |
| Partial reload | `router.reload({ only: ['generations'] })` | Refreshes a single prop instead of the page, and the interval stops once nothing is in flight. |
| Composables | `useGenerationPolling`, `useMoney` | Vue's unit of reuse for shared reactive behaviour, without introducing a store. |
| Single formatting authority | `formatMicros()` over `Intl.NumberFormat` | One decision about how fractions of a cent are displayed, applied everywhere. |

---

## Decisions deliberately not taken

**Repository pattern.** Eloquent already provides the abstraction a repository would wrap. Adding a layer that forwards calls would obscure queries without making them easier to change.

**CQRS and event sourcing.** The ledger supplies the auditability that event sourcing is usually adopted for, without the projection machinery. The operator report is a straightforward SQL aggregation rather than a maintained projection.

**Hexagonal architecture with aggregate roots.** One bounded context and four tables do not justify the indirection. The domain layer is isolated where isolation pays — pricing and state — and nowhere else.

**Circuit breaker.** Bounded retries, jittered backoff and a capped total retry window handle provider failure at this volume. A breaker introduces shared state and a failure mode of its own, and nothing in the requirements calls for surviving a prolonged outage.

**Websockets for live status.** Inertia partial reloads on a short interval, stopping when nothing is in flight, achieve the same visible result with fewer moving parts in a deployed environment. The trade-off is latency measured in seconds rather than milliseconds, which is immaterial for work that takes longer than that to complete.

**A client-side store.** Pinia or Vuex would hold a copy of cost and status in the browser. Money with two sources of truth is precisely the failure this system is built to avoid, so server state is rendered rather than mirrored.

**Optimistic UI for cost and status.** Optimistic updates suit actions whose outcome is not in doubt. A generation's cost is not known until the provider reports it, and showing a user a figure that was never charged is worse than showing them a pending state.
