# Entity relationship model

The data model is four tables. Most of the correctness guarantees this application makes are enforced here rather than in application code, so this document explains what each table holds, which constraint protects what, and why three of the modelling choices are not the obvious ones.

[ARCHITECTURE.md](ARCHITECTURE.md) covers how the code around this model is organised, and [SYSTEM-DESIGN.md](SYSTEM-DESIGN.md) covers how it behaves at runtime.

---

## Diagram

```mermaid
erDiagram
    USERS ||--o{ GENERATIONS : requests
    USERS ||--o{ LEDGER_ENTRIES : owns
    GENERATIONS ||--o{ LEDGER_ENTRIES : "settled by"
    GENERATIONS |o..o{ WEBHOOK_EVENTS : "matched by provider id, no foreign key"

    USERS {
        bigint id PK
        string name
        string email UK
        bigint balance_micros "cached sum of the ledger"
        timestamptz created_at
        timestamptz updated_at
    }

    GENERATIONS {
        uuid id PK "minted before the provider is called"
        bigint user_id FK
        uuid idempotency_key UK "sent with the submission"
        text provider_generation_id UK "null until the provider responds"
        text prompt
        text size
        text quality
        jsonb reference_image_urls
        text status "creating, queued, processing, succeeded, failed"
        smallint status_rank "ordering guard for unordered delivery"
        bigint estimated_micros "shown to the user before submission"
        jsonb estimated_usage "token counts the estimate assumed"
        bigint provider_cost_micros "null until a successful outcome"
        bigint charged_micros "provider cost plus markup"
        jsonb usage "token counts the provider reported"
        text image_url
        text failure_reason
        timestamptz submitted_at
        timestamptz completed_at
        timestamptz created_at
        timestamptz updated_at
    }

    LEDGER_ENTRIES {
        bigint id PK
        bigint user_id FK
        uuid generation_id FK "null for a credit purchase"
        text kind "hold, hold_release, charge, topup"
        bigint amount_micros "signed; negative leaves the user"
        timestamptz created_at
    }

    WEBHOOK_EVENTS {
        bigint id PK
        text provider_event_id UK "deduplication key"
        text event_type
        text provider_generation_id "nullable; no foreign key"
        jsonb payload "raw delivery, retained"
        text outcome "applied, ignored_stale, orphaned"
        timestamptz received_at
        timestamptz processed_at
    }
```

---

## Entities

### users

Standard authentication, plus a single additional column.

`balance_micros` is a **cache**, not the source of truth. The authoritative balance is `SUM(ledger_entries.amount_micros)` for that user. The cache is maintained in the same transaction as the entry that changes it, and an artisan command asserts the two agree. Keeping a cached column avoids aggregating the whole ledger on every page load; keeping the ledger authoritative means a disagreement is detectable rather than silent.

### generations

One row per request a user makes, created **before** the provider is contacted. A generation that is never accepted still has a row, which is what gives a webhook somewhere to land and the reconciler something to find.

Four columns deserve explanation:

| Column | Why it exists |
|---|---|
| `idempotency_key` | Sent with the submission. A request that times out has an unknown outcome, not a failed one; the key makes retrying it safe rather than a second generation the user pays for. |
| `status_rank` | A denormalised copy of the status ordering. Webhook delivery is unordered, and comparing ranks in the `UPDATE` makes "only move forward" a condition the database evaluates rather than a rule the application remembers. |
| `estimated_micros` and `charged_micros` | Both are kept. The estimate is what the user agreed to and the charge is what they paid; overwriting one with the other would destroy the ability to explain a difference. |
| `provider_cost_micros` | What the provider billed, held separately from what the user was charged, so profit is a stored subtraction and reconciliation against the provider's usage reporting is a direct comparison. |

`usage` and `estimated_usage` retain the token counts behind each figure, so any price can be re-derived from the inputs that produced it.

### ledger_entries

Append-only. Nothing is ever updated or deleted; a correction is a new compensating entry.

| Kind | Sign | Written when |
|---|---|---|
| `topup` | positive | A user adds credit |
| `hold` | negative | A generation is submitted, reserving the estimate |
| `hold_release` | positive | The generation reaches an outcome, releasing the reservation |
| `charge` | negative | The generation succeeds, for the actual cost |

