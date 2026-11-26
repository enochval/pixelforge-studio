# Pixelforge Studio

A small product built on an unreliable, paid API: users describe an image, the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API generates it, and they are billed
for what it cost plus a margin.

Almost everything here follows from one constraint. **The provider charges real money,
delivers its results unreliably, and tells you so in its own documentation** — webhooks may
arrive twice, arrive out of order, arrive before the request that caused them has returned,
or never arrive at all. A system that gets the happy path right and the rest approximately
right does not charge people correctly.

So the single claim this project makes is: **a generation is charged exactly once, for
exactly what it cost, or not at all.**

---

## Running it

Everything runs in Docker. Nothing uses the host's PHP or Node.

```bash
make up          # build, install, start every service, migrate
make seed-demo   # two demo accounts, both with password "password"
```

Then open **http://localhost:8000**.

| Account | Sees |
|---|---|
| `demo@pixelforge.test` | **Pixelforge Studio** — generate images, buy credit |
| `operator@pixelforge.test` | **Pixelforge Console** — spend, margin and reconciliation |

Two accounts because there are **two applications**, and neither can reach the other. An
operator cannot generate: an account that reads every user's margin should not also be
spending on the same balance, or a figure in the console would be partly the operator's own
activity. Signing in lands on whichever application the account belongs to.

| | |
|---|---|
| `make test` | 317 tests across three suites |
| `make check` | tests, static analysis (Larastan level 6) and style |
| `make help` | everything else |

To generate anything you need a Pixelforge API key in `.env` as `PIXELFORGE_API_KEY`, and
its signing secret as `PIXELFORGE_WEBHOOK_SECRET`. Without them the application still runs:
submissions fail with the provider's real authentication error, nothing is charged, and the
balance does not move.

Webhooks need a publicly reachable URL, so local development wants a tunnel
(`ngrok http 8000`) with `https://<host>/webhooks/pixelforge` set as the key's webhook URL
in the Pixelforge dashboard. **Without one the product still works** — the reconciler polls
and resolves generations within about two minutes instead of seconds, which is the fallback
the provider's own documentation names.

---

## The four decisions that matter

### Money is an integer number of nanodollars

Not cents, and not micro-dollars. The provider reports costs to nine decimal places and
states that amounts are never rounded, and one of its four rates — $12.50 per million
reasoning tokens — works out at **12.5 micro-dollars per token.** Anything coarser loses
real money on every generation.

In nanodollars every published rate is a whole number per token, so a provider cost is a
sum of integer products with nothing to round. There is exactly one rounding point in the
whole pricing path, and it is the margin.

This is not hypothetical, and it does not need a worked example to see: at 12,500
nanodollars per reasoning token, **any odd number of reasoning tokens produces a half
micro-dollar.** `tests/Unit/PricingTest.php` reproduces the costing example from the
provider's own documentation — $0.373875000 — to the last digit.

### The price shown before submission is a ceiling, not an estimate

Two of the four token counts a generation is billed on are not knowable until it finishes:
text tokens only within a published 15% band, reasoning tokens only within a range. So **no
exact figure exists up front**, and the interface says "up to" rather than naming one.

An average would be wrong about half the time, in the direction that leaves a generation
unaffordable once it has already run. A ceiling can be reserved against a balance; an
average cannot.

### Charging exactly once is the database's job, not the code's

Four separate problems, each with its own mechanism. Conflating any two of them is the
usual way a system like this charges twice.

| Problem | Mechanism |
|---|---|
| The same event delivered twice | `UNIQUE (provider_event_id)` — a transactional inbox |
| A charge written twice for one generation | `UNIQUE (generation_id, kind)`, partial |
| Events applied out of order | A compare-and-set on `status_rank`; the loser's `UPDATE` affects no rows |
| Two workers on one generation at once | The same compare-and-set — the transition is claimed, not decided |

None of these is a check performed in PHP. A uniqueness check that reads and then writes is
not a guarantee: two requests can both read, both find nothing, and both write. The
database is the only place the check and the write are the same operation.

### The row is written before the provider is called

The provider's documentation says plainly: *"A webhook can arrive before your
`POST /v1/generations` request has returned."* An event arriving for a generation with no
row has nowhere to land, and a generation with nowhere to land is one the user paid for and
never saw.

---

## How a generation flows

```
submit ──▶ row committed ──▶ hold the quoted ceiling ──▶ queue the submission
                                                              │
                                              ┌───────────────┴───────────────┐
                                        webhook arrives              nothing arrives
                                              │                             │
                                     verify signature                 reconciler polls
                                     store in inbox                   (every minute)
                                     queue processing                        │
                                              └───────────────┬──────────────┘
                                                              ▼
                                              claim the transition (compare-and-set)
                                                              ▼
                                            release the hold ──▶ charge the real cost
```

