# Introduction

## The problem

Complex journeys — where each step mutates shared state — are where production bugs live, and they recur as a handful of order-dependent patterns:

- **Replace logic that drifts**: an "update" implemented as delete-then-insert that only balances in the order the developer imagined.
- **Counter and quota drift**: denormalised counters incremented and decremented by different code paths that only agree on the happy path.
- **Cache and aggregate staleness**: recomputation that fires on one mutation path but not another.
- **Unenforced state machines**: a status column guarded by scattered `if`s, where one missing guard lets a row jump between states no edge connects.
- **Soft-delete leaks**: a parent is trashed, its children live on.
- **Cross-tenant / cross-actor leakage**: each participant's behaviour is individually correct, but their interaction leaks state — isolation enforced by query convention rather than by the database.

A feature test encodes one ordering — the golden path — and never explores another. Runabout explores the others for you, deterministically.

## How it works

You define a **Journey** as a set of **Steps**. Each step has an action, assertions, and constraints. Runabout runs the steps in the declared order once (so your journey is also just a readable feature test), then in N seeded random orders, picking at every tick among the steps whose preconditions are currently satisfied. After *every* step it checks the journey's **Invariants** — things that must hold no matter what just happened.

Each execution's ordered step list is its **Trail**; all randomness flows from one integer seed, so any trail replays exactly. When a trail fails, Runabout automatically **shrinks** it — minimising length, then the drawn values — to the smallest reproduction, and leads the failure output with that.

That is the whole model. The rest of these docs fill it in: how to [define steps](steps.md) and their constraints, the [context](context.md) threaded through a trail, the [invariants](invariants.md) checked along the way, the [execution modes](execution-modes.md) that decide how orderings are sampled, and how to [reproduce a failure](reproducing-failures.md) once one is found.

Read [Core concepts](concepts.md) next for the vocabulary, or jump straight to the [Quick start](quick-start.md).
