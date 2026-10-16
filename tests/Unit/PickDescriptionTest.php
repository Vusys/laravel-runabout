<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use stdClass;
use Vusys\Runabout\Context;
use Vusys\Runabout\Randomness\Draw;
use Vusys\Runabout\Randomness\StreamDrawSource;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\Fixtures\PostStatus;
use Vusys\Runabout\Trail;

final class PickDescriptionTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string}> */
    public static function options(): iterable
    {
        yield 'saved model' => [new User(['id' => 7]), 'User #7'];
        yield 'unsaved model' => [new User, 'User'];
        yield 'enum' => [PostStatus::Draft, 'PostStatus::Draft'];
        yield 'string' => ['draft', '"draft"'];
        yield 'long string' => [str_repeat('a', 25), '"'.str_repeat('a', 23).'…"'];
        yield '24-char string' => [str_repeat('a', 24), '"'.str_repeat('a', 24).'"'];
        yield 'int' => [3, '3'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'object' => [new stdClass, null];
        yield 'array' => [[1], null];
    }

    #[DataProvider('options')]
    public function test_a_pick_records_what_it_picked(mixed $option, ?string $expected): void
    {
        $source = new StreamDrawSource(new Randomizer(new Mt19937(1)));
        (new Context($source))->pick([$option]);

        $this->assertSame($expected, $source->draws()[0]->picked);
    }

    public function test_random_int_records_no_pick(): void
    {
        $source = new StreamDrawSource(new Randomizer(new Mt19937(1)));
        (new Context($source))->randomInt(1, 5);

        $this->assertNull($source->draws()[0]->picked);
    }

    public function test_the_trail_listing_names_what_each_pinned_pick_picked(): void
    {
        $trail = new Trail(seed: 1, mode: 'replayed');
        $trail->record(null, 'invoice', 1);
        $trail->attachDraws([new Draw(0, 3, 1, 'Order #140'), new Draw(1, 5, 1)], opaque: false, forced: true);

        $this->assertSame('>  1. invoice [drew 1 → Order #140, 1]', $trail->describe());
        $this->assertSame([[null, 'invoice', 1, [1, 1]]], $trail->artifact()['steps'], 'the artifact carries values only.');
    }
}
