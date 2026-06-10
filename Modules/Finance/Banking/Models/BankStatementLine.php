<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class BankStatementLine extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $fillable = [
        'property_id',
        'bank_statement_id',
        'transaction_date',
        'description',
        'reference',
        'external_reference',
        'amount',
        'is_reconciled',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_reconciled' => 'boolean',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function reconciliationMatch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReconciliationMatch::class);
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Banking\database\Factories\BankStatementLineFactory::new();
    }
}
