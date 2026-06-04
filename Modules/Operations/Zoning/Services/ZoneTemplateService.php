<?php

namespace Modules\Operations\Zoning\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Modules\Operations\Zoning\Repositories\ZoneTemplateRepository;

class ZoneTemplateService
{
    public function __construct(
        private ZoneTemplateRepository $repo,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repo->paginate($perPage);
    }

    public function find(string $id): ZoneTemplate
    {
        return $this->repo->find($id);
    }

    public function create(array $data): ZoneTemplate
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): ZoneTemplate
    {
        return $this->repo->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->repo->delete($id);
    }
}
