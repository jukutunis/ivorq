<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutSettlementReadinessProjection;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestLedgerCheckoutSettlementReadinessProjectionService
{
    public const VIEW_PERMISSION = 'pms.guest-ledger.settlement-readiness.view';
    public const PROJECTION_VERSION = 'GLF-D-1.2';
    private const STABLE_ERROR_NESTED_TX = 'GLF_D_REQUIRES_TOP_LEVEL_READ_TRANSACTION';

    public function __construct(
        private readonly CurrentPropertyService                        $currentProperty,
        private readonly GuestLedgerCheckoutFinancialEvaluationService $evaluator,
        private readonly GuestLedgerPostingCompletenessReadPort         $postingCompletenessPort,
        private readonly GuestLedgerSettlementHoldReadPort              $settlementHoldPort,
        private readonly GuestLedgerCompletedSettlementConflictReadPort $completedSettlementPort,
    ) {}

    public function project(User $actor, string $frontDeskStayId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        $propertyId = $this->resolveCurrentProperty();
        $this->guardActor($actor, $propertyId);

        if (DB::transactionLevel() > 0) {
            throw new DomainException(self::STABLE_ERROR_NESTED_TX);
        }

        return DB::transaction(function () use ($actor, $frontDeskStayId, $propertyId) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $this->evaluateProjection($frontDeskStayId, $propertyId);
        });
    }

    private function evaluateProjection(string $frontDeskStayId, string $propertyId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        // Stay existence check — preserves original NotFoundException behavior
        $stay = FrontDeskStay::withoutGlobalScope('property')
            ->where('id', $frontDeskStayId)->where('property_id', $propertyId)->first();
        if (! $stay) { throw new NotFoundException('FrontDeskStay'); }

        $result = $this->evaluator->evaluateSnapshot(
            $frontDeskStayId,
            $propertyId,
            $this->postingCompletenessPort,
            $this->settlementHoldPort,
            $this->completedSettlementPort,
        );

        $statusValue = $result['status_value'];
        $status = match ($statusValue) {
            'PMS_TERMINAL_FINANCIAL_READY' => GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady,
            'PMS_TERMINAL_FINANCIAL_BLOCKED' => GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked,
            'PMS_TERMINAL_FINANCIAL_REVIEW_REQUIRED' => GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReviewRequired,
            default => GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
        };

        $sourceIds = $result['source_ids'];
        $sourceIds['property_id'] = $propertyId;
        $sourceIds['front_desk_stay_id'] = $frontDeskStayId;
        $sourceIds['reservation_id'] = $result['reservation_id'];
        $sourceIds['guest_id'] = $result['guest_id'];
        $sourceIds['folio_ids'] = $result['folio_ids'];

        $fingerprint = $this->evaluator->buildSnapshotFingerprint(
            $propertyId, $frontDeskStayId, $result['reservation_id'], $result['guest_id'],
            $result['folio_facts'], $result['payment_facts'], $result['deposit_facts'],
            $result['refund_facts'], $result['ar_facts'], $result['port_facts'],
            $result['currency'], $statusValue,
        );

        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: $status,
            property_id: $propertyId,
            front_desk_stay_id: $frontDeskStayId,
            reservation_id: $result['reservation_id'],
            guest_id: $result['guest_id'],
            folio_ids: $result['folio_ids'],
            folio_count: $result['folio_count'],
            canonical_aggregate_balance: $result['canonical_aggregate_balance'],
            currency: $result['currency'],
            blocker_codes: $result['blocker_codes'],
            blocker_messages: $result['blocker_messages'],
            review_reasons: $result['review_reasons'],
            evidence_unavailable_codes: $result['evidence_unavailable_codes'],
            markers: $result['markers'],
            evaluated_at: $result['evaluated_at'],
            source_fingerprint: $fingerprint,
            source_identifiers: $sourceIds,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Authorization
    // ═════════════════════════════════════════════════════════════════════

    private function resolveCurrentProperty(): string
    {
        $id = session('active_property_id') ?? session('current_property_id')
            ?? $this->currentProperty->resolveOrFail();
        $this->currentProperty->setPropertyId($id);
        return $id;
    }

    private function guardActor(User $actor, string $propertyId): void
    {
        if (! auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException('Actor identity does not match.');
        }
        $fresh = User::whereKey($actor->id)->where('is_active', true)->first();
        if (! $fresh) { throw new AuthorizationException('Active actor required.'); }
        $has = $fresh->properties()->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')->exists();
        if (! $has) { throw new AuthorizationException('Active property membership required.'); }
        try { $ok = $fresh->can(self::VIEW_PERMISSION); } catch (Throwable) { $ok = false; }
        if (! $ok) { throw new AuthorizationException('Permission required.'); }
    }
}
