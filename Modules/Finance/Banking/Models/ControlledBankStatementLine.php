<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ControlledBankStatementLine extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'controlled_bank_account_id',
        'property_id',
        'source_reference',
        'external_reference',
        'statement_date',
        'direction',
        'amount',
        'currency_code',
        'vendor_reference',
        'recorded_by',
        'recorded_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'direction' => ControlledBankStatementLineDirectionEnum::class,
        'amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ControlledBankAccount::class, 'controlled_bank_account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
