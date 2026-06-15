<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class CashAccount extends Model
{
    use HasUlid, BelongsToProperty, SoftDeletes;

    protected $table = 'cash_accounts';
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
