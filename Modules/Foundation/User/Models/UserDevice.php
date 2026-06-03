<?php

namespace Modules\Foundation\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;

class UserDevice extends Model
{
    use HasUlid;

    protected $fillable = [
        'user_id',
        'property_id',
        'device_name',
        'device_type',
        'platform',
        'push_token',
        'is_active',
        'last_seen_at',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
