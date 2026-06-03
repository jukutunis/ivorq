<?php

namespace Modules\Foundation\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasUlid, HasAuditColumns, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'property_id',
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
        'password'          => 'hashed',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
        return is_null($this->property_id);
    }
}
