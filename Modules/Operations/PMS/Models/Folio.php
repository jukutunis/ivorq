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

/**
 * PMS Guest Ledger — Folio aggregate root.
 *
 * GLF-A: ALL fields are server-owned. The model denies generic mass
 * assignment. Controlled creation MUST go through
 * FolioRepository::createControlled(), which uses forceFill().
 *
 * CACHED TOTALS: total_charges, total_payments, and balance are operational
 * projections derived from active (non-void) folio items. They are NOT
 * settlement evidence and a zero balance does NOT indicate settlement
 * readiness. Only an authoritative PMS Guest Ledger settlement-readiness
 * projection may determine settlement readiness (future GLF-D).
 */
class Folio extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    /**
     * No mass-assignable fields. All aggregate-owned attributes must be
     * set through FolioRepository::createControlled() or explicit property
     * assignment. This prevents browser-controlled creation bypasses.
     */
    protected $fillable = [];

    protected $guarded = ['*'];

    protected $casts = [
        'status'         => FolioStatusEnum::class,
        'window_number'  => 'integer',
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
