<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Shared\Traits\HasUlid;

class CostDeliveryCutover extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'cost_delivery_cutovers';

    protected $guarded = [];

    protected $casts = [
        'boundary_business_date' => 'date',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cost delivery cutover evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Cost delivery cutover evidence cannot be deleted.'));
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(CostDeliveryCutoverScope::class, 'cutover_id');
    }
}
