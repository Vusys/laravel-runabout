<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Exposes RunsJourneys' default hooks to a subclass, to pin them as overridable (protected). */
final class DefaultHooksJourneyTestCase extends JourneyTestCase
{
    /** @param TestResponse<Response> $response */
    public function defaultIsRejected(TestResponse $response): bool
    {
        return $this->journeyIsRejected($response);
    }
}
