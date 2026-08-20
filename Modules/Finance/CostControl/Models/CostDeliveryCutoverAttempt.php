<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Shared\Traits\HasUlid;

class CostDeliveryCutoverAttempt extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'cost_delivery_cutover_attempts';

    protected $guarded = [];

    protected $casts = [
        'boundary_business_date' => 'date',
        'requested_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cost delivery cutover attempt evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Cost delivery cutover attempt evidence cannot be deleted.'));
    }
}
