<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property string $title
 * @property string $status
 * @property int $score
 * @property int $votes_today
 * @property Carbon|null $votes_today_date
 * @property int $reports_remaining
 * @property Carbon|null $deleted_at
 */
final class Post extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'score' => 'int',
        'votes_today' => 'int',
        'votes_today_date' => 'date',
        'reports_remaining' => 'int',
    ];

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

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