A successful generation therefore produces a `hold`, a `hold_release` and a `charge`; a failed one produces a `hold` and a `hold_release`, netting to zero. This is the authorise-and-capture pattern used for card payments, and it is why a failed generation genuinely costs nothing rather than costing something that is later refunded.

### webhook_events

A transactional inbox. Every delivery that passes signature verification is recorded before anything acts on it, and the unique constraint on `provider_event_id` is what makes a repeated delivery a no-op.

`payload` keeps the raw delivery indefinitely. When a figure is questioned later, the provider's own words about that generation are still on file.

`outcome` records what processing decided — `applied`, `ignored_stale` for an event superseded by a later one, or `orphaned` for an event whose generation could not yet be identified.

Deliveries that fail signature verification are rejected and **not** stored. Storing unauthenticated input invites a writable table reachable by anyone who knows the endpoint.

---

## Indexes

| Index | Kind | Serves |
|---|---|---|
| `webhook_events (provider_event_id)` | unique | Deduplication. The failed insert on a repeated delivery is the mechanism, not an error to handle. |
| `ledger_entries (generation_id, kind) WHERE generation_id IS NOT NULL` | partial unique | A second charge for a generation is refused by the database. Partial because credit purchases have no generation. |
| `generations (provider_generation_id)` | unique | Webhook lookup, and prevents a retried submission registering twice. |
| `generations (idempotency_key)` | unique | One submission per key. |
| `generations (user_id, created_at DESC)` | composite | The history view. Ordered so one index serves both the filter and the sort. |
| `generations (submitted_at) WHERE status IN ('creating','queued','processing')` | partial | The reconciler. Rows leave the index when they reach an outcome, so it stays the size of the in-flight set regardless of how large the table grows. |
| `ledger_entries (user_id, created_at)` | composite | Balance derivation and the per-user daily grouping in the operator report. |

The partial index on in-flight generations is the one that changes behaviour at scale. A plain index on `status` would grow with the table and spend most of its size on rows that will never be polled again.

---

## Modelling decisions

### webhook_events has no foreign key to generations

A webhook can arrive before the generation row carries a provider identifier — the provider can dispatch its first event before the response to the submission has been written. A foreign key would reject exactly the event that most needs keeping, and the endpoint would have to answer with an error, which the provider would treat as a reason to redeliver indefinitely.

The association is therefore resolved in application code, and an event that cannot yet be matched is stored with `outcome = 'orphaned'` and retried by the reconciler once the identifier is known.

### Money is stored as bigint micro-dollars, not numeric

Postgres `NUMERIC` would hold these values exactly. Integers were chosen because the guarantee then extends beyond the database: a `bigint` arrives in PHP as an `int` and cannot be silently coerced into a float by an arithmetic operation, whereas a `NUMERIC` arrives as a string and the first careless `+` turns it into one.

One micro-dollar is a ten-thousandth of a cent, which is finer than any amount the provider reports, so nothing is lost by the choice.

### status and status_rank are both stored

This is deliberate denormalisation. `status` is what the application and the interface read; `status_rank` exists so the transition guard can be expressed as a comparison inside the same statement that performs the update, rather than as a read followed by a decision followed by a write. The two are set together in one place, by the state machine in `App\Domain\GenerationStatus`.

---

## A generation, row by row

**A generation that succeeds**

| Step | Table | Effect |
|---|---|---|
| User submits | `generations` | Row inserted: `status = creating`, `estimated_micros` set |
| | `ledger_entries` | `hold` for the estimate |
| Provider accepts | `generations` | `provider_generation_id` and `submitted_at` set, `status = queued` |
| `processing` webhook | `webhook_events` | Stored, `outcome = applied` |
| | `generations` | `status = processing` |
| `succeeded` webhook | `webhook_events` | Stored, `outcome = applied` |
| | `generations` | `status = succeeded`, costs and `image_url` recorded |
| | `ledger_entries` | `hold_release`, then `charge` for the actual cost |
| Same webhook redelivered | `webhook_events` | Insert refused by the unique constraint. Nothing else happens. |

**A generation that fails**

Identical until the outcome, which writes `status = failed` and a `failure_reason`, releases the hold, and writes **no** `charge`. The ledger nets to zero and the user has paid nothing.
