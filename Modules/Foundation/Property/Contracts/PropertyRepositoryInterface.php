<?php

namespace Modules\Foundation\Property\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Property\Models\Property;

interface PropertyRepositoryInterface
{
    public function all(): Collection;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function find(string $id): Property;
    public function findBySlug(string $slug): Property;
    public function findByCode(string $code): Property;
    public function create(array $data): Property;
    public function update(string $id, array $data): Property;
    public function delete(string $id): bool;
}
