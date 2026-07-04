<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Vote extends Model
{
    protected $guarded = [];

    protected $casts = ['value' => 'int'];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
