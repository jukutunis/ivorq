<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Shared\Traits\HasUlid;

/**
 * PMS Guest Ledger — FolioItem (immutable ledger entry).
 *
 * FolioItem rows are immutable. updated_at is not meaningful; void flag
 * is used instead of deletion.
 *
 * GLF-A: Server-owned fields (property_id, folio_id, is_void, posted_at,
 * posted_by, created_by) are NOT mass-assignable. Controlled creation MUST
 * go through FolioItemRepository::createControlled(). Only business input
 * (item_type, description, quantity, amount) may be set via fill.
 */
class FolioItem extends Model
{
    use HasUlid;

    /**
     * Only business-input fields are mass-assignable.
     * Server-owned fields must go through createControlled().
     */
    protected $fillable = [
        'item_type',
        'description',
        'quantity',
        'amount',
    ];

    protected $guarded = [
        'property_id',
        'folio_id',
        'is_void',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'item_type'  => FolioItemTypeEnum::class,
        'quantity'   => 'decimal:2',
        'amount'     => 'decimal:2',
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
