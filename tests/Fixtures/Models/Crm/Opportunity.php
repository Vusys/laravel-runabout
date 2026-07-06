<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $stage
 * @property int $amount
 */
final class Opportunity extends Model
{
    protected $table = 'crm_opportunities';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'int',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
