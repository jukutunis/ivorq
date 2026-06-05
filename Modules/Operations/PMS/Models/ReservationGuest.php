<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\HasUlid;

/**
 * Pivot model for the reservation_guests table.
 * Supports additional metadata on the pivot (is_primary flag).
 */
class ReservationGuest extends Model
{
    use HasUlid;

    protected $table = 'reservation_guests';

    protected $fillable = [
        'property_id',
        'reservation_id',
        'guest_id',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
