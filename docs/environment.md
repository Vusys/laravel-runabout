# Environment variables

Runabout has no config file. Runtime behaviour is controlled fluently on the runner (see [Execution modes](execution-modes.md)) or through these environment variables — handy in CI, or for replaying a failure without editing the test.

| Variable | Effect |
|---|---|
| `RUNABOUT_SEED=923206350` | Replay one exact shuffled trail. Every failure message prints this line for you. |
| `RUNABOUT_TRAIL='{"seed":...,"steps":[...]}'` | Replay one exact trail from an artifact (a seed plus an ordered token list), including a shrunk or repeat-heavy trail a bare seed cannot reproduce. `RUNABOUT_TRAIL=@path.json` reads it from a file. Printed for you under every shrunk failure. |
| `RUNABOUT_SHRINK=0` | Turn off automatic shrinking of failing trails. |
| `RUNABOUT_SHRINK_BUDGET=200` | Cap how many candidate replays the shrinker may run (default 200). |
| `RUNABOUT_RANDOMIZE=1` | Explore fresh random seeds instead of the stable derived ones. Meant for a nightly CI job that hunts orderings the fixed seeds never visit; any failure it finds prints its seed, so it replays exactly. |
| `RUNABOUT_VERBOSE=1` | Print every completed trail to stderr as it runs. |
| `RUNABOUT_COVERAGE=1` | Print an aggregate coverage summary to stderr when a run finishes: executions per step, distinct orderings, and the step-pair orderings no trail explored. |

By default seeds are derived deterministically from the journey class and trail index, so ordinary CI runs are stable from commit to commit. See [Reproducing failures](reproducing-failures.md) for how `RUNABOUT_SEED` and `RUNABOUT_TRAIL` differ, and [Trails & coverage](observability.md) for what the verbose and coverage output mean.
