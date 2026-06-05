<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Models\Guest;
use Shared\Exceptions\NotFoundException;

class GuestRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Guest::latest();

        if (! empty($filters['guest_type'])) {
            $query->where('guest_type', $filters['guest_type']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('full_name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('guest_code', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (! empty($filters['vip_level'])) {
            $query->where('vip_level', $filters['vip_level']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Guest
    {
        $guest = Guest::with([
            'reservations.ratePlan',
            'folios',
            'stays.room',
        ])->find($id);

        throw_if(! $guest, new NotFoundException('Guest'));

        return $guest;
    }

    public function findOrFail(string $id): Guest
    {
        return Guest::findOrFail($id);
    }

    public function create(array $data): Guest
    {
        return Guest::create($data)->fresh();
    }

    public function update(string $id, array $data): Guest
    {
        $guest = $this->find($id);
        $guest->update($data);

        return $guest->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function findByCode(string $code): ?Guest
    {
        return Guest::where('guest_code', $code)->first();
    }

    public function vip(): Collection
    {
        return Guest::whereNotNull('vip_level')
            ->orderBy('vip_level')
            ->orderBy('full_name')
            ->get();
    }
}
