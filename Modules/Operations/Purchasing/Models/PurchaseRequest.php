<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Database\Factories\PurchaseRequestFactory;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PurchaseRequest extends Model implements ApprovableContract
{
    use BelongsToProperty, HasAuditColumns, HasFactory, HasUlid, LogsActivity, SoftDeletes;

    protected $table = 'purchase_requests';

    protected $fillable = [
        'property_id',
        'request_no',
        'creation_idempotency_key',
        'source_fingerprint',
        'revision_number',
        'current_revision_created_by',
        'department_id',
        'requester_id',
        'required_date',
        'currency_code',
        'exchange_rate',
        'estimated_total',
        'status',
        'remarks',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'estimated_total' => 'decimal:2',
        'revision_number' => 'integer',
        'status' => PurchaseRequestStatusEnum::class,
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'purchase_request_id');
    }

    public function revisionSnapshots(): HasMany
    {
        return $this->hasMany(PurchaseRequestRevisionSnapshot::class)
            ->orderByDesc('revision_number');
    }

    public function currentRevisionMakerId(): ?string
    {
        if ((int) $this->revision_number > 1) {
            return $this->current_revision_created_by;
        }

        return $this->current_revision_created_by ?? $this->requester_id;
    }

    protected static function newFactory()
    {
        return PurchaseRequestFactory::new();
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
        return $this->department_id;
    }

    public function getApprovalAmount(): float
    {
        return (float) $this->estimated_total;
    }

    public function markAsApproved(): void
    {
        $actorId = $this->latestApprovalActor('approve');

        $this->update([
            'status' => PurchaseRequestStatusEnum::Approved,
            'approved_by' => $actorId,
            'approved_at' => now(),
        ]);
    }

    public function markAsRejected(?string $reason = null): void
    {
        $actorId = $this->latestApprovalActor('reject');

        $this->update([
            'status' => PurchaseRequestStatusEnum::Rejected,
            'rejected_by' => $actorId,
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
