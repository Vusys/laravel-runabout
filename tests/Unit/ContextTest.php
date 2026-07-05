<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Vusys\Runabout\Context;
use Vusys\Runabout\HttpDriver;

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

    public function test_integer_returns_the_remembered_int(): void
    {
        $ctx = $this->context();
        $ctx->remember('post id', 42);

        $this->assertSame(42, $ctx->integer('post id'));
    }

    public function test_integer_rejects_a_non_int(): void
    {
        $ctx = $this->context();
        $ctx->remember('post id', '42');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "post id" holds string, expected int.');

        $ctx->integer('post id');
    }

    public function test_string_returns_the_remembered_string(): void
    {
        $ctx = $this->context();
        $ctx->remember('name', 'ana');

        $this->assertSame('ana', $ctx->string('name'));
    }

    public function test_string_rejects_a_non_string(): void
    {
        $ctx = $this->context();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "name" holds null, expected string.');

        $ctx->string('name');
    }

    public function test_push_starts_a_list_and_appends_to_it(): void
    {
        $ctx = $this->context();

        $this->assertSame(['ana'], $ctx->push('voters', 'ana'));
        $this->assertSame(['ana', 'ben'], $ctx->push('voters', 'ben'));
        $this->assertSame(['ana', 'ben'], $ctx->list('voters'));
    }

    public function test_list_is_empty_for_a_missing_key(): void
    {
        $this->assertSame([], $this->context()->list('voters'));
    }

    public function test_list_rejects_a_key_holding_something_other_than_a_list(): void
    {
        $ctx = $this->context();
        $ctx->remember('voters', 'ana');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "voters" holds string, expected a list.');

        $ctx->list('voters');
    }

    public function test_push_rejects_a_key_holding_something_other_than_a_list(): void
    {
        $ctx = $this->context();
        $ctx->remember('voters', ['lead' => 'ana']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context key "voters" holds array, expected a list.');

        $ctx->push('voters', 'ben');
    }

    public function test_a_pushed_list_interoperates_with_remember_and_get(): void
    {
        $ctx = $this->context();
        $ctx->remember('voters', ['ana', 'ben']);
        $ctx->push('voters', 'cai');

        $this->assertSame(['ana', 'ben', 'cai'], $ctx->get('voters'));
    }

    public function test_acting_as_requires_an_http_driver(): void
    {
        $ctx = $this->context();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No HTTP driver is bound to this context.');

        $ctx->actingAs(new GenericUser(['id' => 1]), 'ana');
    }

    public function test_as_rejects_an_unknown_actor_when_none_are_registered(): void
    {
        $ctx = $this->contextWithHttp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Register actors with $ctx->actingAs($user, \'ana\')');

        $ctx->as('ana');
    }

    public function test_as_lists_the_known_actors_for_an_unknown_name(): void
    {
        $ctx = $this->contextWithHttp();
        $ctx->actingAs(new GenericUser(['id' => 1]), 'ana');
        $ctx->actingAs(new GenericUser(['id' => 2]), 'ben');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Known actors: ana, ben.');

        $ctx->as('cai');
    }

    public function test_as_returns_the_registered_actor(): void
    {
        $ctx = $this->contextWithHttp();
        $actor = $ctx->actingAs(new GenericUser(['id' => 1]), 'ana');

        $this->assertSame($actor, $ctx->as('ana'));
        $this->assertSame('ana', $actor->name());
    }

    public function test_last_response_requires_a_request_to_have_been_made(): void
    {
        $ctx = $this->contextWithHttp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No actor has made a request yet in this trail.');

        $ctx->lastResponse();
    }

    public function test_an_actor_request_authenticates_and_records_the_last_response(): void
    {
        $authenticated = [];
        $response = new TestResponse(new Response('ok'));

        $ctx = new Context(new Randomizer(new Mt19937(1)), new HttpDriver(
            authenticate: function ($user) use (&$authenticated): void {
                $authenticated[] = $user->getAuthIdentifier();
            },
            json: fn (): TestResponse => $response,
            form: fn (): TestResponse => $response,
        ));

        $sent = $ctx->actingAs(new GenericUser(['id' => 7]), 'ana')->postJson('/anywhere');

        $this->assertSame([7], $authenticated);
        $this->assertSame($response, $sent);
        $this->assertSame($response, $ctx->lastResponse());
    }

    public function test_travel_to_freezes_the_clock_and_travel_back_restores_it(): void
    {
        $ctx = $this->context();

        try {
            $ctx->travelTo('2030-06-15 12:00:00');
            $this->assertSame('2030-06-15 12:00:00', Date::now()->format('Y-m-d H:i:s'));

            $ctx->travel('+2 days');
            $this->assertSame('2030-06-17 12:00:00', Date::now()->format('Y-m-d H:i:s'));

            $ctx->travelBack();
            $this->assertFalse(Date::hasTestNow());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_time_travel_registers_a_single_deferred_unwind(): void
    {
        $ctx = $this->context();

        try {
            $ctx->travelTo('2030-06-15');
            $ctx->travel('+1 hour');

            $deferred = $ctx->drainDeferred();
            $this->assertCount(1, $deferred);

            $deferred[0]();
            $this->assertFalse(Date::hasTestNow());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_forget_removes_a_remembered_value(): void
    {
        $ctx = $this->context();
        $ctx->remember('key', 'value');

        $ctx->forget('key');

        $this->assertFalse($ctx->has('key'));
        $this->assertSame('fallback', $ctx->get('key', 'fallback'));
    }

    public function test_random_int_is_seeded_and_bounded(): void
    {
        $draw = function (): array {
            $ctx = new Context(new Randomizer(new Mt19937(42)));

            return array_map(fn (): int => $ctx->randomInt(10, 20), range(1, 25));
        };

        $first = $draw();

        $this->assertSame($first, $draw());
        $this->assertSame($first, array_filter($first, fn (int $n): bool => $n >= 10 && $n <= 20));
    }

    public function test_randomizer_exposes_the_seeded_source(): void
    {
        $randomizer = new Randomizer(new Mt19937(1));

        $this->assertSame($randomizer, (new Context($randomizer))->randomizer());
    }

    private function context(): Context
    {
        return new Context(new Randomizer(new Mt19937(1)));
    }

    private function contextWithHttp(): Context
    {
        $response = new TestResponse(new Response('ok'));

        return new Context(new Randomizer(new Mt19937(1)), new HttpDriver(
            authenticate: function ($user): void {},
            json: fn (): TestResponse => $response,
            form: fn (): TestResponse => $response,
        ));
    }
}