Both paths end in the same action, `ApplyProviderEvent`. Two implementations of one state
transition would eventually disagree, and the disagreement would be a charge. It also makes
the reconciler racing a late webhook harmless: whichever is second claims nothing and
changes nothing.

A failed generation releases its hold and is never charged, so it nets to zero — free
rather than refunded.

---

## The two applications

| Route | |
|---|---|
| `/generations` | Prompt, shape and detail as visible choices, because each one changes the price. The cost updates as you type and is stated as a ceiling. Generations appear as cards that shimmer while running and fill in when done, refreshed by a partial reload that stops once nothing is in flight |
| `/checkout` | A simulated card payment, in two steps. Published test cards make each outcome reproducible: one succeeds, three decline in different ways |
| `/operator` | Reconciliation first and largest, then totals, then spend by day and by user. Gated behind `view-operator-report` |

### The payment is simulated, and says so

A single button that always succeeds never exercises what happens when a payment fails —
and the thing on the other side of it adds money to a balance. So the flow has real
failure paths, triggered by card number in the manner payment providers use for their test
cards, which makes a decline reproducible rather than a coin toss.

It also carries a **single-use intent**, issued by the server and claimed with
`Cache::pull` so that reading and deleting are one operation. Without it, a double click or
an impatient refresh would add the credit twice — the same gap, and the same reasoning, as
the conditional updates used everywhere else money moves here.

### On the command line

The same figures, plus two checks:

| Command | |
|---|---|
| `pixelforge:reconcile` | Resolve generations whose outcome never arrived. Scheduled every minute |
| `pixelforge:verify-balances` | Assert every cached balance equals the sum of its ledger. Exits non-zero on disagreement, so it works as a deployment gate |
| `pixelforge:report` | The operator figures, for a deployment gate or an on-call check rather than a browser |

The reconciliation is the only figure in either the page or the command that can disprove
anything. Everything else is a sum of this application's own records, which will agree with
itself whether or not it is right. A provider that cannot be reached is reported as
**unavailable**, never as agreement — no answer and an answer of zero mean opposite things.

---

## What is deliberately not here

- **No real payments.** The checkout simulates a card and takes no money, as the brief
  specifies. A real integration would need a webhook, a pending state and a reconciliation
  of its own — which is the same problem this project already solves once, for a different
  provider.
- **No roles beyond the two.** `users.is_operator` is a boolean, not a role table. Two
  roles do not need a join table, and the check sits behind a Gate so adding more later is
  a change to one policy rather than to every route.
- **No reference images in the interface.** The client sends them, validation accepts them
  and pricing bills them at 765 tokens each — but a URL list would be the largest part of
  that form and the smallest part of what it demonstrates.
- **No websockets.** Inertia partial reloads on a three-second interval, stopping when
  nothing is in flight. Seconds of latency are immaterial for work that takes 5 to 30
  seconds, and it needs no broadcast server in a deployed environment.
- **No repository pattern, no CQRS, no circuit breaker.** [ARCHITECTURE.md](docs/ARCHITECTURE.md)
  has the reasoning for each, and for the patterns that are here.

---

## Reading further

| Document | |
|---|---|
| [PROVIDER-NOTES.md](docs/PROVIDER-NOTES.md) | What the Pixelforge API guarantees, and where each guarantee is answered in the code |
| [SYSTEM-DESIGN.md](docs/SYSTEM-DESIGN.md) | Runtime behaviour: components, lifecycles, failure modes, concurrency, trust boundaries |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Code structure, the patterns used, and the ones deliberately not |
| [ERD.md](docs/ERD.md) | Tables, constraints, indexes, and the reasoning behind the non-obvious modelling choices |
| [PLAN.md](docs/PLAN.md) | The build, phase by phase, including what each phase got wrong first |

`PLAN.md` is worth a look if you want to see the mistakes rather than the conclusions. The
money unit was wrong until the documentation was read properly; the first version of the
ordering guard had the race it was meant to prevent; and the reconciler queued the same
resubmission thirty times before a cooldown was added.

---

## Stack

PHP 8.3, Laravel 12, Postgres 16, Redis, Vue 3 with Inertia, Tailwind. Pest for tests,
Larastan at level 6, Pint for style. All of it in Docker, with CI running the same images.

Tests run against **Postgres, not SQLite**, because the guarantees above are Postgres
guarantees — partial unique indexes, the affected-row count of a conditional update, and
the fact that a failed statement aborts its whole transaction unless wrapped in a savepoint.
SQLite would report those tests as passing without exercising them, which is worse than not
having them.
