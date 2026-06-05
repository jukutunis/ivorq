<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Repositories\GuestRepository;

class GuestService
{
    public function __construct(
        private GuestRepository $guestRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->guestRepository->paginate($filters, $perPage);
    }

    public function find(string $id): Guest
    {
        return $this->guestRepository->find($id);
    }

    public function create(array $data): Guest
    {
        return $this->guestRepository->create($data);
    }

    public function update(string $id, array $data): Guest
    {
        return $this->guestRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->guestRepository->delete($id);
    }

    public function vip(): Collection
    {
        return $this->guestRepository->vip();
    }

    public function findByCode(string $code): ?Guest
    {
        return $this->guestRepository->findByCode($code);
    }
}
