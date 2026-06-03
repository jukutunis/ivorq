<?php

namespace Modules\Foundation\Department\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Property\Models\Property;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::withoutGlobalScope('property')->where('code', 'IGH')->first();

        if (! $property) {
            return;
        }

        $departments = [
            ['code' => 'FO',  'name' => 'Front Office'],
            ['code' => 'HK',  'name' => 'Housekeeping'],
            ['code' => 'ENG', 'name' => 'Engineering'],
            ['code' => 'FB',  'name' => 'Food & Beverage'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'HR',  'name' => 'Human Resources'],
            ['code' => 'SEC', 'name' => 'Security'],
            ['code' => 'IT',  'name' => 'Information Technology'],
        ];

        foreach ($departments as $data) {
            $dept = Department::create([
                'property_id' => $property->id,
                'name'        => $data['name'],
                'code'        => $data['code'],
                'is_active'   => true,
            ]);

            Position::create([
                'property_id'   => $property->id,
                'department_id' => $dept->id,
                'name'          => $data['name'] . ' Manager',
                'code'          => $data['code'] . '-MGR',
                'level'         => 4,
                'is_active'     => true,
            ]);

            Position::create([
                'property_id'   => $property->id,
                'department_id' => $dept->id,
                'name'          => $data['name'] . ' Staff',
                'code'          => $data['code'] . '-STAFF',
                'level'         => 2,
                'is_active'     => true,
            ]);
        }
    }
}
