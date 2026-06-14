<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\CountStatusEnum;
use Modules\Operations\Inventory\Enums\CountScopeEnum;
use Modules\Operations\Inventory\Enums\SessionTypeEnum;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Support\LogOptions;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class StockCountSession extends Model implements ApprovableContract
{
    use HasUlid, BelongsToProperty, SoftDeletes, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'property_id',
        'session_number',
        'type',
        'scope',
        'location_id',
        'zone_id',
        'category_id',
        'scheduled_at',
    ];

    protected $casts = [
        'type' => SessionTypeEnum::class,
        'scope' => CountScopeEnum::class,
        'status' => CountStatusEnum::class,
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(\Modules\Operations\Inventory\Models\InventoryCategory::class, 'category_id');
    }

    // --- APPROVAL ENGINE INTEGRATION ---

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    // --- ATTACHMENT INTEGRATION ---

    public function attachments(): HasMany
    {
        return $this->hasMany(StockCountAttachment::class, 'stock_count_session_id');
    }

    public function getApprovableType(): string
    {
        return self::class;
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
        return null; // Opname affects property inventory, not department specific budget
    }

    public function getApprovalAmount(): float
    {
        return 0.0; // Variance cost is dynamically calculated, but doesn't necessarily dictate approval matrix amount limits
    }

    public function markAsApproved(): void
    {
        $this->status = CountStatusEnum::APPROVED->value;
        $this->approved_by = auth()->id();
        $this->save();
    }

    public function markAsRejected(?string $reason = null): void
    {
        $this->status = CountStatusEnum::REJECTED->value; // Assuming REJECTED exists or STALE
        // If rejected, usually they are set back or cancelled. Let's set it to CANCELLED for now if REJECTED is not in enum
        if (!CountStatusEnum::tryFrom('rejected')) {
             $this->status = CountStatusEnum::CANCELLED->value;
        }
        $this->save();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_session_id');
    }
}
