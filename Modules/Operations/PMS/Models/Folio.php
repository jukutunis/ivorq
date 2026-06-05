<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Folio extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'folio_number',
        'reservation_id',
        'guest_id',
        'status',
        'currency',
        'total_charges',
        'total_payments',
        'balance',
    ];

    protected $casts = [
        'status'         => FolioStatusEnum::class,
        'total_charges'  => 'decimal:2',
        'total_payments' => 'decimal:2',
        'balance'        => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(FolioItem::class);
    }

    /**
     * Active (non-void) line items only.
     */
    public function activeItems(): HasMany
    {
        return $this->hasMany(FolioItem::class)->where('is_void', false);
    }
}
