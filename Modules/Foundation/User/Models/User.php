<?php

namespace Modules\Foundation\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyUser;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class User extends Authenticatable
{
    use HasApiTokens, HasUlid, HasAuditColumns, HasRoles, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'is_system_admin',
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'department_id',
        'position_id',
        'employee_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'         => 'boolean',
        'is_system_admin'   => 'boolean',
        'password'          => 'hashed',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_user')
            ->using(PropertyUser::class)
            ->withPivot(['is_default', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_system_admin === true;
    }

    public function defaultProperty(): ?Property
    {
        return $this->properties()
            ->wherePivot('is_default', true)
            ->wherePivot('status', 'active')
            ->first() 
            ?? $this->properties()
            ->wherePivot('status', 'active')
            ->first();
    }
}
