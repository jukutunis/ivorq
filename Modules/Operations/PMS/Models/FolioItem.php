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

    // FolioItem rows are immutable ledger entries.
    // updated_at is not meaningful here; void flag is used instead of deletion.
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
