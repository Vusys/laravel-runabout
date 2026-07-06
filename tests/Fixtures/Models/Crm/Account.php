<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $won_amount
 * @property int $largest_open_deal
 * @property int $health_score
 * @property bool $health_fresh
 */
final class Account extends Model
{
    protected $table = 'crm_accounts';

    protected $guarded = [];

    protected $casts = [
        'won_amount' => 'int',
        'largest_open_deal' => 'int',
        'health_score' => 'int',
        'health_fresh' => 'bool',
    ];

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
