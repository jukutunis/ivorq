<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Shared\Traits\HasUlid;

class CashierSession extends Model
{
    use HasUlid;

    protected $fillable = [
        'property_id',
        'cashier_user_id',
        'status',
        'opened_at',
        'opened_by',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'status' => CashierSessionStatusEnum::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
