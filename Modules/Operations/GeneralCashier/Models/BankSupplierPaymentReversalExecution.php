<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankSupplierPaymentReversalExecution extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns;

    protected $guarded = ['id'];

    protected $casts = [
        'reversal_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'source_snapshot' => 'array',
    ];
}
