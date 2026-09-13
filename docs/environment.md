# Environment variables

Runabout has no config file. Runtime behaviour is controlled fluently on the runner (see [Execution modes](execution-modes.md)) or through these environment variables — handy in CI, or for replaying a failure without editing the test.

| Variable | Effect |
|---|---|
| `RUNABOUT_SEED=923206350` | Replay one exact shuffled trail. Every failure message prints this line for you. |
| `RUNABOUT_TRAIL='{"seed":...,"steps":[...]}'` | Replay one exact trail from an artifact (a seed plus an ordered token list), including a shrunk or repeat-heavy trail a bare seed cannot reproduce. `RUNABOUT_TRAIL=@path.json` reads it from a file. Printed for you under every shrunk failure. |
| `RUNABOUT_SHRINK=0` | Turn off automatic shrinking of failing trails. |
| `RUNABOUT_SHRINK_BUDGET=200` | Cap how many candidate replays each shrink pass may run (default 200). Length and value shrinking get this budget each, not one between them. |
| `RUNABOUT_RANDOMIZE=1` | Explore fresh random seeds instead of the stable derived ones. Meant for a nightly CI job that hunts orderings the fixed seeds never visit; any failure it finds prints its seed, so it replays exactly. |
| `RUNABOUT_VERBOSE=1` | Print every completed trail to stderr as it runs. |
| `RUNABOUT_COVERAGE=1` | Print an aggregate coverage summary to stderr when a run finishes: executions per step, distinct orderings, and the step-pair orderings no trail explored. |

By default seeds are derived deterministically from the journey class and trail index, so ordinary CI runs are stable from commit to commit. See [Reproducing failures](reproducing-failures.md) for how `RUNABOUT_SEED` and `RUNABOUT_TRAIL` differ, and [Trails & coverage](observability.md) for what the verbose and coverage output mean.

## Precedence

A run has exactly one mode. When more than one is configured — in code, in the environment, or both — they resolve in this order, and the first match wins:

| | Mode | Set by |
|---|---|---|
| 1 | Exhaustive | `exhaustive()` |
| 2 | Replay an artifact | `trail()`, else `RUNABOUT_TRAIL` |
| 3 | Replay a seed | `seed()`, else `RUNABOUT_SEED` |
| 4 | Canonical + shuffles | `shuffles()`, or nothing at all |

Two consequences are worth internalising:

- **Code beats the environment within a tier, but a higher tier beats both.** `RUNABOUT_SEED` does nothing to a test that calls `exhaustive()` or `trail()` — not because the variable was ignored, but because a higher-precedence mode was already chosen.
- **Replaying runs one trail.** Both replay modes execute a single trail, not the canonical order followed by shuffles. That's the point — you asked for one specific execution.

`RUNABOUT_RANDOMIZE` is not a mode; it changes how seeds are *derived* for tier 4, and has no effect when a replay tier is active (a replay's seed comes from the seed or artifact you supplied).

## How the flags are read

`RUNABOUT_SHRINK` is an opt-**out**: only the exact string `0` disables shrinking, because shrinking a failure is the default worth having. Every other flag is an opt-**in**, where unset, empty, and `0` all read as off — so `RUNABOUT_VERBOSE=0` is off, and any other non-empty value is on.

`RUNABOUT_SEED` accepts any numeric string. `RUNABOUT_SHRINK_BUDGET` accepts positive integers only; anything else falls back to the default of 200 rather than erroring.
