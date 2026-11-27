<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;

class GuestService
{
    private const WRITABLE = ['full_name', 'email', 'phone', 'nationality',
        'id_type', 'id_number', 'guest_type', 'vip_level', 'notes'];

    public function __construct(private PmsPropertyAuthority $authority, private ReservationMutationEvidence $mutationEvidence, private PmsServiceInput $input) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        [, $propertyId] = $this->authority->require('pms.guest.view');

        return Guest::query()->where('property_id', $propertyId)
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('full_name', 'ilike', '%'.$term.'%')
                    ->orWhere('guest_code', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%');
            }))
            ->when($filters['guest_type'] ?? null, fn ($q, $v) => $q->where('guest_type', $v))
            ->when($filters['vip_level'] ?? null, fn ($q, $v) => $q->where('vip_level', $v))
            ->latest()->paginate(min(max($perPage, 1), 100));
    }

    public function find(string $id): Guest
    {
        [, $propertyId] = $this->authority->require('pms.guest.view');

        return Guest::query()->where('property_id', $propertyId)->findOrFail($id);
    }

    public function create(array $data): Guest
    {
        [$actor, $propertyId] = $this->authority->require('pms.guest.create');
        $data = $this->input->guest($this->allowed($data), true);
        if (($data['guest_type'] ?? null) === null) {
            unset($data['guest_type']);
        }

        return DB::transaction(function () use ($actor, $propertyId, $data) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $max = Guest::query()->withTrashed()->where('property_id', $propertyId)
                ->pluck('guest_code')->reduce(function (int $max, string $code): int {
                    return preg_match('/^GST-(\d+)$/', $code, $matches)
                        ? max($max, (int) $matches[1]) : $max;
                }, 0);
            $guest = Guest::create($data + [
                'property_id' => $propertyId,
                'guest_code' => sprintf('GST-%05d', $max + 1),
            ]);
            $this->audit('pms_guest_created', $guest, $actor->id);

            return $guest->fresh();
        });
    }

    public function update(string $id, array $data): Guest
    {
        [$actor, $propertyId] = $this->authority->require('pms.guest.edit');
        $data = $this->input->guest($this->allowed($data), false);

        return DB::transaction(function () use ($id, $data, $actor, $propertyId) {
            $guest = Guest::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
            $guest->update($data);
            $this->audit('pms_guest_amended', $guest, $actor->id, ['fields' => array_keys($data)]);

            return $guest->fresh();
        });
    }

    public function delete(string $id): bool
    {
        [$actor, $propertyId] = $this->authority->require('pms.guest.delete');

        return DB::transaction(function () use ($id, $actor, $propertyId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $guest = Guest::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
            $hasHistory = DB::table('reservations')->where('property_id', $propertyId)->where('primary_guest_id', $id)->exists()
                || DB::table('reservation_guests')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('stays')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('front_desk_stays')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('folios')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('guest_payment_transactions')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('guest_deposit_transactions')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('guest_refund_transactions')->where('property_id', $propertyId)->where('guest_id', $id)->exists()
                || DB::table('guest_ar_transfer_requests')->where('property_id', $propertyId)->where('guest_id', $id)->exists();
            abort_if($hasHistory, 409, 'Guest has retained operational or financial evidence.');
            $this->audit('pms_guest_deleted', $guest, $actor->id);

            return $guest->delete();
        });
    }

    public function addAccompanyingGuest(string $reservationId, string $guestId): void
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');
        DB::transaction(function () use ($actor, $propertyId, $reservationId, $guestId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = DB::table('reservations')->where('property_id', $propertyId)
                ->where('id', $reservationId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($reservation, 404);
            $this->assertPivotProperty($reservationId, $propertyId);
            abort_if($reservation->primary_guest_id === $guestId, 409, 'Primary guest is already linked.');
            abort_unless(Guest::query()->where('property_id', $propertyId)->whereKey($guestId)->lockForUpdate()->first(), 422);
            $this->assertRelationshipMutable($reservation, $propertyId);
            abort_if(DB::table('reservation_guests')->where('property_id', $propertyId)->where('reservation_id', $reservationId)
                ->where('guest_id', $guestId)->exists(), 409, 'Guest is already linked.');
            DB::table('reservation_guests')->insert([
                'id' => (string) Str::ulid(), 'property_id' => $propertyId,
                'reservation_id' => $reservationId, 'guest_id' => $guestId,
                'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::record(['property_id' => $propertyId, 'user_id' => $actor->id,
                'event' => 'pms_reservation_guest_linked', 'auditable_type' => Reservation::class,
                'auditable_id' => $reservationId, 'new_values' => ['guest_id' => $guestId], 'tags' => ['pms-p01']]);
        });
    }

    public function removeAccompanyingGuest(string $reservationId, string $guestId): void
    {
        [$actor, $propertyId] = $this->authority->require('pms.reservation.edit');
        DB::transaction(function () use ($actor, $propertyId, $reservationId, $guestId) {
            DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first();
            $reservation = DB::table('reservations')->where('property_id', $propertyId)
                ->where('id', $reservationId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($reservation, 404);
            $this->assertPivotProperty($reservationId, $propertyId);
            abort_if($reservation->primary_guest_id === $guestId, 409);
            abort_unless(Guest::query()->where('property_id', $propertyId)->whereKey($guestId)->lockForUpdate()->first(), 422);
            $this->assertRelationshipMutable($reservation, $propertyId);
            $count = DB::table('reservation_guests')->where('property_id', $propertyId)
                ->where('reservation_id', $reservationId)->where('guest_id', $guestId)
                ->where('is_primary', false)->delete();
            abort_unless($count === 1, 404);
            AuditLog::record(['property_id' => $propertyId, 'user_id' => $actor->id,
                'event' => 'pms_reservation_guest_unlinked', 'auditable_type' => Reservation::class,
                'auditable_id' => $reservationId, 'new_values' => ['guest_id' => $guestId], 'tags' => ['pms-p01']]);
        });
    }

    public function vip(): Collection
    {
        [, $propertyId] = $this->authority->require('pms.guest.view');

        return Guest::query()->where('property_id', $propertyId)->whereNotNull('vip_level')->get();
    }

    public function findByCode(string $code): ?Guest
    {
        [, $propertyId] = $this->authority->require('pms.guest.view');

        return Guest::query()->where('property_id', $propertyId)->where('guest_code', $code)->first();
    }

    private function allowed(array $data): array
    {
        if (array_diff(array_keys($data), self::WRITABLE) !== []) {
            throw ValidationException::withMessages(['guest' => ['Unsupported guest fields.']]);
        }

        return $data;
    }

    private function assertRelationshipMutable(object $reservation, string $propertyId): void
    {
        abort_unless(in_array($reservation->status, ['tentative', 'confirmed', 'waitlisted'], true), 409);
        abort_if($this->mutationEvidence->exists($propertyId, $reservation->id), 409,
            'Operational or financial evidence prevents guest relationship changes.');
    }

    private function assertPivotProperty(string $reservationId, string $propertyId): void
    {
        abort_if(DB::table('reservation_guests')->where('reservation_id', $reservationId)
            ->where('property_id', '!=', $propertyId)->exists(), 409,
            'Reservation guest relationship has an invalid Property.');
    }

    private function audit(string $event, Guest $guest, string $actorId, array $values = []): void
    {
        AuditLog::record(['property_id' => $guest->property_id, 'user_id' => $actorId,
            'event' => $event, 'auditable_type' => Guest::class,
            'auditable_id' => $guest->id, 'new_values' => $values, 'tags' => ['pms-p01']]);
    }
}
