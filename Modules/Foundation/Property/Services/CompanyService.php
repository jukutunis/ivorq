<?php

namespace Modules\Foundation\Property\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Repositories\CompanyRepository;

class CompanyService
{
    public function __construct(
        private CompanyRepository $companyRepository
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->companyRepository->paginate($perPage);
    }

    public function find(string $id): Company
    {
        return $this->companyRepository->find($id);
    }

    public function create(array $data): Company
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        return $this->companyRepository->create($data);
    }

    public function update(string $id, array $data): Company
    {
        return $this->companyRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->companyRepository->delete($id);
    }
}
