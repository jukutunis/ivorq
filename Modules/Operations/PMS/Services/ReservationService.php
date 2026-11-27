<?php

namespace Modules\Operations\PMS\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Events\ReservationCancelled;
use Modules\Operations\PMS\Events\ReservationConfirmed;
use Modules\Operations\PMS\Events\ReservationCreated;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;

class ReservationService
{
    private const WRITABLE = ['primary_guest_id', 'rate_plan_id', 'arrival_date',
        'departure_date', 'adults', 'children', 'reservation_source', 'reserved_room_type', 'remarks'];

    public function __construct(
        private PmsPropertyAuthority $authority,
        private AvailabilityService $availabilityService,
        private ReservationMutationEvidence $mutationEvidence,
        private PmsServiceInput $input,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        [, $propertyId] = $this->authority->require('pms.reservation.view');

        return Reservation::query()->where('property_id', $propertyId)
            ->with(['primaryGuest', 'ratePlan'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['reservation_source'] ?? null, fn ($q, $v) => $q->where('reservation_source', $v))
            ->when($filters['reserved_room_type'] ?? null, fn ($q, $v) => $q->where('reserved_room_type', $v))
            ->when($filters['arrival_date'] ?? null, fn ($q, $v) => $q->whereDate('arrival_date', $v))
            ->when($filters['departure_date'] ?? null, fn ($q, $v) => $q->whereDate('departure_date', $v))
            ->latest()->paginate(min(max($perPage, 1), 100));
    }

    public function find(string $id): Reservation
    {
        [, $propertyId] = $this->authority->require('pms.reservation.view');

        return Reservation::query()->where('property_id', $propertyId)
            ->with(['primaryGuest', 'guests', 'ratePlan'])->findOrFail($id);
    }

    public function capabilities(Reservation $reservation): array
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.view');
        abort_unless($reservation->property_id === $propertyId, 404);
        $evidence = $this->hasEvidence($reservation, $propertyId);
        $editable = $this->authority->hasPermission($actor, $propertyId, 'pms.reservation.edit') && ! $evidence;

        return [
            'can_edit' => $editable && in_array($reservation->status, [
                ReservationStatusEnum::Tentative, ReservationStatusEnum::Confirmed,
                ReservationStatusEnum::Waitlisted], true),
            'can_confirm' => $editable && $reservation->status->canTransitionTo(ReservationStatusEnum::Confirmed),
            'can_cancel' => $editable && $reservation->status->canTransitionTo(ReservationStatusEnum::Cancelled),
            'can_no_show' => $editable && $reservation->status->canTransitionTo(ReservationStatusEnum::NoShow),
            'can_manage_guests' => $editable && $this->authority->hasPermission($actor, $propertyId, 'pms.guest.view'),
            'has_operational_evidence' => $evidence,
        ];
    }

