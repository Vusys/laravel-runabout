<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property string $title
 * @property string $status
 * @property int $score
 * @property int $votes_today
 * @property Carbon|null $votes_today_date
 */
final class Post extends Model
{
    protected $guarded = [];

    protected $casts = [
        'score' => 'int',
        'votes_today' => 'int',
        'votes_today_date' => 'date',
    ];

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return HasMany<Vote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
