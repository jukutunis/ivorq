<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Modules\Operations\Inventory\Models\InventoryUnit;

class RequestForQuotationLine extends Model
{
    use HasUlid;

    protected $table = 'request_for_quotation_lines';
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function requestForQuotation(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class, 'request_for_quotation_id');
    }

    public function purchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class, 'purchase_request_line_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }
}
