<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class BankAccount extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $fillable = [
        'property_id',
        'bank_name',
        'account_name',
        'account_number',
        'currency_code',
        'opening_balance',
        'current_balance',
        'reconciled_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'reconciled_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function newFactory()
    {
        return \Modules\Finance\Banking\database\Factories\BankAccountFactory::new();
    }
}
