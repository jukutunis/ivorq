<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesAndEventManagement\Enums\ContactRoleEnum;

class AccountContact extends Model
{
    use HasUlids;

    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'contact_role',
        'email',
        'phone',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'contact_role' => ContactRoleEnum::class,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
