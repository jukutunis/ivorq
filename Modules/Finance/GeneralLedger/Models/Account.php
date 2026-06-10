<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;

class Account extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_accounts';

    protected $fillable = [
        'property_id',
        'master_account_id',
        'code',
        'name',
        'normal_balance',
        'account_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'normal_balance' => NormalBalanceEnum::class,
        'account_type' => AccountTypeEnum::class,
    ];
}
