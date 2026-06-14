<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Modules\Operations\Purchasing\Enums\RequestForQuotationStatusEnum;

class RequestForQuotation extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'request_for_quotations';
    protected $guarded = ['id'];

    protected $casts = [
        'deadline_at' => 'datetime',
        'status' => RequestForQuotationStatusEnum::class,
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'request_for_quotation_vendors', 'request_for_quotation_id', 'vendor_id')
            ->withTimestamps();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequestForQuotationLine::class, 'request_for_quotation_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(VendorQuotation::class, 'request_for_quotation_id');
    }

    public function selectWinningQuotation(VendorQuotation $winningQuotation): void
    {
        // Deselect all others
        $this->quotations()->update(['is_winner' => false, 'status' => \Modules\Operations\Purchasing\Enums\VendorQuotationStatusEnum::Rejected]);
        
        // Select the winner
        $winningQuotation->update(['is_winner' => true, 'status' => \Modules\Operations\Purchasing\Enums\VendorQuotationStatusEnum::Selected]);
        
        $this->update(['status' => RequestForQuotationStatusEnum::Awarded]);
    }
}
