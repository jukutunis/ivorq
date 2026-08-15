<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use RuntimeException;

class ControlledIssuePostingService
{
    public function __construct(
        private readonly InventoryLedgerPostingService $ledgerPostingService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function post(InventoryIssue $issue, string $actorId): InventoryIssue
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($actorId);

        if (!Gate::forUser($user)->check('post', $issue)) {
            throw new RuntimeException('Actor does not have permission to post issues.');
        }

        $propertyId = $issue->property_id;
        $companyId = DB::table('properties')->where('id', $propertyId)->value('company_id');

        $this->confirmationService->requireValidConfirmation(
            $user,
            'inventory-issue-posting',
            $companyId,
            $propertyId
        );

        if ($issue->lines->isEmpty()) {
            throw new RuntimeException('Issue must have at least one line to post.');
        }

        return DB::transaction(function () use ($issue, $actorId) {
            $correlationId = (string) Str::ulid();
            $unitId = $this->defaultUnitId($issue->property_id);

            foreach ($issue->lines as $line) {
                $itemId = $line->item_id;
                $locId = $line->location_id;
                $qty = (float) ($line->quantity ?? 0);

                if ($qty <= 0) {
                    throw new RuntimeException('Issue quantity must be positive.');
                }

                $this->ledgerPostingService->post([
                    'property_id' => $issue->property_id,
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $locId,
                    'inventory_unit_id' => $unitId,
                    'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
                    'direction' => InventoryMovementDirectionEnum::Out,
                    'source_leg' => InventoryMovementSourceLegEnum::Primary,
                    'quantity' => $qty,
                    'source_domain' => 'inventory',
                    'source_type' => InventoryIssueLine::class,
                    'source_id' => $line->id,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => "iss_post_{$issue->id}_{$line->id}",
                    'occurred_at' => now(),
                    'created_by' => $actorId,
                ]);
            }

            return $issue;
        });
    }

    private function defaultUnitId(string $propertyId): string
    {
        $unit = InventoryUnit::where('property_id', $propertyId)->first();
        if (!$unit) {
            throw new RuntimeException('No inventory unit found for property.');
        }
        return $unit->id;
    }
}
