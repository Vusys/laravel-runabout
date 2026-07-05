<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use LogicException;

/** The journey definition itself is broken (duplicate names, unknown dependencies, deadlock). */
final class InvalidJourneyException extends LogicException {}
