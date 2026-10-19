<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Closure;

/** Exposes RunsJourneys' default hooks to a subclass, to pin them as overridable (protected). */
final class DefaultHooksJourneyTestCase extends JourneyTestCase
{
    /** @return list<Closure(): void> */
    public function defaultStateResets(): array
    {
        return $this->journeyStateResets();
    }
}
