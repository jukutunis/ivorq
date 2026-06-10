<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Banking\Enums\BankStatementStatusEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class BankStatement extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $fillable = [
        'property_id',
        'bank_account_id',
        'statement_date',
        'opening_balance',
        'closing_balance',
        'imported_closing_balance',
        'status',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'imported_closing_balance' => 'decimal:2',
        'status' => BankStatementStatusEnum::class,
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Banking\database\Factories\BankStatementFactory::new();
    }
}
