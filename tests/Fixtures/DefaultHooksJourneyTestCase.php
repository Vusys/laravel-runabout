<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Closure;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Exposes RunsJourneys' default hooks to a subclass, to pin them as overridable (protected). */
final class DefaultHooksJourneyTestCase extends JourneyTestCase
{
    /** @return list<Closure(): void> */
    public function defaultStateResets(): array
    {
        return $this->journeyStateResets();
    }

    /** @param TestResponse<Response> $response */
    public function defaultResponsePolicy(TestResponse $response): void
    {
        $this->journeyResponsePolicy($response);
    }
}
