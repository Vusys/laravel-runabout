<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use Exception;

/**
 * @internal Signals that a forced step order (exhaustive mode) reached a step
 * that was not enabled in its slot — the order is skipped, not failed.
 */
final class OrderNotViableException extends Exception
{
    public function __construct(public readonly string $stepName)
    {
        parent::__construct(sprintf('Step "%s" is not enabled in this order.', $stepName));
    }
}
