<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;

class VendorQuotationLine extends Model
{
    use HasUlid;

    protected $table = 'vendor_quotation_lines';
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function vendorQuotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class, 'vendor_quotation_id');
    }

    public function requestForQuotationLine(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotationLine::class, 'request_for_quotation_line_id');
    }
}
