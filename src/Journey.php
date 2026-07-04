<?php

declare(strict_types=1);

namespace Vusys\Runabout;

abstract class Journey
{
    /** @return list<Step> */
    abstract public function steps(): array;

    /**
     * Checked after every step, whichever step it was.
     *
     * @return list<Invariant>
     */
    public function invariants(): array
    {
        return [];
    }
}
