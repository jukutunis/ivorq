<?php

namespace Modules\Finance\PaymentAdjustmentReference\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentConfigurationStatusEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentPolicyTypeEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentTypeEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PaymentAdjustmentConfigurationEvidence extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'payment_adjustment_configuration_evidences';

    protected $fillable = [
        'property_id',
        'adjustment_type',
        'policy_type',
        'policy_value',
        'policy_currency',
        'adjustment_account_mapping_id',
        'mapping_snapshot',
        'effective_date',
        'source_reference',
        'status',
        'recorded_by',
        'recorded_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'adjustment_type' => PaymentAdjustmentTypeEnum::class,
        'policy_type' => PaymentAdjustmentPolicyTypeEnum::class,
        'policy_value' => 'decimal:8',
        'mapping_snapshot' => 'array',
        'effective_date' => 'date',
        'status' => PaymentAdjustmentConfigurationStatusEnum::class,
        'recorded_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (PaymentAdjustmentConfigurationEvidence $evidence): void {
            if (in_array($evidence->getRawOriginal('status'), [
                PaymentAdjustmentConfigurationStatusEnum::APPROVED->value,
                PaymentAdjustmentConfigurationStatusEnum::REJECTED->value,
            ], true)) {
                throw new DomainException('Approved or rejected Payment Adjustment Configuration evidence is immutable.');
            }
        });

        static::deleting(function (PaymentAdjustmentConfigurationEvidence $evidence): void {
            if (in_array($evidence->getRawOriginal('status'), [
                PaymentAdjustmentConfigurationStatusEnum::APPROVED->value,
                PaymentAdjustmentConfigurationStatusEnum::REJECTED->value,
            ], true)) {
                throw new DomainException('Approved or rejected Payment Adjustment Configuration evidence is immutable.');
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function adjustmentAccountMapping(): BelongsTo
    {
        return $this->belongsTo(OperationalIdentityMapping::class, 'adjustment_account_mapping_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
