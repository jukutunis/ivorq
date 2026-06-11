<?php

namespace Modules\Operations\Engineering\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderPriorityEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderTypeEnum;

class WorkOrderSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $workOrders = [
            [
                'wo_number'          => 'WO-0001',
                'title'              => 'AC Unit Not Cooling — Room 101',
                'description'        => 'Guest reported air conditioning unit in room 101 is running but not producing cold air.',
                'type'               => WorkOrderTypeEnum::Corrective,
                'priority'           => WorkOrderPriorityEnum::High,
                'status'             => WorkOrderStatusEnum::Open,
                'priority_score'     => 50,
            ],
            [
                'wo_number'          => 'WO-0002',
                'title'              => 'Water Leak — Bathroom Ceiling',
                'description'        => 'Water leak detected through bathroom ceiling in Room 203.',
                'type'               => WorkOrderTypeEnum::Emergency,
                'priority'           => WorkOrderPriorityEnum::Emergency,
                'status'             => WorkOrderStatusEnum::Open,
                'priority_score'     => 100,
            ],
        ];

        foreach ($workOrders as $data) {
            WorkOrder::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'wo_number'   => $data['wo_number'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}
