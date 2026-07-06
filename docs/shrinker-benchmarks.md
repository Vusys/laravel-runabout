# Shrinker benchmarks — a toy-CRM corpus

Status: **implemented for S1, S2, S6, S7; S3–S5 specified below.**

Shrinking (see [shrinking-design.md](shrinking-design.md)) only earns its keep on *long* failing trails, and it is only *correct* if the trail it hands back reproduces the same bug and nothing else. Neither property can be judged by a passing test suite alone — you need failing trails whose minimal counterexample you already know, so you can grade the shrinker against a fixed answer.

That is what this corpus is: a fictional Salesforce (`tests/Fixtures/Crm` — `Account`, `Opportunity`, and a `CrmService` with toggleable planted bugs) and a set of journeys, each built so that

- the **canonical order never provokes the bug** (the trigger is an ordering or repeat a once-through declaration can't reach), so only a shuffle catches it and the shrinker always has real work;
- the **minimal counterexample is known**, so the test asserts the exact shrunk trail, not just "shorter";
- the **distractors draw randomness**, so removing one shifts every downstream draw under the old single-stream scheme — which is why the corpus doubles as the acceptance test for seed schema v2.

Each scenario targets a different axis of what makes a trail hard to shrink.

## The axes

1. **Length** — many independent steps; reduce by deletion.
2. **Repeat count** — one step run many times; reduce the count, sometimes to a threshold rather than to one.
3. **Data-decisive draws** — the bug needs a specific drawn value; v2 must keep a surviving execution's draw stable as the trail is rearranged.
4. **Precondition depth** — a shrunk candidate must stay viable (`after()`/`when()` still satisfied) or be rejected, not counted as passing.
5. **Interleave order** — two actors; reduce to the minimal cross-instance pair.
6. **Non-monotonicity** — a step that looks like removable noise (a read) is actually required.
7. **Same-failure identity** — two live bugs; shrinking one must never slip to the other.

## The scenarios

### S1 — roll-up on reopen *(axes 1, 2)* · implemented

`Account.won_amount` should equal the sum of its closed-won opportunities. The planted bug forgets to subtract on reopen. Steps are declared in a non-triggering order (reopen, win, open) and win/reopen no-op when nothing is available, so the canonical order stays green; only a shuffle that lands open → win → reopen fails. **Known minimal: `open opportunity`, `win opportunity`, `reopen opportunity`** (three executions), which the test asserts exactly after shrinking a repeat-heavy trail.

### S2 — largest-open-deal cache *(axis 3)* · implemented · the v2 acceptance test

`Account.largest_open_deal` should track the biggest open opportunity. The bug fails to recompute it when the largest is closed while others remain open — so whether a trail triggers depends on *which open drew the larger amount*, the data and not just the order. **Known minimal: two `open opportunity` and one `close largest opportunity`.** This is the scenario that cannot pass under the old single-stream randomness: dropping a distractor there would re-key the amount draws and the "which is larger" relationship could flip, hiding the bug. Under v2 each surviving open keeps its drawn amount (the run index is the stream key), so the shrunk trail reproduces from its artifact — the test asserts exactly that.

### S3 — round-robin with deactivation *(axis 2, threshold flavour)* · specified

Leads assigned round-robin across reps; deactivating a rep should skip it, but the index isn't recomputed, so after the cursor wraps the dead rep gets a lead. The repeatable "create lead" step must run *enough times to wrap once* — the shrinker must reduce toward the **threshold the failure oracle sets**, not blindly toward one. Proves the loop respects the probe at every candidate rather than assuming count monotonicity.

### S4 — merge on case-colliding email *(axis 3, value/string)* · specified · motivates future value shrinking

Contacts merge when they share a case-insensitive email; the survivor is chosen wrong when emails differ only by case. The sequence minimal is small, but the *data* — two emails that collide only under case-folding — is what makes it fire, and sequence-shrinking alone leaves a trail full of unreadable random emails. This is the standing evidence for when the future value-shrinking hybrid (out of scope for v1) becomes worth building: the minimal-length trail that is still not minimal-*data*.

### S5 — territory reassignment race *(axis 5)* · specified · interleave × shrink

Two managers (`actors()`) edit overlapping territory rules under `interleave()`; a specific cross leaks an account across a boundary. **Minimal: `A: retarget` → `B: retarget`.** This is the seam where two features meet — the replay token carries an instance label and the viability check must respect cross-instance ordering — so it is where shrinker bugs are most likely to hide.

### S6 — stale health cache via a read *(axis 6)* · implemented · the adversarial case

`Account.health_score` is recomputed and marked fresh by `view account`; the bug is that `open opportunity` forgets to invalidate it. The failure needs `view` → `open`: the view marks the cache fresh, then the open moves the true count on without clearing the flag. The adversarial part is that **`view account` only reads** — a shrinker that assumed reads are removable would drop it and wrongly declare the bug gone. Delta debugging against an exact same-failure oracle keeps it, because removing it makes the candidate *pass*, and a passing candidate is rejected. **Known minimal: `view account`, `open opportunity`.** The test asserts the read survives — the concrete demonstration that ddmin with an exact oracle is sound on non-monotonic bugs (the design doc's open worry).

### S7 — two bugs, one trail *(axis 7)* · implemented

Both the S1 roll-up bug and the S6 stale-health bug are live at once, so a long trail can trip either invariant. The test finds a seed whose *unshrunk* failure is the roll-up bug, then asserts that with shrinking on the same seed still reports the roll-up bug and never the health bug — the end-to-end guard that shrinking holds the original `FailureSignature` and does not silently swap the reported defect.

## How the corpus is used

- **Exact-minimal assertions** (S1, S2, S6): because the shrinker is deterministic, the shrunk token list is a stable value; the tests assert it equals the hand-derived core, so any regression that over- or under-shrinks fails loudly.
- **Reproduces-from-artifact** (S2): the shrunk `RUNABOUT_TRAIL` is replayed and must fail the same way — the v2 guarantee end to end.
- **Signature fidelity** (S7): the shrunk failure must name the same invariant as the original.
- **Substrate-first**: S1 and S2 exercise seed-schema-v2 and explicit-order replay before the shrink loop is even consulted (a hand-written token list, replayed, must reproduce), so the prerequisite is validated by artifact, not by argument.

## What is deliberately not here

- **Value shrinking** — minimising drawn data after the sequence is minimal (S4's payoff). Out of scope for v1; the scenario exists to mark the boundary.
- **S3/S5 as code** — specified above; they need `SalesRep`/territory models and an interleaved journey respectively, and are the natural next additions.
