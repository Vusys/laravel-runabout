# Value shrinking — design

Status: **implemented (phase 1).** Int and pick value shrinking ship as designed below: a `DrawSource` layer (`StreamDrawSource` records, `ScriptedDrawSource` forces + falls back), a `ValueShrinker` binary-searching each draw toward its domain's low end gated by the same `FailureSignature`, forced draws in the `RUNABOUT_TRAIL` artifact's optional fourth element, and the failure output annotated with concrete drawn values. Validated with exact-value assertions on CRM S2 (`{suffix→1, amounts→51,50}`) and the forum revote (`pick`s → the first options). Phase 2 (string generators, S4) stays deferred. The design below is what was built.

Sequence shrinking shipped ([shrinking-design.md](shrinking-design.md)): a failing trail is minimised to the shortest *subsequence* that reproduces the same failure. This doc is the other half the benchmark corpus already gestures at ([S4](shrinker-benchmarks.md#s4--merge-on-case-colliding-email-axis-3-valuestring--specified--motivates-future-value-shrinking)) — minimising the *drawn values* inside a trail once its length is minimal, so the counterexample is not just short but concrete: not "open two deals of random amounts and close the larger", but "open a deal of 51, open a deal of 50, close the larger".

## The gap

A shrunk trail today is minimal in *length* and opaque in *data*. Take S2 (`MaxDealCacheJourney`), whose `open opportunity` step draws twice (`tests/Fixtures/Crm/MaxDealCacheJourney.php:43`):

```php
$service->openOpportunity($this->account, 'Deal '.$ctx->randomInt(1, 9999), $ctx->randomInt(50, 500))
```

Sequence shrinking lands the minimal three-token trail — `open`, `open`, `close largest` — but the amounts are still whatever the seed drew (say `347` and `189`) and the names are noise like `Deal 8123`. The developer reading the failure has to reverse-engineer *which* facts about those numbers matter. Two of the three draws are pure distraction; one is load-bearing and only at a boundary. Value shrinking is what turns the numbers into the explanation.

This is the classic property-based-testing move: generators shrink their *outputs*, not just the sequence of operations. QuickCheck, Hypothesis, and fast-check all do it. It is the last mechanical gap between Runabout and that lineage.

## Why this reopens a door the sequence shrinker closed

The sequence shrinker deliberately **rejected** recording-and-replaying draws (Design A in [shrinking-design.md](shrinking-design.md#design-a-considered-and-rejected-record-and-replay-draws)) in favour of position-independent streams (seed schema v2), because for *reordering* you get draw-stability for free from the stream key `hash(seed, label, step, run)` (`JourneyRunner.php:433`) and the replay artifact stays a readable token list.

Value shrinking cannot get its property for free the same way, because it must do the one thing v2 makes impossible by construction: **change a drawn value while holding everything else fixed.** Under v2 a draw is a pure function of its token, so the only lever on a value is the seed — and moving the seed re-keys *every* draw in the trail. So value shrinking necessarily reintroduces a bounded form of Design A: record what each execution drew, and be able to force specific values back.

The reconciliation — and the reason this does not undo v2 — is that recording/override is an **additive layer, used only during the value-shrink pass**, sitting on top of the v2 streams rather than replacing them:

- Sequence replay stays a pure token list. Nothing about ordering shrinking changes.
- The v2 stream remains the substrate. An execution with no pinned values draws from its keyed stream exactly as today.
- The three objections that killed Design A as a *substrate* are answered by keeping it a *layer* (below).

## Design

Three pieces, mirroring the sequence shrinker's shape (a recorder, an override source, and a budgeted ddmin sweep gated by the existing `FailureSignature`).

### 1. The draw ledger (recording)

`Context::pick()` and `Context::randomInt()` already carry each draw's domain — `pick` is an index draw over `[0, count-1]`, `randomInt` over `[min, max]` (`Context.php:171`–`185`). Wrap the draw path so each execution records an ordered list of `Draw{kind, min, max, value}`. Recording is off on ordinary runs and switched on for the one baseline replay that opens the value pass, so it costs nothing in the common path.

`$ctx->randomizer()` (`Context.php:187`) hands out the raw, `final` `Randomizer`; draws made through it are invisible to the recorder. Rather than let that silently mis-shrink (Design A's hole), an execution that touches the raw randomizer is **marked value-opaque and skipped** by the value shrinker. Its draws still replay verbatim from the v2 stream — they simply aren't candidates for minimisation. This keeps the escape hatch honest: it costs you value shrinking on that step, nothing more.

### 2. `DrawSource` (override)

Replace the bare `Randomizer` field in `Context` with a `DrawSource` with two implementations:

- **`StreamDrawSource`** — wraps the keyed `Mt19937` (today's behaviour) and records into the ledger. This is what the runner installs per execution (`JourneyRunner.php:433`, `Context::useStream()` at `Context.php:55`).
- **`ScriptedDrawSource`** — replays a fixed list of forced values *for one token*, each clamped to the draw's declared domain so a forced value can never fall out of range, with **fallback to the keyed stream** for any draw beyond the script. The fallback is what preserves position-independence: unscripted draws still derive from `(seed, token)`, so scripting execution A's values never perturbs B.

`executionStream()` becomes "build a `DrawSource` for this token": a recording stream source on a normal run; a scripted source seeded with the candidate ledger during the value pass.

### 3. The value-shrink sweep (search)

Runs **after** sequence shrinking, over the already-minimal token list — fewer executions means fewer draws to touch, which is why length goes first.

1. Replay the minimal trail once with recording on → the baseline ledger per surviving token.
2. Greedily, one draw at a time, shrink its value toward the canonical-simplest end of its domain:
   - **ints** → halve toward `min` (binary search on the low end);
   - **pick indices** → toward `0`, i.e. the *first* option — adopting the QuickCheck convention that earlier = simpler, so authors order `pick` lists simplest-first and get readable minimal values for free.
3. Each candidate rebuilds the full trail with *every* draw forced to its recorded value except the one under test, replays through scripted sources, and is kept iff the failure still carries the same `FailureSignature` (`FailureSignature.php`, reused verbatim — no new soundness surface).
4. Budget-capped, deterministic, swept to a fixpoint. Structurally identical to `TrailShrinker` (`TrailShrinker.php`), over values instead of positions.

The same exact-oracle discipline that lets the sequence shrinker keep a required read (S6) is what lets the value shrinker **stop at a boundary**: if reducing `51`→`50` makes the candidate pass, the probe rejects it and `51` stands.

## Artifact and reporting

- **Artifact.** `TrailToken::toArray()` (`TrailToken.php:33`) grows an optional fourth element: `[label, step, run, [forcedDraws...]]`. Absent ⇒ pure stream, so every existing `RUNABOUT_TRAIL` stays valid; `runTokens()` (`JourneyRunner.php:104`) builds scripted sources from the override lists. This is the layer answer to Design A's "artifact is an opaque value dump": values appear in the artifact *only when pinned by shrinking*, and a shrunk artifact of a handful of small ints is still readable.
- **Reporting — the payoff.** The failure output annotates each execution with its concrete drawn values (`open Deal 1 (amount 51)`), so the minimal counterexample reads like a hand-written test instead of a seed to go re-derive.

## Validation — on the fixtures we already have

Both can be graded with exact-minimal assertions in the style of the existing corpus, and **neither needs new models or a string generator**, so phase 1 ships fully validated on its own.

- **CRM S2 (`MaxDealCacheJourney`) — the standout, covering `randomInt`.** It exercises both behaviours in one journey:
  - the name-suffix draw `randomInt(1, 9999)` is pure noise the bug never reads, so it shrinks to its floor: `Deal 1`;
  - the amount draw `randomInt(50, 500)` is decisive with a boundary — the bug (`CrmService.php:114`) only bites when the closed deal was larger than a surviving open one, so both amounts drive toward `min` but stop at `{51, 50}` the moment `{50, 50}` (equal → `orderBy('id')` tie-break → cache agrees) makes the candidate pass.

  The assertion is the shrunk ledger `{suffix → 1, amounts → 51, 50}` — a stable value because the search is deterministic, gradeable exactly like the S1/S2/S6 minimal-trail assertions.

- **Forum `cast vote` (`PostLifecycleJourney`) — covering `pick`.** The `$buggyRevote` score-drift bug (`PostService.php:20`) needs the *same* voter voting twice with values that differ. The voter `pick(['ana','ben','cai'])` shrinks to index 0 (`ana`, `ana`) — which also happens to satisfy the "same voter" precondition — while the value `pick([1,-1])` stops at the pair that keeps the score drifting. Running both fixtures covers both of `Context`'s structured draw funnels.

## Scope boundary — string-decisive bugs (S4)

Value shrinking as designed minimises `int` and `pick` draws. It does **not** close S4 (`shrinker-benchmarks.md`), where the bug fires on two emails that collide only under case-folding and the value is a freshly *generated* string with no declared domain to shrink toward. A string's "smaller" direction (shorter, lowercase, ASCII) is richer than an int's, and reaching it needs structured generators on `Context` — a `$ctx->word()`/`$ctx->email()` that emit recordable, shrinkable draws carrying their own simplest-value — which is a larger API surface than the override layer above.

That is **phase 2**, deliberately deferred. Phase 1 (this doc) closes the readability gap for the numeric and choice draws that dominate real journeys and needs no new authoring API; S4 stays the standing marker for when generators are worth building.

## Cost and risk

- **Draw count is not fixed across candidates.** A smaller value can send a step down a branch that draws more or fewer times. The scripted source handles this by construction — script what the ledger has, fall back to the stream for anything extra — and a candidate whose control flow diverges enough to change the `FailureSignature` is simply "does not reproduce" and dropped.
- **Budget.** Every candidate is a full trail replay, as in sequence shrinking; the value pass shares the same budget discipline (`RUNABOUT_SHRINK_BUDGET`) and shrinks greedily, one draw at a time, low-end-first.
- **No re-keying.** Because overrides are keyed by token and unscripted draws fall back to the token's own stream, the value pass never perturbs the position-independence v2 bought — it only pins, per token, what the ledger recorded.
