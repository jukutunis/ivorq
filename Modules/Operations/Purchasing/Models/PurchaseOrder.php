<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Operations\Purchasing\Database\Factories\PurchaseOrderFactory;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PurchaseOrder extends Model implements ApprovableContract
{
    use BelongsToProperty, HasAuditColumns, HasFactory, HasUlids, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'received_total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'revision_number' => 'integer',
        'status' => PurchaseOrderStatusEnum::class,
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'property_id',
                'po_no',
                'vendor_id',
                'purchase_request_id',
                'revision_number',
                'current_revision_created_by',
                'issue_date',
                'expected_delivery_date',
                'currency_code',
                'exchange_rate',
                'subtotal',
                'tax_amount',
                'total_amount',
                'status',
                'remarks',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'issued_by',
                'issued_at',
                'cancelled_by',
                'cancelled_at',
                'cancellation_reason',
            ])
            ->logOnlyDirty();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function revisionSnapshots(): HasMany
    {
        return $this->hasMany(PurchaseOrderRevisionSnapshot::class)
            ->orderByDesc('revision_number');
    }

    public function currentRevisionMakerId(): ?string
    {
        if ((int) $this->revision_number > 1) {
            return $this->current_revision_created_by;
        }

        return $this->current_revision_created_by ?? $this->created_by;
    }

    protected static function newFactory()
    {
        return PurchaseOrderFactory::new();
    }

    // ApprovableContract Implementation

    public function getApprovableType(): string
    {
        return static::class;
    }

    public function getApprovableId(): string
    {
        return $this->id;
    }

    public function getPropertyId(): string
    {
        return $this->property_id;
    }

    public function getDepartmentId(): ?string
    {
        // PO might not have a direct department, check PR
        return $this->purchaseRequest?->department_id;
    }

    public function getApprovalAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function markAsApproved(): void
    {
        $this->update([
            'status' => PurchaseOrderStatusEnum::Approved,
            'approved_by' => $this->latestApprovalActor('approve'),
            'approved_at' => now(),
        ]);
    }

    public function markAsRejected(?string $reason = null): void
    {
        $this->update([
            'status' => PurchaseOrderStatusEnum::Rejected,
            'rejected_by' => $this->latestApprovalActor('reject'),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    private function latestApprovalActor(string $action): ?string
    {
        $approvalRequestId = ApprovalRequest::query()
            ->where('approvable_type', static::class)
            ->where('approvable_id', $this->id)
            ->latest('requested_at')
            ->value('id');

        return $approvalRequestId === null
            ? null
            : ApprovalAction::query()
                ->where('approval_request_id', $approvalRequestId)
                ->where('action_type', $action)
                ->latest('created_at')
                ->value('user_id');
    }
}
