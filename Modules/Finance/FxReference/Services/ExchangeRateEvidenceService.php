<?php

namespace Modules\Finance\FxReference\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Models\ExchangeRateEvidence;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Throwable;

class ExchangeRateEvidenceService
{
    public const RECORD_PERMISSION = 'finance.fx-rate.record';
    public const APPROVE_PERMISSION = 'finance.fx-rate.approve';
    public const CONTRACT = 'exchange_rate_evidence_v1';
    private const RATE_SCALE = 8;

    public function record(
        string $propertyId,
        string $baseCurrency,
        string $quoteCurrency,
        mixed $rate,
        string $quoteConvention,
        string $effectiveDate,
        string $sourceReference,
        ?User $actor
    ): ExchangeRateEvidence {
        return DB::transaction(function () use (
            $propertyId,
            $baseCurrency,
            $quoteCurrency,
            $rate,
            $quoteConvention,
            $effectiveDate,
            $sourceReference,
            $actor
        ): ExchangeRateEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::RECORD_PERMISSION);
            $property = $this->resolveProperty($propertyId);
            $this->assertActorCanAccessProperty($actor, $property->id);

            $baseCurrency = $this->currency($baseCurrency);
            $quoteCurrency = $this->currency($quoteCurrency);
            $rate = $this->rateString($rate);
            $quoteConvention = trim($quoteConvention);
            $sourceReference = trim($sourceReference);
            $effectiveDate = Carbon::parse($effectiveDate)->toDateString();

            if ($baseCurrency === $quoteCurrency) {
                throw new DomainException('Exchange Rate evidence requires different base and quote currencies.');
            }
            if (!$this->isPositiveDecimal($rate)) {
                throw new DomainException('Exchange Rate evidence requires a positive rate.');
            }
            if ($quoteConvention === '' || $sourceReference === '') {
                throw new DomainException('Exchange Rate evidence requires quote convention and source reference.');
            }

            $identityHash = $this->identityHash($property->id, $baseCurrency, $quoteCurrency, $rate, $quoteConvention, $effectiveDate, $sourceReference, $actor->id);
            $existing = ExchangeRateEvidence::where('source_identity_hash', $identityHash)
                ->orWhere(function ($query) use ($property, $baseCurrency, $quoteCurrency, $quoteConvention, $effectiveDate, $sourceReference): void {
                    $query->where('property_id', $property->id)
                        ->where('base_currency', $baseCurrency)
                        ->where('quote_currency', $quoteCurrency)
                        ->where('quote_convention', $quoteConvention)
                        ->where('effective_date', $effectiveDate)
                        ->where('source_reference', $sourceReference);
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingRecordMatches($existing, $identityHash, $property->id, $baseCurrency, $quoteCurrency, $rate, $quoteConvention, $effectiveDate, $sourceReference, $actor->id);

                return $existing->fresh();
            }

            $evidence = new ExchangeRateEvidence([
                'property_id' => $property->id,
                'base_currency' => $baseCurrency,
                'quote_currency' => $quoteCurrency,
                'rate' => $rate,
                'quote_convention' => $quoteConvention,
                'effective_date' => $effectiveDate,
                'source_reference' => $sourceReference,
                'status' => ExchangeRateEvidenceStatusEnum::RECORDED,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => [
                    'contract' => self::CONTRACT,
                    'property_id' => $property->id,
                    'base_currency' => $baseCurrency,
                    'quote_currency' => $quoteCurrency,
                    'rate' => $rate,
                    'quote_convention' => $quoteConvention,
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

    public function approve(string $evidenceId, ?User $actor): ExchangeRateEvidence
    {
        return DB::transaction(function () use ($evidenceId, $actor): ExchangeRateEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);
            $evidence = ExchangeRateEvidence::whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $this->assertActorCanAccessProperty($actor, $evidence->property_id);

            if ($evidence->status === ExchangeRateEvidenceStatusEnum::APPROVED) {
                if ($evidence->approved_by === $actor->id) {
                    return $evidence->fresh();
                }

                throw new DomainException('Conflicting Exchange Rate approval evidence already exists.');
            }
            if ($evidence->status === ExchangeRateEvidenceStatusEnum::REJECTED) {
                throw new DomainException('Rejected Exchange Rate evidence cannot be approved.');
            }
            if ($evidence->recorded_by === $actor->id) {
                throw new AuthorizationException('Exchange Rate recorder cannot approve their own evidence.');
            }

            $evidence->status = ExchangeRateEvidenceStatusEnum::APPROVED;
            $evidence->approved_by = $actor->id;
            $evidence->approved_at = now();
            $evidence->updated_by = $actor->id;
            $evidence->save();

            return $evidence->fresh();
        });
    }

    public function reject(string $evidenceId, string $reason, ?User $actor): ExchangeRateEvidence
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Exchange Rate rejection requires a reason.');
        }

        return DB::transaction(function () use ($evidenceId, $reason, $actor): ExchangeRateEvidence {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);
            $evidence = ExchangeRateEvidence::whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $this->assertActorCanAccessProperty($actor, $evidence->property_id);

            if ($evidence->status === ExchangeRateEvidenceStatusEnum::REJECTED) {
                if ($evidence->rejected_by === $actor->id && $evidence->rejection_reason === $reason) {
                    return $evidence->fresh();
                }

                throw new DomainException('Conflicting Exchange Rate rejection evidence already exists.');
            }
            if ($evidence->status === ExchangeRateEvidenceStatusEnum::APPROVED) {
                throw new DomainException('Approved Exchange Rate evidence cannot be rejected.');
            }

            $evidence->status = ExchangeRateEvidenceStatusEnum::REJECTED;
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
            throw new AuthorizationException('Exchange Rate evidence requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)->where('is_active', true)->first();
        if (!$freshActor) {
            throw new AuthorizationException('Exchange Rate evidence requires an active actor.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Exchange Rate evidence permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Exchange Rate evidence permission is required.');
        }

        return $freshActor;
    }

    private function resolveProperty(string $propertyId): Property
    {
        $property = Property::query()->whereKey($propertyId)->where('is_active', true)->first();
        if (!$property) {
            throw new DomainException('Exchange Rate evidence requires active property scope.');
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
            throw new AuthorizationException('Exchange Rate evidence requires active property access.');
        }
    }

    private function assertExistingRecordMatches(
        ExchangeRateEvidence $existing,
        string $identityHash,
        string $propertyId,
        string $baseCurrency,
        string $quoteCurrency,
        string $rate,
        string $quoteConvention,
        string $effectiveDate,
        string $sourceReference,
        string $actorId
    ): void {
        if (
            $existing->source_identity_hash === $identityHash &&
            $existing->property_id === $propertyId &&
            $existing->base_currency === $baseCurrency &&
            $existing->quote_currency === $quoteCurrency &&
            $this->rateString($existing->rate) === $rate &&
            $existing->quote_convention === $quoteConvention &&
            $existing->effective_date?->toDateString() === $effectiveDate &&
            $existing->source_reference === $sourceReference &&
            $existing->recorded_by === $actorId
        ) {
            return;
        }

        throw new DomainException('Conflicting Exchange Rate evidence already exists.');
    }

    private function identityHash(
        string $propertyId,
        string $baseCurrency,
        string $quoteCurrency,
        string $rate,
        string $quoteConvention,
        string $effectiveDate,
        string $sourceReference,
        string $actorId
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $propertyId,
            $baseCurrency,
            $quoteCurrency,
            $rate,
            $quoteConvention,
            $effectiveDate,
            $sourceReference,
            $actorId,
        ]));
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw new DomainException('Exchange Rate evidence requires currency identity.');
        }

        return $currency;
    }

    private function rateString(mixed $rate): string
    {
        if (!is_string($rate)) {
            throw new DomainException('Exchange Rate evidence requires a canonical decimal-string rate.');
        }

        if ($rate !== trim($rate) || $rate === '') {
            throw new DomainException('Exchange Rate evidence requires a canonical decimal-string rate.');
        }

        if (!preg_match('/^(0|[1-9][0-9]*)\.([0-9]+)$/', $rate, $matches)) {
            throw new DomainException('Exchange Rate evidence requires a canonical decimal-string rate.');
        }

        $fraction = $matches[2];
        if (strlen($fraction) > self::RATE_SCALE) {
            throw new DomainException('Exchange Rate evidence rate scale exceeds configured precision.');
        }

        return $matches[1] . '.' . str_pad($fraction, self::RATE_SCALE, '0');
    }

    private function isPositiveDecimal(string $rate): bool
    {
        [$whole, $fraction] = explode('.', $rate, 2);

        return ltrim($whole, '0') !== '' || ltrim($fraction, '0') !== '';
    }
}
