<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutFinalReviewService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-checkout-final-review.create';

    private const ALLOWED_STATUSES = ['CHECKOUT_FINAL_REVIEW_READY','CHECKOUT_FINAL_REVIEW_BLOCKED','CHECKOUT_FINAL_REVIEW_REVIEWED'];
    private const MAX_NOTE_LENGTH = 2000;

    public function create(User $actor, string $frontDeskStayId, string $finalReviewStatus, ?string $finalReviewNote, string $idempotencyKey): array
    {
        $propertyId = $this->authorizeCreate($actor);
        $this->validateStatus($finalReviewStatus);
        $this->validateNoteLength($finalReviewNote);

        return DB::transaction(function () use ($actor, $propertyId, $frontDeskStayId, $finalReviewStatus, $finalReviewNote, $idempotencyKey) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
                ->where('property_id', $propertyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return ['final_review' => $existing, 'replayed' => true];

            $latestB6 = $this->latestAuthorizationForStay($propertyId, $frontDeskStayId);
            $this->validateAgainstB6($finalReviewStatus, $latestB6);

            $occurredAt = Carbon::now();
            $sourceHash = hash('sha256', implode('|', [$frontDeskStayId, $finalReviewStatus, $finalReviewNote ?? '', $occurredAt->toISOString()]));

            $sourceDup = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
                ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)->where('source_hash', $sourceHash)->first();
            if ($sourceDup) return ['final_review' => $sourceDup, 'replayed' => true];

            $review = FrontDeskDepartureCheckoutFinalReview::create([
                'property_id' => $propertyId, 'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id, 'final_review_status' => $finalReviewStatus,
                'final_review_note' => $finalReviewNote, 'occurred_at' => $occurredAt,
                'created_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'source_hash' => $sourceHash,
            ]);
            return ['final_review' => $review, 'replayed' => false];
        });
    }

    private function lockStay(string $stayId, string $propertyId): FrontDeskStay
    {
        $stay = FrontDeskStay::withoutGlobalScopes()->whereKey($stayId)->where('property_id', $propertyId)->lockForUpdate()->first();
        if (!$stay) throw new DomainException('Front Desk stay not found for active property.');
        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) throw new DomainException('Departure checkout final review requires an IN_HOUSE stay.');
        return $stay;
    }

    private function latestAuthorizationForStay(string $propertyId, string $stayId): ?array
    {
        $e = FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->first();
        return $e ? ['id' => $e->id, 'authorization_status' => $e->authorization_status?->value, 'authorization_note' => $e->authorization_note] : null;
    }

    private function validateAgainstB6(string $status, ?array $latestB6): void
    {
        if ($status === 'CHECKOUT_FINAL_REVIEW_READY') {
            if ($latestB6 === null) throw new DomainException('CHECKOUT_FINAL_REVIEW_READY requires at least one FD-B6 checkout authorization. No authorization evidence found.');
            if ($latestB6['authorization_status'] !== 'CHECKOUT_AUTHORIZATION_READY') throw new DomainException('CHECKOUT_FINAL_REVIEW_READY requires the latest FD-B6 checkout authorization to be CHECKOUT_AUTHORIZATION_READY.');
        }
    }

    private function authorizeCreate(User $actor): string
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');
        $property = Property::withoutGlobalScopes()->whereKey($propertyId)->where('company_id', $companyId)->where('is_active', true)->first();
        if (!$property) throw new HttpException(403, 'Active property is required.');
        if (!$actor->can(self::CREATE_PERMISSION)) throw new HttpException(403, 'Front Desk departure checkout final review create permission is required.');
        return $propertyId;
    }

    private function validateStatus(string $s): void
    {
        if (!FrontDeskDepartureCheckoutFinalReviewStatusEnum::tryFrom($s) || !in_array($s, self::ALLOWED_STATUSES, true))
            throw new DomainException('Invalid final review status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES) . '.');
    }

    private function validateNoteLength(?string $n): void
    {
        if ($n !== null && mb_strlen($n) > self::MAX_NOTE_LENGTH) throw new DomainException('Final review note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.');
    }
}
