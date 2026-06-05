<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Guest extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'guest_code',
        'full_name',
        'email',
        'phone',
        'nationality',
        'id_type',
        'id_number',
        'guest_type',
        'vip_level',
        'notes',
    ];

    protected $casts = [
        'guest_type' => GuestTypeEnum::class,
        'vip_level'  => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'primary_guest_id');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }
}
