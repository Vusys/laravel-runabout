<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Runabout\Tests\Fixtures\PostStatus;

/**
 * The same `posts` table as {@see Post}, but with `status` cast to a BackedEnum
 * so legalTransitions() is exercised against a non-string state attribute.
 *
 * @property PostStatus $status
 */
final class EnumPost extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    protected $casts = [
        'status' => PostStatus::class,
    ];
}
