<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;

/** Mix into a Laravel/Testbench TestCase to run journeys inside tests. */
trait RunsJourneys
{
    /** @param class-string<Journey>|Journey $journey */
    protected function journey(string|Journey $journey): PendingJourney
    {
        $journey = is_string($journey) ? new $journey : $journey;

        return new PendingJourney($journey, $this->wrapTrail(...), $this->journeyHttpDriver());
    }

    /**
     * Adapts this test case's HTTP methods (Laravel's MakesHttpRequests) so
     * actors inside steps can make authenticated requests through it.
     */
    protected function journeyHttpDriver(): HttpDriver
    {
        return new HttpDriver(
            authenticate: function (Authenticatable $user): void {
                $this->actingAs($user);
            },
            json: fn (string $method, string $uri, array $data, array $headers): TestResponse => $this->json($method, $uri, $data, $headers),
            form: fn (string $method, string $uri, array $data, array $headers): TestResponse => match ($method) {
                'GET' => $this->get($uri, $headers),
                'POST' => $this->post($uri, $data, $headers),
                'PUT' => $this->put($uri, $data, $headers),
                'PATCH' => $this->patch($uri, $data, $headers),
                'DELETE' => $this->delete($uri, $data, $headers),
                default => throw new InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method)),
            },
        );
    }

    /**
     * Each trail runs against fresh database state: by default a transaction
     * on the default connection, rolled back afterwards. Override for apps
     * that need truncation or multiple connections.
     */
    protected function wrapTrail(Closure $trail): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $trail();
        } finally {
            $connection->rollBack();
        }
    }
}
