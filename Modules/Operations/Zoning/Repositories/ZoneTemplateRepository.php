<?php

namespace Modules\Operations\Zoning\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Shared\Exceptions\NotFoundException;

class ZoneTemplateRepository
{
    public function all(): Collection
    {
        return ZoneTemplate::latest()->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return ZoneTemplate::latest()->paginate($perPage);
    }

    public function find(string $id): ZoneTemplate
    {
        $template = ZoneTemplate::find($id);

        throw_if(! $template, new NotFoundException('ZoneTemplate'));

        return $template;
    }

    public function create(array $data): ZoneTemplate
    {
        return ZoneTemplate::create($data)->fresh();
    }

    public function update(string $id, array $data): ZoneTemplate
    {
        $template = $this->find($id);
        $template->update($data);

        return $template->fresh();
    }

    public function delete(string $id): bool
    {
        $template = $this->find($id);

        return $template->delete();
    }

    public function activeByType(ZoneTypeEnum $type): Collection
    {
        return ZoneTemplate::where('zone_type', $type)
            ->where('is_active', true)
            ->get();
    }
}
