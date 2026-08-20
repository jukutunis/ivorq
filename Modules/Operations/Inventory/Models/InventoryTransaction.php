<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryTransaction extends Model
{
    use BelongsToProperty, HasUlid;

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = [
        'transaction_type' => TransactionTypeEnum::class,
        'quantity_before' => 'decimal:4',
        'quantity_change' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'posted_at' => 'datetime',
        'business_date' => 'date',
        'occurred_at' => 'datetime',
        'currency_code' => 'string',
        'financial_period_id' => 'string',
        'valuation_scope' => 'string',
        'valuation_sequence' => 'integer',
        'valuation_approval_status' => 'string',
        'valuation_approval_reference' => 'string',
        'cost_delivery_mode' => 'string',
        'cost_delivery_ownership_id' => 'string',
        'cost_delivery_ownership_version' => 'integer',
        'cost_delivery_cutover_id' => 'string',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversesTransaction()
    {
        return $this->belongsTo(self::class, 'reverses_inventory_transaction_id');
    }

    public function correctsTransaction()
    {
        return $this->belongsTo(self::class, 'corrects_inventory_transaction_id');
    }
}
