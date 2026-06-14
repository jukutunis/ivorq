<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\ReasonCodeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class StockCountLine extends Model
{
    use HasUlid, BelongsToProperty, LogsActivity;

    protected $fillable = [
        'property_id',
        'stock_count_session_id',
        'item_id',
    ];

    protected $casts = [
        'expected_quantity_snapshot' => 'decimal:4',
        'counted_quantity'           => 'decimal:4',
        'variance_quantity'          => 'decimal:4',
        'reason_code'                => ReasonCodeEnum::class,
        'snapshot_timestamp'         => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockCountSession::class, 'stock_count_session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    /**
     * Dynamically computes the variance cost based on the current AVCO.
     */
    public function getVarianceCostAttribute(): float
    {
        if ($this->variance_quantity === null || $this->item === null) {
            return 0.0;
        }

        return round((float) $this->variance_quantity * (float) $this->item->weighted_average_cost, 2);
    }
}
