<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryStockCard extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'inventory_stock_cards';

    // Append-only ledger: no created_at/updated_at columns on this table
    public $timestamps = false;

    // Block mass assignment — writes must go through StockMovementService
    protected $guarded = ['*'];

    protected $casts = [
        'movement_type'   => TransactionTypeEnum::class,
        'quantity_before' => 'decimal:3',
        'quantity_change' => 'decimal:3',
        'quantity_after'  => 'decimal:3',
        'unit_cost'       => 'decimal:4',
        'total_value'     => 'decimal:4',
        'posted_at'       => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
