<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;

class Account extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'account_name',
        'account_type',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'account_type' => AccountTypeEnum::class,
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
