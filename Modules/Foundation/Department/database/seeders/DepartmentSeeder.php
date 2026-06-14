<?php

namespace Modules\Foundation\Department\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Shared\Enums\DepartmentTypeEnum;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::withoutGlobalScope('property')->where('code', 'IGH')->first();

        if (! $property) {
            return;
        }

        $departments = [
            ['code' => 'FO',  'name' => 'Front Office', 'type' => DepartmentTypeEnum::Operational, 'cost_center' => 'FO-001'],
            ['code' => 'HK',  'name' => 'Housekeeping', 'type' => DepartmentTypeEnum::Operational, 'cost_center' => 'HSKP-001'],
            ['code' => 'ENG', 'name' => 'Engineering', 'type' => DepartmentTypeEnum::Operational, 'cost_center' => 'ENG-001'],
            ['code' => 'FB',  'name' => 'Food & Beverage', 'type' => DepartmentTypeEnum::Operational, 'cost_center' => 'FB-001'],
            ['code' => 'FIN', 'name' => 'Finance', 'type' => DepartmentTypeEnum::Administrative, 'cost_center' => 'FIN-001'],
            ['code' => 'HR',  'name' => 'Human Resources', 'type' => DepartmentTypeEnum::Administrative, 'cost_center' => 'HR-001'],
            ['code' => 'SEC', 'name' => 'Security', 'type' => DepartmentTypeEnum::Support, 'cost_center' => 'SEC-001'],
            ['code' => 'IT',  'name' => 'Information Technology', 'type' => DepartmentTypeEnum::Support, 'cost_center' => 'IT-001'],
        ];

        foreach ($departments as $data) {
            Department::create([
                'property_id' => $property->id,
                'name'        => $data['name'],
                'code'        => $data['code'],
                'type'        => $data['type'],
                'cost_center_code' => $data['cost_center'],
                'is_active'   => true,
            ]);
        }
    }
}
