<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Company extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'logo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
