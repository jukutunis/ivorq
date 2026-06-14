<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Foundation\Approval\Contracts\ApprovableContract;

class ReceivingDocument extends Model implements ApprovableContract
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, LogsActivity;

    protected $table = 'receiving_documents';

    protected $fillable = [
        'property_id',
        'vendor_id',
        'purchase_order_id',
        'grn_number',
        'vendor_delivery_no',
        'status',
        'received_at',
        'received_by',
        'remarks',
    ];

    protected $casts = [
        'status' => ReceivingDocumentStatusEnum::class,
        'received_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceivingLine::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ReceivingAttachment::class, 'attachable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(ReceivingComment::class, 'commentable');
    }

    public function getApprovableId(): string
    {
        return $this->id;
    }

    public function getApprovalAmount(): float
    {
        return $this->lines()->sum('line_total');
    }

    public function markAsApproved(): void
    {
        $this->update(['status' => ReceivingDocumentStatusEnum::Approved]);
    }

    public function markAsRejected(?string $reason = null): void
    {
        $this->update(['status' => ReceivingDocumentStatusEnum::Rejected]);
    }

    public function getApprovableType(): string
    {
        return static::class;
    }

    public function getPropertyId(): string
    {
        return $this->property_id;
    }

    public function getRequesterId(): string
    {
        return $this->created_by;
    }

    public function getDepartmentId(): ?string
    {
        return null;
    }

    public function getTotalAmount(): float
    {
        return 0.0;
    }

    public function isDiscrepant(): bool
    {
        return $this->lines()->whereHas('discrepancies')->exists();
    }
}
