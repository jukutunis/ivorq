<?php

namespace Modules\Finance\AccountsPayable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Finance\AccountsPayable\Database\Factories\ApInvoiceLineFactory;

class ApInvoiceLine extends Model
{
    use HasUlid, HasFactory;

    protected static function newFactory()
    {
        return ApInvoiceLineFactory::new();
    }

    protected $table = 'ap_invoice_lines';

    protected $fillable = [
        'invoice_id',
        'receipt_line_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:3',
        'subtotal_amount' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'total_amount' => 'decimal:3',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(ReceivingLine::class, 'receipt_line_id');
    }
}
