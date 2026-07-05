<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

/** A string-backed status enum, to exercise legalTransitions' enum coercion. */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
