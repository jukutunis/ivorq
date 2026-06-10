<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class ReconciliationSession extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $fillable = [
        'property_id',
        'bank_account_id',
        'statement_date_start',
        'statement_date_end',
        'opening_balance',
        'reconciled_balance',
        'unreconciled_balance',
        'status',
        'completed_at',
        'completed_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'statement_date_start' => 'date',
        'statement_date_end' => 'date',
        'opening_balance' => 'decimal:2',
        'reconciled_balance' => 'decimal:2',
        'unreconciled_balance' => 'decimal:2',
        'status' => ReconciliationSessionStatusEnum::class,
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class);
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Banking\database\Factories\ReconciliationSessionFactory::new();
    }
}
