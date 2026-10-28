<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankReturnEvidence extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns;

    protected $table = 'bank_return_evidence';

    protected $guarded = ['id'];

    protected $casts = [
        'return_amount' => 'decimal:2',
        'observed_return_date' => 'date',
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];
}
