<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Folio extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    /**
     * Mass-assignable attributes.
     *
     * SERVER-OWNED (must NOT be accepted from browser input — the service layer
     * resolves these fields server-side only):
     *   property_id, reservation_id, guest_id, currency, status,
     *   total_charges, total_payments, balance, window_number,
     *   opening_idempotency_key
     *
     * CACHED TOTALS: total_charges, total_payments, and balance are operational
     * projections derived from active (non-void) folio items. They are NOT
     * settlement evidence and a zero balance does NOT indicate settlement
     * readiness. Only an authoritative PMS Guest Ledger settlement-readiness
     * projection may determine settlement readiness (future GLF-D).
     */
    protected $fillable = [
        'property_id',
        'folio_number',
        'reservation_id',
        'guest_id',
        'status',
        'currency',
        'window_number',
        'opening_idempotency_key',
        'total_charges',
        'total_payments',
        'balance',
    ];

    protected $casts = [
        'status'         => FolioStatusEnum::class,
        'window_number'  => 'integer',
        'total_charges'  => 'decimal:2',
        'total_payments' => 'decimal:2',
        'balance'        => 'decimal:2',
    ];

    /**
     * Auto-generate defaults for GLF-A columns when not provided.
     *
     * This preserves backward compatibility with direct Folio::create()
     * usage (tests, seeders, legacy callers) while the controlled service
     * layer (GuestLedgerFolioAggregateService) explicitly sets correct
     * values.
     */
    protected static function booted(): void
    {
        static::creating(function (Folio $folio) {
            // GLF-A: Provide safe defaults when not explicitly set.
            // The controlled service layer always overrides these with
            // correct server-resolved values.
            if ($folio->window_number === null || (int) $folio->window_number < 1) {
                $folio->window_number = 1;
            }
            if (empty($folio->opening_idempotency_key)) {
                $folio->opening_idempotency_key = 'legacy-' . Str::ulid();
            }
        });
    }

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
