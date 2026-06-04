<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

/**
 * Photos attached to a room inspection.
 *
 * HasAuditColumns is intentionally omitted — the inspection_photos table has
 * only created_by (no updated_by column). Photos are created-once, never
 * updated; created_by is set via the booted() hook below.
 */
class InspectionPhoto extends Model
{
    use HasUlid, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'inspection_id',
        'file_path',
        'file_name',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $photo) {
            if (auth()->check()) {
                $photo->created_by ??= auth()->id();
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RoomInspection::class, 'inspection_id');
    }
}
