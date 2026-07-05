<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;
use Vusys\Runabout\Context;

final class ContextTest extends TestCase
{
    public function test_instance_returns_the_remembered_value_typed(): void
    {
        $ctx = $this->context();
        $object = new stdClass;
        $ctx->remember('thing', $object);

        $this->assertSame($object, $ctx->instance('thing', stdClass::class));
    }

    public function test_instance_rejects_a_missing_key(): void
    {
        $ctx = $this->context();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "thing" holds null, expected stdClass.');

        $ctx->instance('thing', stdClass::class);
    }

    public function test_instance_rejects_a_value_of_the_wrong_class(): void
    {
        $ctx = $this->context();
        $ctx->remember('thing', 'just a string');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "thing" holds string, expected stdClass.');

        $ctx->instance('thing', stdClass::class);
    }

    private function context(): Context
    {
        return new Context(new Randomizer(new Mt19937(1)));
    }
}
