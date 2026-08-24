<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Shared\Traits\HasUlid;

class CostDeliveryPilotProperty extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'cost_delivery_pilot_properties';

    protected $fillable = [
        'pilot_slot',
        'property_id',
        'owner_approval_reference',
        'authorized_by',
        'authorized_at',
    ];

    protected $casts = [
        'pilot_slot' => 'integer',
        'authorized_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cost delivery pilot authorization is immutable.'));
        static::deleting(fn () => throw new LogicException('Cost delivery pilot authorization cannot be deleted.'));
    }
}
