<?php

namespace Modules\Foundation\Department\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\JobTitle;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Property\Models\Property;

class JobTitleSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::withoutGlobalScope('property')->where('code', 'IGH')->first();

        if (! $property) {
            return;
        }

        $mgrPos = Position::where('code', 'MGR')->first();
        $staffPos = Position::where('code', 'STAFF')->first();

        $departments = Department::where('property_id', $property->id)->get();

        foreach ($departments as $dept) {
            JobTitle::create([
                'property_id'   => $property->id,
                'department_id' => $dept->id,
                'position_id'   => $mgrPos?->id,
                'title'         => $dept->name . ' Manager',
                'is_active'     => true,
            ]);

            JobTitle::create([
                'property_id'   => $property->id,
                'department_id' => $dept->id,
                'position_id'   => $staffPos?->id,
                'title'         => $dept->name . ' Staff',
                'is_active'     => true,
            ]);
        }
    }
}
