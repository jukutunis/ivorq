<?php

namespace Shared\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    public function all(): Collection;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function find(string $id): Model;
    public function findOrFail(string $id): Model;
    public function create(array $data): Model;
    public function update(string $id, array $data): Model;
    public function delete(string $id): bool;
}
