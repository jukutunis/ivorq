<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Shared\Traits\HasUlid;

class FolioItem extends Model
{
    use HasUlid;

    /**
     * FolioItem rows are immutable ledger entries.
     * updated_at is not meaningful here; void flag is used instead of deletion.
     *
     * SERVER-OWNED (must NOT be accepted from browser input):
     *   property_id — derived from parent Folio server-side
     *   folio_id    — resolved server-side from the controlled folio
     *   is_void     — managed through controlled voidItem() only
     *   posted_at   — set server-side at posting time
     *   posted_by   — resolved from the authenticated actor server-side
     *   created_by  — resolved from the authenticated actor server-side
     *
     * BUSINESS INPUT (narrowly permitted, subject to validation):
     *   item_type, description, quantity, amount
     */
    protected $fillable = [
        'property_id',
        'folio_id',
        'item_type',
        'description',
        'quantity',
        'amount',
        'is_void',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'item_type'  => FolioItemTypeEnum::class,
        'quantity'   => 'decimal:2',
        'amount'     => 'decimal:2',   // positive = charge; negative = credit/payment
        'is_void'    => 'boolean',
        'posted_at'  => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
