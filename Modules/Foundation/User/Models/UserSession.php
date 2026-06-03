<?php

namespace Modules\Foundation\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;

class UserSession extends Model
{
    use HasUlid;

    protected $fillable = [
        'user_id',
        'property_id',
        'token_id',
        'device_name',
        'ip_address',
        'user_agent',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
