<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryTransfer extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_transfers';
    protected $guarded = ['id'];

    public function lines() { return $this->hasMany(InventoryTransferLine::class, 'transfer_id'); }
}

class InventoryTransferLine extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_transfer_lines';
    protected $guarded = ['id'];

    public function transfer() { return $this->belongsTo(InventoryTransfer::class, 'transfer_id'); }
    public function item() { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function fromLocation() { return $this->belongsTo(InventoryLocation::class, 'from_location_id'); }
    public function toLocation() { return $this->belongsTo(InventoryLocation::class, 'to_location_id'); }
    public function unit() { return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id'); }
}

class InventoryIssue extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_issues';
    protected $guarded = ['id'];

    public function lines() { return $this->hasMany(InventoryIssueLine::class, 'issue_id'); }
}

class InventoryIssueLine extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_issue_lines';
    protected $guarded = ['id'];

    public function issue() { return $this->belongsTo(InventoryIssue::class, 'issue_id'); }
    public function item() { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'inventory_location_id'); }
    public function unit() { return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id'); }
}

class InventoryStockCount extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_stock_counts';
    protected $guarded = ['id'];

    public function lines() { return $this->hasMany(InventoryStockCountLine::class, 'stock_count_id'); }
}

class InventoryStockCountLine extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_stock_count_lines';
    protected $guarded = ['id'];

    public function stockCount() { return $this->belongsTo(InventoryStockCount::class, 'stock_count_id'); }
    public function item() { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
}

class InventoryAdjustment extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_adjustments';
    protected $guarded = ['id'];

    public function lines() { return $this->hasMany(InventoryAdjustmentLine::class, 'adjustment_id'); }
}

class InventoryAdjustmentLine extends Model
{
    use HasUlid, BelongsToProperty;

    public $timestamps = false;
    protected $table = 'inventory_adjustment_lines';
    protected $guarded = ['id'];

    public function adjustment() { return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id'); }
    public function item() { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'inventory_location_id'); }
}
