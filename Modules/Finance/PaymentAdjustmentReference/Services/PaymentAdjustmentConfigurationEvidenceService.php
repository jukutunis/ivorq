<?php

namespace Modules\Finance\PaymentAdjustmentReference\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentConfigurationStatusEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentPolicyTypeEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentTypeEnum;
use Modules\Finance\PaymentAdjustmentReference\Models\PaymentAdjustmentConfigurationEvidence;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Throwable;

class PaymentAdjustmentConfigurationEvidenceService
{
    public const RECORD_PERMISSION = 'finance.payment-adjustment-config.record';
    public const APPROVE_PERMISSION = 'finance.payment-adjustment-config.approve';
    public const CONTRACT = 'payment_adjustment_configuration_evidence_v1';
    private const POLICY_SCALE = 8;

    public function __construct(
        private readonly OperationalIdentityValidationService $identityValidationService
    ) {}

    public function record(
        string $propertyId,
        PaymentAdjustmentTypeEnum|string $adjustmentType,
        PaymentAdjustmentPolicyTypeEnum|string $policyType,
        mixed $policyValue,
        string $adjustmentAccountMappingId,
        string $effectiveDate,
        string $sourceReference,
        ?User $actor
    ): PaymentAdjustmentConfigurationEvidence {
        return DB::transaction(function () use (
            $propertyId,
            $adjustmentType,
            $policyType,
            $policyValue,
            $adjustmentAccountMappingId,
            $effectiveDate,
            $sourceReference,
            $actor
        ): PaymentAdjustmentConfigurationEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::RECORD_PERMISSION);
            $property = $this->resolveProperty($propertyId);
            $this->assertActorCanAccessProperty($actor, $property->id);

            $adjustmentType = $this->adjustmentType($adjustmentType);
            $policyType = $this->policyType($policyType);
            $policyValue = $this->decimalString($policyValue);
            $effectiveDate = Carbon::parse($effectiveDate)->toDateString();
            $sourceReference = trim($sourceReference);

            if ($sourceReference === '') {
                throw new DomainException('Payment Adjustment Configuration evidence requires a source reference.');
            }

            $mapping = $this->resolveMapping($property->id, $adjustmentType, $adjustmentAccountMappingId, $effectiveDate);
            $policyCurrency = $policyType === PaymentAdjustmentPolicyTypeEnum::FIXED ? $property->currency : null;
            $mappingSnapshot = $this->mappingSnapshot($mapping);
            $identityHash = $this->identityHash(
                $property->id,
                $adjustmentType,
                $policyType,
                $policyValue,
                $policyCurrency,
                $mapping,
                $effectiveDate,
                $sourceReference,
                $actor->id
            );

            $existing = PaymentAdjustmentConfigurationEvidence::where('source_identity_hash', $identityHash)
                ->orWhere(function ($query) use (
                    $property,
                    $adjustmentType,
                    $policyType,
                    $policyCurrency,
                    $mapping,
                    $effectiveDate,
                    $sourceReference
                ): void {
                    $query->where('property_id', $property->id)
                        ->where('adjustment_type', $adjustmentType->value)
                        ->where('policy_type', $policyType->value)
                        ->where('policy_currency', $policyCurrency)
                        ->where('adjustment_account_mapping_id', $mapping->id)
                        ->where('effective_date', $effectiveDate)
                        ->where('source_reference', $sourceReference);
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingRecordMatches(
                    $existing,
                    $identityHash,
                    $property->id,
                    $adjustmentType,
                    $policyType,
                    $policyValue,
                    $policyCurrency,
                    $mapping,
                    $effectiveDate,
                    $sourceReference,
                    $actor->id
                );

                return $existing->fresh();
            }

            $evidence = new PaymentAdjustmentConfigurationEvidence([
                'property_id' => $property->id,
                'adjustment_type' => $adjustmentType,
                'policy_type' => $policyType,
                'policy_value' => $policyValue,
                'policy_currency' => $policyCurrency,
                'adjustment_account_mapping_id' => $mapping->id,
                'mapping_snapshot' => $mappingSnapshot,
                'effective_date' => $effectiveDate,
                'source_reference' => $sourceReference,
                'status' => PaymentAdjustmentConfigurationStatusEnum::RECORDED,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => [
                    'contract' => self::CONTRACT,
                    'property_id' => $property->id,
                    'adjustment_type' => $adjustmentType->value,
                    'policy_type' => $policyType->value,
                    'policy_value' => $policyValue,
                    'policy_currency' => $policyCurrency,
                    'adjustment_account_mapping_id' => $mapping->id,
                    'mapping_snapshot' => $mappingSnapshot,
                    'effective_date' => $effectiveDate,
                    'source_reference' => $sourceReference,
                    'recorded_by' => $actor->id,
                ],
            ]);
            $evidence->created_by = $actor->id;
            $evidence->updated_by = $actor->id;
            $evidence->save();

            return $evidence->fresh();
        });
    }

    public function approve(string $evidenceId, ?User $actor): PaymentAdjustmentConfigurationEvidence
    {
        return DB::transaction(function () use ($evidenceId, $actor): PaymentAdjustmentConfigurationEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);
            $evidence = PaymentAdjustmentConfigurationEvidence::whereKey($evidenceId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertActorCanAccessProperty($actor, $evidence->property_id);

            if ($evidence->status === PaymentAdjustmentConfigurationStatusEnum::APPROVED) {
                if ($evidence->approved_by === $actor->id) {
                    return $evidence->fresh();
                }

                throw new DomainException('Conflicting Payment Adjustment Configuration approval evidence already exists.');
            }
            if ($evidence->status === PaymentAdjustmentConfigurationStatusEnum::REJECTED) {
                throw new DomainException('Rejected Payment Adjustment Configuration evidence cannot be approved.');
            }
            if ($evidence->recorded_by === $actor->id) {
                throw new AuthorizationException('Payment Adjustment Configuration recorder cannot approve their own evidence.');
            }

            $evidence->status = PaymentAdjustmentConfigurationStatusEnum::APPROVED;
            $evidence->approved_by = $actor->id;
            $evidence->approved_at = now();
            $evidence->updated_by = $actor->id;
            $evidence->save();

            return $evidence->fresh();
        });
    }

    public function reject(string $evidenceId, string $reason, ?User $actor): PaymentAdjustmentConfigurationEvidence
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Payment Adjustment Configuration rejection requires a reason.');
        }

        return DB::transaction(function () use ($evidenceId, $reason, $actor): PaymentAdjustmentConfigurationEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);
            $evidence = PaymentAdjustmentConfigurationEvidence::whereKey($evidenceId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertActorCanAccessProperty($actor, $evidence->property_id);

            if ($evidence->status === PaymentAdjustmentConfigurationStatusEnum::REJECTED) {
                if ($evidence->rejected_by === $actor->id && $evidence->rejection_reason === $reason) {
                    return $evidence->fresh();
                }

                throw new DomainException('Conflicting Payment Adjustment Configuration rejection evidence already exists.');
            }
            if ($evidence->status === PaymentAdjustmentConfigurationStatusEnum::APPROVED) {
                throw new DomainException('Approved Payment Adjustment Configuration evidence cannot be rejected.');
            }

            $evidence->status = PaymentAdjustmentConfigurationStatusEnum::REJECTED;
            $evidence->rejected_by = $actor->id;
            $evidence->rejected_at = now();
            $evidence->rejection_reason = $reason;
            $evidence->updated_by = $actor->id;
            $evidence->save();

            return $evidence->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor, string $permission): User
    {
        if (!$actor) {
            throw new AuthorizationException('Payment Adjustment Configuration evidence requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Payment Adjustment Configuration evidence requires an active actor.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Payment Adjustment Configuration evidence permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Payment Adjustment Configuration evidence permission is required.');
        }

        return $freshActor;
    }

    private function resolveProperty(string $propertyId): Property
    {
        $property = Property::query()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->first();

        if (!$property) {
            throw new DomainException('Payment Adjustment Configuration evidence requires active property scope.');
        }

        return $property;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Payment Adjustment Configuration evidence requires active property access.');
        }
    }

    private function resolveMapping(
        string $propertyId,
        PaymentAdjustmentTypeEnum $adjustmentType,
        string $mappingId,
        string $effectiveDate
    ): OperationalIdentityMapping {
        $mapping = OperationalIdentityMapping::with('account')
            ->whereKey($mappingId)
            ->lockForUpdate()
            ->first();

        if (!$mapping) {
            throw new DomainException('Payment Adjustment Configuration account mapping is missing.');
        }

        $identity = $this->mappingIdentity($mapping);
        if ($mapping->property_id !== $propertyId) {
            throw new DomainException('Payment Adjustment Configuration account mapping conflicts with property scope.');
        }
        if (!$mapping->is_active || $mapping->effective_from?->toDateString() > $effectiveDate) {
            throw new DomainException('Payment Adjustment Configuration account mapping is inactive.');
        }
        if ($mapping->effective_to !== null && $mapping->effective_to->toDateString() < $effectiveDate) {
            throw new DomainException('Payment Adjustment Configuration account mapping is inactive.');
        }
        if ($identity !== $this->requiredMappingIdentity($adjustmentType)) {
            throw new DomainException('Payment Adjustment Configuration account mapping does not support the adjustment type.');
        }
        if (!$mapping->account || $mapping->account->property_id !== $propertyId || !$mapping->account->is_active || $mapping->account->deleted_at !== null) {
            throw new DomainException('Payment Adjustment Configuration account mapping account is inactive or cross-property.');
        }

        try {
            $this->identityValidationService->validate($identity, $mapping->account);
        } catch (OperationalIdentityValidationException) {
            throw new DomainException('Payment Adjustment Configuration account mapping is incompatible with its GL account.');
        }

        $matchingMappings = OperationalIdentityMapping::where('property_id', $propertyId)
            ->where('operational_identity', $identity->value)
            ->where('is_active', true)
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveDate);
            });

        if ($mapping->cost_center_id === null) {
            $matchingMappings->whereNull('cost_center_id');
        } else {
            $matchingMappings->where('cost_center_id', $mapping->cost_center_id);
        }

        if ($matchingMappings->count() !== 1) {
            throw new DomainException('Payment Adjustment Configuration account mapping is ambiguous.');
        }

        return $mapping;
    }

    private function requiredMappingIdentity(PaymentAdjustmentTypeEnum $adjustmentType): OperationalIdentityEnum
    {
        return match ($adjustmentType) {
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentTypeEnum::WITHHOLDING => OperationalIdentityEnum::VENDOR_TAX,
            PaymentAdjustmentTypeEnum::DISCOUNT => OperationalIdentityEnum::PAYMENT_VARIANCE,
        };
    }

    private function mappingSnapshot(OperationalIdentityMapping $mapping): array
    {
        $identity = $this->mappingIdentity($mapping);

        return [
            'mapping_id' => $mapping->id,
            'property_id' => $mapping->property_id,
            'operational_identity' => $identity->value,
            'account_id' => $mapping->account_id,
            'cost_center_id' => $mapping->cost_center_id,
            'effective_from' => $mapping->effective_from?->toDateString(),
            'effective_to' => $mapping->effective_to?->toDateString(),
            'is_active' => (bool) $mapping->is_active,
        ];
    }

    private function assertExistingRecordMatches(
        PaymentAdjustmentConfigurationEvidence $existing,
        string $identityHash,
        string $propertyId,
        PaymentAdjustmentTypeEnum $adjustmentType,
        PaymentAdjustmentPolicyTypeEnum $policyType,
        string $policyValue,
        ?string $policyCurrency,
        OperationalIdentityMapping $mapping,
        string $effectiveDate,
        string $sourceReference,
        string $actorId
    ): void {
        if (
            $existing->source_identity_hash === $identityHash &&
            $existing->property_id === $propertyId &&
            $existing->adjustment_type === $adjustmentType &&
            $existing->policy_type === $policyType &&
            $this->decimalString($existing->policy_value) === $policyValue &&
            $existing->policy_currency === $policyCurrency &&
            $existing->adjustment_account_mapping_id === $mapping->id &&
            $existing->effective_date?->toDateString() === $effectiveDate &&
            $existing->source_reference === $sourceReference &&
            $existing->recorded_by === $actorId
        ) {
            return;
        }

        throw new DomainException('Conflicting Payment Adjustment Configuration evidence already exists.');
    }

    private function identityHash(
        string $propertyId,
        PaymentAdjustmentTypeEnum $adjustmentType,
        PaymentAdjustmentPolicyTypeEnum $policyType,
        string $policyValue,
        ?string $policyCurrency,
        OperationalIdentityMapping $mapping,
        string $effectiveDate,
        string $sourceReference,
        string $actorId
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $propertyId,
            $adjustmentType->value,
            $policyType->value,
            $policyValue,
            $policyCurrency ?? 'NONE',
            $mapping->id,
            $this->mappingIdentity($mapping)->value,
            $mapping->account_id,
            $effectiveDate,
            $sourceReference,
            $actorId,
        ]));
    }

    private function mappingIdentity(OperationalIdentityMapping $mapping): OperationalIdentityEnum
    {
        if ($mapping->operational_identity instanceof OperationalIdentityEnum) {
            return $mapping->operational_identity;
        }

        return OperationalIdentityEnum::from($mapping->operational_identity);
    }

    private function adjustmentType(PaymentAdjustmentTypeEnum|string $adjustmentType): PaymentAdjustmentTypeEnum
    {
        if ($adjustmentType instanceof PaymentAdjustmentTypeEnum) {
            return $adjustmentType;
        }

        return PaymentAdjustmentTypeEnum::from($adjustmentType);
    }

    private function policyType(PaymentAdjustmentPolicyTypeEnum|string $policyType): PaymentAdjustmentPolicyTypeEnum
    {
        if ($policyType instanceof PaymentAdjustmentPolicyTypeEnum) {
            return $policyType;
        }

        return PaymentAdjustmentPolicyTypeEnum::from($policyType);
    }

    private function decimalString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('Payment Adjustment Configuration policy value requires a canonical decimal string.');
        }
        if ($value !== trim($value) || $value === '') {
            throw new DomainException('Payment Adjustment Configuration policy value requires a canonical decimal string.');
        }
        if (!preg_match('/^(0|[1-9][0-9]*)\.([0-9]+)$/', $value, $matches)) {
            throw new DomainException('Payment Adjustment Configuration policy value requires a canonical decimal string.');
        }
        if (strlen($matches[2]) > self::POLICY_SCALE) {
            throw new DomainException('Payment Adjustment Configuration policy value scale exceeds configured precision.');
        }

        $canonical = $matches[1] . '.' . str_pad($matches[2], self::POLICY_SCALE, '0');
        [$whole, $fraction] = explode('.', $canonical, 2);
        if (ltrim($whole, '0') === '' && ltrim($fraction, '0') === '') {
            throw new DomainException('Payment Adjustment Configuration policy value must be positive.');
        }

        return $canonical;
    }
}
