<?php

namespace Modules\Operations\Zoning\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Events\ZoneCreated;
use Modules\Operations\Zoning\Events\ZoneStatusChanged;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Zoning\Repositories\ZoneRepository;
use Modules\Operations\Zoning\Repositories\ZoneTemplateRepository;

class ZoneService
{
    public function __construct(
        private ZoneRepository         $zoneRepository,
        private ZoneTemplateRepository $zoneTemplateRepository,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->zoneRepository->paginate($perPage);
    }

    public function find(string $id): Zone
    {
        return $this->zoneRepository->find($id);
    }

    public function create(array $data): Zone
    {
        $zone = $this->zoneRepository->create($data);

        event(new ZoneCreated($zone));

        return $zone;
    }

    /**
     * Update zone fields. Status changes are not allowed here — use changeStatus().
     * Any 'status' key in $data is stripped before persisting.
     */
    public function update(string $id, array $data): Zone
    {
        unset($data['status']);

        return $this->zoneRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->zoneRepository->delete($id);
    }

    /**
     * Transition a zone to a new status.
     *
     * Validates the transition against ZoneStatusEnum::canTransitionTo() before
     * persisting. Throws ValidationException for any invalid transition, including
     * all transitions out of the terminal 'archived' state.
     */
    public function changeStatus(string $id, ZoneStatusEnum $newStatus, ?string $remarks = null): Zone
    {
        $zone = $this->zoneRepository->findOrFail($id);
        $from = $zone->status;

        if (! $from->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition zone from {$from->label()} to {$newStatus->label()}.",
                ],
            ]);
        }

        $zone->update(['status' => $newStatus]);

        event(new ZoneStatusChanged($zone->fresh(), $from, $newStatus, $remarks));

        return $zone->fresh();
    }

    /**
     * Create a zone pre-populated with defaults from a template.
     *
     * Template supplies zone_type, priority, and description as defaults.
     * Any key present in $overrides takes precedence over the template value.
     * zone_code, zone_name, and property_id must be supplied in $overrides.
     */
    public function createFromTemplate(string $templateId, array $overrides = []): Zone
    {
        $template = $this->zoneTemplateRepository->find($templateId);

        $data = array_merge([
            'zone_type'   => $template->zone_type->value,
            'priority'    => $template->default_priority->value,
            'description' => $template->description,
        ], $overrides);

        return $this->create($data);
    }
}