    public function create(array $data): Reservation
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.create');
        $data = $this->withNights($this->input->reservation($this->allowed($data), true));
        if (($data['reservation_source'] ?? null) === null) {
            unset($data['reservation_source']);
        }
        $reservation = DB::transaction(function () use ($actor, $propertyId, $data) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $this->validateReferences($data, $propertyId);
            $max = Reservation::query()->withTrashed()->where('property_id', $propertyId)
                ->pluck('reservation_number')->reduce(function (int $max, string $number): int {
                    return preg_match('/^RES-(\d+)$/', $number, $matches)
                        ? max($max, (int) $matches[1]) : $max;
                }, 0);
            $reservation = Reservation::create($data + [
                'property_id' => $propertyId,
                'reservation_number' => sprintf('RES-%05d', $max + 1),
                'status' => ReservationStatusEnum::Tentative->value,
            ]);
            $this->syncPrimaryGuest($reservation, $reservation->primary_guest_id);
            $this->audit('pms_reservation_created', $reservation, $actor->id);

            return $reservation->fresh();
        });
        event(new ReservationCreated($reservation));

        return $reservation;
    }

    public function update(string $id, array $data): Reservation
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');
        $data = $this->input->reservation($this->allowed($data), false);

        return DB::transaction(function () use ($id, $data, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = $this->locked($id, $propertyId);
            $this->assertNoEvidence($reservation, $propertyId);
            abort_unless(in_array($reservation->status, [ReservationStatusEnum::Tentative,
                ReservationStatusEnum::Confirmed, ReservationStatusEnum::Waitlisted], true), 409);
            $merged = array_merge([
                'primary_guest_id' => $reservation->primary_guest_id,
                'rate_plan_id' => $reservation->rate_plan_id,
                'arrival_date' => $reservation->arrival_date->toDateString(),
                'departure_date' => $reservation->departure_date->toDateString(),
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'reservation_source' => $reservation->reservation_source->value,
                'reserved_room_type' => $reservation->reserved_room_type->value,
                'remarks' => $reservation->remarks,
            ], $data);
            $merged = $this->input->reservation($merged, true);
            $this->validateReferences($merged, $propertyId);
            $data['nights'] = $this->withNights($merged)['nights'];
            if ($reservation->status === ReservationStatusEnum::Confirmed &&
                (isset($data['arrival_date']) || isset($data['departure_date']) || isset($data['reserved_room_type']))) {
                $candidate = clone $reservation;
                $candidate->forceFill($data);
                $this->validateAvailabilityBeforeConfirm($candidate);
            }
            $previousPrimary = $reservation->primary_guest_id;
            $reservation->update($data);
            if (array_key_exists('primary_guest_id', $data)) {
                $this->syncPrimaryGuest($reservation, $data['primary_guest_id'], $previousPrimary);
            }
            $this->audit('pms_reservation_amended', $reservation, $actor->id, ['fields' => array_keys($data)]);

            return $reservation->fresh();
        });
    }

    public function delete(string $id): bool
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.delete');

        return DB::transaction(function () use ($id, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = $this->locked($id, $propertyId);
            $this->assertNoEvidence($reservation, $propertyId);
            abort_unless($reservation->status === ReservationStatusEnum::Tentative, 409);
            $this->audit('pms_reservation_deleted', $reservation, $actor->id);

            return $reservation->delete();
        });
    }

    public function confirm(string $id): Reservation
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');
        $reservation = DB::transaction(function () use ($id, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = $this->locked($id, $propertyId);
            $this->transition($reservation, ReservationStatusEnum::Confirmed);
            $this->assertNoEvidence($reservation, $propertyId);
            $this->validateAvailabilityBeforeConfirm($reservation);
            $reservation->update(['status' => ReservationStatusEnum::Confirmed]);
            $this->audit('pms_reservation_confirmed', $reservation, $actor->id);

            return $reservation->fresh();
        });
        event(new ReservationConfirmed($reservation));

        return $reservation;
    }

    public function cancel(string $id, ?string $reason = null): Reservation
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');
        $reservation = DB::transaction(function () use ($id, $reason, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = $this->locked($id, $propertyId);
            $this->transition($reservation, ReservationStatusEnum::Cancelled);
            $this->assertNoEvidence($reservation, $propertyId);
            $reservation->update(['status' => ReservationStatusEnum::Cancelled]);
            $this->audit('pms_reservation_cancelled', $reservation, $actor->id,
                ['reason' => $reason === null ? null : mb_substr(trim($reason), 0, 1000)]);

            return $reservation->fresh();
        });
        event(new ReservationCancelled($reservation, null));

        return $reservation;
    }

    public function noShow(string $id): Reservation
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');

        return DB::transaction(function () use ($id, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = $this->locked($id, $propertyId);
            $this->transition($reservation, ReservationStatusEnum::NoShow);
            $this->assertNoEvidence($reservation, $propertyId);
            $reservation->update(['status' => ReservationStatusEnum::NoShow]);
            $this->audit('pms_reservation_no_show', $reservation, $actor->id);

            return $reservation->fresh();
        });
    }

    public function assignRoom(string $id, string $roomId): Reservation
    {
        $this->authority->require('pms.reservation.edit');
        abort(409, 'Use the controlled Front Desk room assignment workspace.');
    }

    public function validateAvailabilityBeforeConfirm(Reservation $reservation): void
    {
        if ($reservation->reserved_room_type === null) {
            return;
        }
        if (! $this->availabilityService->isAvailable(
            $reservation->reserved_room_type->value,
            $reservation->arrival_date->toDateString(),
            $reservation->departure_date->toDateString(),
            $reservation->id,
            $reservation->property_id,
        )) {
            throw ValidationException::withMessages(['availability' => ['No rooms available for the requested type and dates.']]);
        }
    }

    private function allowed(array $data): array
    {
        if (array_diff(array_keys($data), self::WRITABLE) !== []) {
            throw ValidationException::withMessages(['reservation' => ['Unsupported reservation fields.']]);
        }

        return $data;
    }

    private function withNights(array $data): array
    {
        if (empty($data['arrival_date']) || empty($data['departure_date'])) {
            throw ValidationException::withMessages(['dates' => ['Arrival and departure dates are required.']]);
        }
        try {
            $arrival = CarbonImmutable::createFromFormat('!Y-m-d', $data['arrival_date']);
            $departure = CarbonImmutable::createFromFormat('!Y-m-d', $data['departure_date']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['dates' => ['Valid arrival and departure dates are required.']]);
        }
        $nights = $arrival->diffInDays($departure, false);
        if ($nights < 1 || $nights > 65535) {
            throw ValidationException::withMessages(['dates' => ['Departure must follow arrival.']]);
        }
        $data['nights'] = (int) $nights;

        return $data;
    }

    private function validateReferences(array $data, string $propertyId): void
    {
        if (isset($data['primary_guest_id']) && ! Guest::query()->where('property_id', $propertyId)
            ->whereKey($data['primary_guest_id'])->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['primary_guest_id' => ['Invalid guest.']]);
        }
        if (isset($data['rate_plan_id']) && ! RatePlan::query()->where('property_id', $propertyId)
            ->whereKey($data['rate_plan_id'])->exists()) {
            throw ValidationException::withMessages(['rate_plan_id' => ['Invalid rate plan.']]);
        }
    }

    private function locked(string $id, string $propertyId): Reservation
    {
        return Reservation::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
    }

    private function syncPrimaryGuest(Reservation $reservation, string $guestId, ?string $previousPrimary = null): void
    {
        abort_if(DB::table('reservation_guests')->where('reservation_id', $reservation->id)
            ->where('property_id', '!=', $reservation->property_id)->exists(), 409,
            'Reservation guest relationship has an invalid Property.');
        $primaryRows = DB::table('reservation_guests')->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)->where('is_primary', true)->pluck('guest_id');
        abort_if($primaryRows->count() > 1 || ($previousPrimary !== null && $primaryRows->isNotEmpty()
            && $primaryRows->first() !== $previousPrimary), 409,
            'Reservation primary guest relationship is inconsistent.');
        $existing = DB::table('reservation_guests')->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('guest_id', $guestId)->first();
        abort_if($existing && $existing->is_primary && $reservation->primary_guest_id !== $guestId, 409);
        $rows = DB::table('reservation_guests')->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id);
        $rows->where('is_primary', true)->where('guest_id', '!=', $guestId)->delete();
        if ($existing) {
            $updated = DB::table('reservation_guests')->where('id', $existing->id)
                ->where('property_id', $reservation->property_id)
                ->where('reservation_id', $reservation->id)->where('guest_id', $guestId)
                ->update(['is_primary' => true, 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
        } else {
            DB::table('reservation_guests')->insert([
                'id' => (string) Str::ulid(), 'property_id' => $reservation->property_id,
                'reservation_id' => $reservation->id, 'guest_id' => $guestId,
                'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function assertNoEvidence(Reservation $reservation, string $propertyId): void
    {
        abort_if($this->hasEvidence($reservation, $propertyId), 409,
            'Operational or financial evidence prevents this reservation change.');
    }

    private function hasEvidence(Reservation $reservation, string $propertyId): bool
    {
        return $this->mutationEvidence->exists($propertyId, $reservation->id);
    }

    private function transition(Reservation $reservation, ReservationStatusEnum $to): void
    {
        if (! $reservation->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => ['Reservation status transition is not allowed.']]);
        }
    }

    private function audit(string $event, Reservation $reservation, string $actorId, array $values = []): void
    {
        AuditLog::record([
            'property_id' => $reservation->property_id, 'user_id' => $actorId,
            'event' => $event, 'auditable_type' => Reservation::class,
            'auditable_id' => $reservation->id, 'new_values' => $values, 'tags' => ['pms-p01'],
        ]);
    }
}
