<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use Illuminate\Auth\GenericUser;
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
