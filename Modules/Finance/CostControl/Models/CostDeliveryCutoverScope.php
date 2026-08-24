<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Shared\Traits\HasUlid;

class CostDeliveryCutoverScope extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'cost_delivery_cutover_scopes';

    protected $guarded = [];

    protected $casts = [
        'inventory_allocator_last_sequence' => 'integer',
        'cost_avco_last_valuation_sequence' => 'integer',
        'last_synchronously_owned_sequence' => 'integer',
        'first_deferred_owned_sequence' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cost delivery cutover scope evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Cost delivery cutover scope evidence cannot be deleted.'));
    }
}
