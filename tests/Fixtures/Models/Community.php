<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $posts_count
 */
final class Community extends Model
{
    protected $guarded = [];

    protected $casts = ['posts_count' => 'int'];

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
