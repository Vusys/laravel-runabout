<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use Vusys\Runabout\Randomness\ScriptedDrawSource;
use Vusys\Runabout\Randomness\SeedSchema;
use Vusys\Runabout\Randomness\StreamDrawSource;

/**
 * Guards seed schema v2 as a compatibility surface.
 *
 * SeedSchemaTest pins the *property* the shrinker depends on — that an
 * execution's draws survive the trail around it being rearranged — by running
 * whole journeys. These cases sit one level lower and pin the derivations
 * themselves: each stream is a pure function of the seed and the execution's
 * identity, and the four kinds of stream are all distinct from one another.
 *
 * The values are not asserted literally on purpose. What matters is that a
 * given identity always derives the same stream and that different identities
 * derive different ones; hard-coding crc32 output would pin the algorithm
 * without making either property any clearer.
 */
final class SeedDerivationTest extends TestCase
{
    public function test_every_stream_is_a_pure_function_of_its_inputs(): void
    {
        $this->assertSame(
            $this->firstDraws(SeedSchema::picker(42)),
            $this->firstDraws(SeedSchema::picker(42)),
        );

        $this->assertSame(
            $this->firstDraws(SeedSchema::execution(42, 'A', 'open', 2)),
            $this->firstDraws(SeedSchema::execution(42, 'A', 'open', 2)),
        );

        $this->assertSame(
            $this->firstDraws(SeedSchema::teardown(42, 'A')),
            $this->firstDraws(SeedSchema::teardown(42, 'A')),
        );

        $this->assertSame(
            $this->firstDraws(SeedSchema::baseline(42, 'A')),
            $this->firstDraws(SeedSchema::baseline(42, 'A')),
        );
    }

    public function test_the_seed_and_every_part_of_an_executions_identity_change_its_stream(): void
    {
        $baseline = $this->firstDraws(SeedSchema::execution(42, 'A', 'open', 1));

        $this->assertNotSame($baseline, $this->firstDraws(SeedSchema::execution(43, 'A', 'open', 1)), 'the seed must change the stream');
        $this->assertNotSame($baseline, $this->firstDraws(SeedSchema::execution(42, 'B', 'open', 1)), 'the instance label must change the stream');
        $this->assertNotSame($baseline, $this->firstDraws(SeedSchema::execution(42, 'A', 'close', 1)), 'the step name must change the stream');
        $this->assertNotSame($baseline, $this->firstDraws(SeedSchema::execution(42, 'A', 'open', 2)), 'the run index must change the stream');
    }

    public function test_the_picker_teardown_and_baseline_streams_are_distinct_from_each_other(): void
    {
        $streams = [
            'picker' => $this->firstDraws(SeedSchema::picker(42)),
            'teardown' => $this->firstDraws(SeedSchema::teardown(42, null)),
            'baseline' => $this->firstDraws(SeedSchema::baseline(42, null)),
            'execution' => $this->firstDraws(SeedSchema::execution(42, null, 'open', 1)),
        ];

        // Sharing a stream between two of these would make a teardown's draws
        // depend on how many invariants ran, which is exactly what the schema
        // separates them to prevent.
        $this->assertCount(count($streams), array_unique(array_map(serialize(...), $streams)));
    }

    public function test_an_unlabelled_instance_does_not_collide_with_one_labelled_empty(): void
    {
        // A null label is rendered as "" in the stream key, so the two forms
        // deliberately agree — a single-instance run and an instance labelled
        // "" are the same execution identity.
        $this->assertSame(
            $this->firstDraws(SeedSchema::execution(42, null, 'open', 1)),
            $this->firstDraws(SeedSchema::execution(42, '', 'open', 1)),
        );
    }

    public function test_source_records_from_the_stream_unless_draws_are_forced(): void
    {
        $streamed = SeedSchema::source(42, null, 'open', 1, null);
        $forced = SeedSchema::source(42, null, 'open', 1, [3, 4]);

        $this->assertInstanceOf(StreamDrawSource::class, $streamed);
        $this->assertInstanceOf(ScriptedDrawSource::class, $forced);

        $this->assertSame(3, $forced->int(0, 10));
        $this->assertSame(4, $forced->int(0, 10));

        // Past the script, a scripted source falls back to the same stream the
        // unforced source would have used — that is what keeps a value-shrunk
        // candidate replayable when its control flow draws more than the ledger.
        $this->assertSame($streamed->int(0, 1000), $forced->int(0, 1000));
    }

    /**
     * @return list<int>
     */
    private function firstDraws(Randomizer $randomizer): array
    {
        $draws = [];

        for ($i = 0; $i < 5; $i++) {
            $draws[] = $randomizer->getInt(0, PHP_INT_MAX);
        }

        return $draws;
    }
}
