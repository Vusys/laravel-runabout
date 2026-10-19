<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\TestCase;

/** A base test case that mixes in RunsJourneys, so subclasses can override its hooks. */
abstract class JourneyTestCase extends TestCase
{
    use RunsJourneys;
}
