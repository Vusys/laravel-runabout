<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Throwable;
use Vusys\Runabout\Exceptions\InvariantViolationException;
use Vusys\Runabout\Exceptions\JourneyFailedException;

/**
 * A fingerprint of *how* a trail failed, so the shrinker can tell a candidate
 * that reproduces the original bug from one that trips a different one. It is
 * deliberately coarse on data (message text is excluded — it carries values
 * that legitimately vary) and precise on identity: an invariant violation is
 * identified by the invariant's labelled name; a step failure by the failing
 * step's labelled name plus the thrown exception's class. Two failures with
 * the same signature are "the same bug", wherever in the trail they land.
 */
final readonly class FailureSignature
{
    /** @param 'invariant'|'step' $kind */
    private function __construct(
        public string $kind,
        public string $name,
        public string $causeClass,
    ) {}

    public static function from(JourneyFailedException $failure): self
    {
        $cause = $failure->getPrevious();

        if ($cause instanceof InvariantViolationException) {
            return new self('invariant', $cause->invariant, '');
        }

        $steps = $failure->trail()->steps();
        $name = $steps === [] ? '(before any step)' : $steps[count($steps) - 1];

        return new self('step', $name, $cause instanceof Throwable ? $cause::class : '');
    }

    public function matches(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->name === $other->name
            && $this->causeClass === $other->causeClass;
    }

    /** A short human-readable form for failure output. */
    public function describe(): string
    {
        return $this->kind === 'invariant'
            ? sprintf('invariant "%s"', $this->name)
            : sprintf('step "%s" throwing %s', $this->name, $this->causeClass);
    }
}
