<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerBalance extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, HasFactory;

    protected $table = 'gl_ledger_balances';

    protected $fillable = [
        'property_id',
        'account_id',
        'period_year',
        'period_month',
        'debit_total',
        'credit_total',
        'ending_balance',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'debit_total' => 'decimal:2',
        'credit_total' => 'decimal:2',
        'ending_balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
