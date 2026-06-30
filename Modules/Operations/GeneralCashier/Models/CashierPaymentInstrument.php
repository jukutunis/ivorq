<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class CashierPaymentInstrument extends Model
{
    use HasUlid, HasAuditColumns;

    protected $fillable = [
        'property_id',
        'name',
        'type',
        'operational_gl_account_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => CashierPaymentInstrumentTypeEnum::class,
        'is_active' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
