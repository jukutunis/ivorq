<?php

namespace Modules\Foundation\Property\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Property\Models\Company;
use Shared\Exceptions\NotFoundException;

class CompanyRepository
{
    public function all(): Collection
    {
        return Company::with('properties')->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Company::withCount('properties')->latest()->paginate($perPage);
    }

    public function find(string $id): Company
    {
        return Company::with('properties')->findOrFail($id);
    }

    public function create(array $data): Company
    {
        return Company::create($data);
    }

    public function update(string $id, array $data): Company
    {
        $company = $this->find($id);
        $company->update($data);

        return $company->fresh();
    }

    public function delete(string $id): bool
    {
        $company = $this->find($id);

        return $company->delete();
    }
}
