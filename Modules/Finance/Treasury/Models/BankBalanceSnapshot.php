<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class BankBalanceSnapshot extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'treasury_bank_balance_snapshots';
    protected $fillable = ['property_id', 'bank_account_id', 'snapshot_date', 'balance'];

    // Snapshot is immutable (CTO BR-012)
    protected static function booted()
    {
        static::updating(function ($snapshot) {
            return false;
        });
        static::deleting(function ($snapshot) {
            return false;
        });
    }
}
