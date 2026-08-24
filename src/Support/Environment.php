<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

/**
 * The RUNABOUT_* environment contract in one place (documented at
 * docs/environment.md). Every variable a run responds to is read here, so the
 * contract can be read — and changed — without hunting through the executor.
 *
 * Note the deliberate asymmetry on RUNABOUT_SHRINK: it is an opt-*out* (only
 * the exact string "0" disables shrinking, because shrinking a failure is the
 * default), while every other flag is an opt-*in* through flag(), where unset,
 * empty, and "0" all read as off. That difference is behaviour existing runs
 * depend on, so it is preserved here rather than smoothed over.
 */
final class Environment
{
    private const int DEFAULT_SHRINK_BUDGET = 200;

    /** Whether a failing trail should be shrunk. Opt-out: only "0" turns it off. */
    public static function shrinkingEnabled(): bool
    {
        return getenv('RUNABOUT_SHRINK') !== '0';
    }

    /** Maximum candidate replays per shrink pass. */
    public static function shrinkBudget(): int
    {
        $budget = getenv('RUNABOUT_SHRINK_BUDGET');

        return is_string($budget) && ctype_digit($budget) && (int) $budget > 0
            ? (int) $budget
            : self::DEFAULT_SHRINK_BUDGET;
    }

    /** Whether to collect and print a trail coverage summary. */
    public static function coverageEnabled(): bool
    {
        return self::flag('RUNABOUT_COVERAGE');
    }

    /** Whether to print every completed trail to STDERR. */
    public static function verboseEnabled(): bool
    {
        return self::flag('RUNABOUT_VERBOSE');
    }

    /** Whether to explore fresh random seeds instead of the deterministic derived ones. */
    public static function randomizeEnabled(): bool
    {
        return self::flag('RUNABOUT_RANDOMIZE');
    }

    /** A seed to replay a single shuffled trail with, if one is set. */
    public static function seed(): ?int
    {
        $seed = getenv('RUNABOUT_SEED');

        return is_string($seed) && is_numeric($seed) ? (int) $seed : null;
    }

    /** A raw trail artifact to replay (JSON, or "@path"), if one is set. */
    public static function trail(): ?string
    {
        $raw = getenv('RUNABOUT_TRAIL');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /** An opt-in flag: unset, empty, and "0" all read as off. */
    private static function flag(string $name): bool
    {
        return ! in_array(getenv($name), [false, '', '0'], true);
    }
}
