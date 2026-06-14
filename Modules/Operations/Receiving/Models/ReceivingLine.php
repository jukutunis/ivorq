<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ReceivingLine extends Model
{
    use HasUlid, HasAuditColumns, LogsActivity;

    protected $table = 'receiving_lines';

    protected $fillable = [
        'receiving_document_id',
        'purchase_order_line_id',
        'inventory_item_id',
        'inventory_unit_id',
        'destination_location_id',
        'description',
        'received_quantity',
        'unit_cost',
        'line_total',
        'serial_number',
        'imei',
        'mac_address',
        'lot_number',
        'expiry_date',
        'manufacture_date',
    ];

    protected $casts = [
        'received_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
        'expiry_date' => 'date',
        'manufacture_date' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function receivingDocument(): BelongsTo
    {
        return $this->belongsTo(ReceivingDocument::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Operations\Inventory\Models\InventoryItem::class, 'inventory_item_id');
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(ReceivingDiscrepancy::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(ReceivingInspection::class);
    }
}
