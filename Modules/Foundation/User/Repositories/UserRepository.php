<?php

namespace Modules\Foundation\User\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\User\Models\User;
use Shared\Exceptions\NotFoundException;

class UserRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return User::with(['department', 'position'])
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): User
    {
        $user = User::with(['department', 'position', 'roles'])->find($id);

        throw_if(!$user, new NotFoundException('User'));

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByEmailAndCompany(string $email, string $companyId): ?User
    {
        return User::where('email', $email)
            ->whereHas('properties', function ($query) use ($companyId) {
                $query->where('company_id', $companyId)
                      ->where('properties.is_active', true)
                      ->where('property_user.status', 'active');
            })->first();
    }

    public function allForProperty(string $propertyId): Collection
    {
        return User::forProperty($propertyId)
            ->with(['department', 'position'])
            ->get();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(string $id, array $data): User
    {
        $user = $this->find($id);
        $user->update($data);

        return $user->fresh();
    }

    public function delete(string $id): bool
    {
        $user = $this->find($id);

        return $user->delete();
    }
}
