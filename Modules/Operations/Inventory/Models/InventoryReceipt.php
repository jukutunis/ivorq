<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryReceipt extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'inventory_receipts';

    protected $fillable = [
        'property_id',
        'receipt_number',
        'supplier_name',
        'external_reference',
        'receiving_document_id',
        'status',
        'received_at',
        'remarks',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'status'       => ReceiptStatusEnum::class,
        'received_at'  => 'datetime',
        'posted_at'    => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'receipt_id');
    }

    public function receivingDocument(): BelongsTo
    {
        return $this->belongsTo(ReceivingDocument::class, 'receiving_document_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
