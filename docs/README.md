# Documentation

| Document | Covers |
|---|---|
| [PROVIDER-NOTES.md](PROVIDER-NOTES.md) | What the Pixelforge API guarantees, and where each guarantee shows up in the code |
| [SYSTEM-DESIGN.md](SYSTEM-DESIGN.md) | Runtime behaviour: components, the submission and webhook lifecycles, failure modes, concurrency, behaviour under load, trust boundaries |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Code structure: layering, the patterns used on each side of the stack, and the ones deliberately not used |
| [ERD.md](ERD.md) | The data model: tables, columns, constraints, indexes, and the reasoning behind three non-obvious modelling choices |
| [PLAN.md](PLAN.md) | The build plan, phase by phase, with the tests that close each one |

Start with `PROVIDER-NOTES.md` for what the provider guarantees and why the design looks the way it does, `SYSTEM-DESIGN.md` for what the system does, and `ARCHITECTURE.md` for where a change belongs.
