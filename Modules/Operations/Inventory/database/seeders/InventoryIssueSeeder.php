<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryLocation;

class InventoryIssueSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $admin = User::whereHas('roles', fn($q) => $q->where('name', 'super-admin'))
            ->orWhereHas('roles', fn($q) => $q->where('name', 'property-admin'))
            ->first();

        $items     = InventoryItem::where('property_id', $property->id)->pluck('id', 'sku');
        $locations = InventoryLocation::where('property_id', $property->id)->pluck('id', 'name');

        $hkStore  = $locations['HK-STORE']   ?? null;
        $engStore = $locations['ENG-STORE']  ?? null;

        if ($items->isEmpty()) {
            $this->command->warn('InventoryIssueSeeder: items missing — run item seeder first.');
            return;
        }

        $issues = [
            [
                'issue_number'   => 'ISS-2024-001',
                'status'         => IssueStatusEnum::Posted->value,
                'issued_at'      => now()->subDays(25),
                'posted_by'      => $admin?->id,
                'posted_at'      => now()->subDays(25),
                'issued_to_type' => 'department',
                'remarks'        => 'Daily housekeeping amenity issue — Floor 1–5.',
                'location_id'    => $hkStore,
                'lines'          => [
                    ['sku' => 'HK-SOAP-001',  'qty' => 120],
                    ['sku' => 'HK-SHAMP-001', 'qty' =>  80],
                    ['sku' => 'HK-COND-001',  'qty' =>  80],
                    ['sku' => 'HK-TISSUE-001', 'qty' => 40],
                ],
            ],
            [
                'issue_number'   => 'ISS-2024-002',
                'status'         => IssueStatusEnum::Posted->value,
                'issued_at'      => now()->subDays(10),
                'posted_by'      => $admin?->id,
                'posted_at'      => now()->subDays(10),
                'issued_to_type' => 'department',
                'remarks'        => 'Engineering — corrective maintenance materials.',
                'location_id'    => $engStore,
                'lines'          => [
                    ['sku' => 'ENG-BULB-LED01', 'qty' =>  6],
                    ['sku' => 'ENG-TAPE-PTFE',  'qty' =>  5],
                    ['sku' => 'ENG-BATT-AA',    'qty' =>  4],
                ],
            ],
            [
                'issue_number'   => 'ISS-2024-003',
                'status'         => IssueStatusEnum::Draft->value,
                'issued_at'      => null,
                'posted_by'      => null,
                'posted_at'      => null,
                'issued_to_type' => 'department',
                'remarks'        => 'Laundry supply issue — pending supervisor approval.',
                'location_id'    => $locations['LAUNDRY-STR'] ?? null,
                'lines'          => [
                    ['sku' => 'LDY-DET-001',    'qty' => 2],
                    ['sku' => 'LDY-STARCH-001', 'qty' => 3],
                ],
            ],
        ];

        foreach ($issues as $issueData) {
            $lines      = $issueData['lines'];
            $locationId = $issueData['location_id'];
            unset($issueData['lines'], $issueData['location_id']);

            $issue = InventoryIssue::firstOrCreate(
                [
                    'property_id'  => $property->id,
                    'issue_number' => $issueData['issue_number'],
                ],
                array_merge($issueData, ['property_id' => $property->id])
            );

            if ($issue->wasRecentlyCreated && $locationId) {
                foreach ($lines as $line) {
                    $itemId = $items[$line['sku']] ?? null;
                    if (! $itemId) {
                        continue;
                    }

                    InventoryIssueLine::create([
                        'property_id' => $property->id,
                        'issue_id'    => $issue->id,
                        'item_id'     => $itemId,
                        'location_id' => $locationId,
                        'quantity'    => $line['qty'],
                    ]);
                }
            }
        }
    }
}
