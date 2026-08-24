<?php

declare(strict_types=1);

namespace Vusys\Runabout\Randomness;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seed schema v2: how a trail's single seed derives every stream inside it.
 *
 * The trail seed alone drives the picker (order decisions), and each execution
 * gets its own stream keyed by the execution's identity — instance label, step
 * name, run index — rather than by its position in the trail. That is the whole
 * point of the schema: the nth run of a step draws the same values wherever it
 * lands, so a shrunk, reordered, or replayed trail reproduces a failure
 * verbatim. Teardowns and baseline invariant checks run outside any execution,
 * so they get dedicated trail-start and trail-end streams of their own.
 *
 * Every derivation is a pure function of the seed and the execution's identity,
 * which is what makes the schema testable in isolation (tests/Unit/SeedSchemaTest.php)
 * and what pins it as a compatibility surface: changing any string built here
 * changes which values every existing artifact replays, so it is a schema
 * version bump, not a refactor.
 */
final class SeedSchema
{
    /**
     * The picker stream for a trail: derived from the trail seed alone, so
     * order decisions live here and depend on nothing else.
     */
    public static function picker(int $seed): Randomizer
    {
        return self::stream($seed.'|__picker__');
    }

    /**
     * One execution's data stream: derived from the trail seed plus the
     * execution's identity (instance label, step name, run index), so the nth
     * run of a step draws the same values wherever it lands.
     */
    public static function execution(int $seed, ?string $label, string $step, int $run): Randomizer
    {
        return self::stream(sprintf('%d|%s|%s|%d', $seed, $label ?? '', $step, $run));
    }

    /** The trail-end stream that teardowns draw from. */
    public static function teardown(int $seed, ?string $label): Randomizer
    {
        return self::stream(sprintf('%d|__teardown__|%s', $seed, $label ?? ''));
    }

    /** The trail-start stream that baseline invariant checks draw from. */
    public static function baseline(int $seed, ?string $label): Randomizer
    {
        return self::stream(sprintf('%d|__baseline__|%s', $seed, $label ?? ''));
    }

    /**
     * The draw source for an execution: a recording stream source normally, or
     * a scripted source (forced values, stream fallback) during value shrinking.
     *
     * @param  array<int, int>|null  $forcedDraws
     */
    public static function source(int $seed, ?string $label, string $step, int $run, ?array $forcedDraws): DrawSource
    {
        $stream = self::execution($seed, $label, $step, $run);

        return $forcedDraws === null
            ? new StreamDrawSource($stream)
            : new ScriptedDrawSource($forcedDraws, $stream);
    }

    private static function stream(string $key): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($key)));
    }
}
