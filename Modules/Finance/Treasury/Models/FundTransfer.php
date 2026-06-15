<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Modules\Finance\Banking\Models\BankAccount;

class FundTransfer extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'fund_transfers';
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
    ];

    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function destinationBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_bank_account_id');
    }
}
