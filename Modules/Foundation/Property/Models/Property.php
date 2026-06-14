<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Foundation\User\Models\User;

class Property extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes, LogsActivity;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'code',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'timezone',
        'currency',
        'logo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(PropertySetting::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'property_user')
            ->using(PropertyUser::class)
            ->withPivot(['is_default', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function setting(string $group, string $key): ?string
    {
        return $this->settings()
            ->where('group', $group)
            ->where('key', $key)
            ->value('value');
    }
}
