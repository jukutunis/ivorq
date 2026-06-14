<?php

namespace Modules\Foundation\Department\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Department\Models\Shift;
use Modules\Foundation\Property\Models\Property;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::withoutGlobalScope('property')->where('code', 'IGH')->first();

        if (! $property) {
            return;
        }

        $shifts = [
            ['name' => 'Morning', 'code' => 'M', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'is_cross_day' => false],
            ['name' => 'Afternoon', 'code' => 'A', 'start_time' => '15:00:00', 'end_time' => '23:00:00', 'is_cross_day' => false],
            ['name' => 'Night', 'code' => 'N', 'start_time' => '23:00:00', 'end_time' => '07:00:00', 'is_cross_day' => true],
        ];

        foreach ($shifts as $data) {
            Shift::create([
                'property_id'   => $property->id,
                'name'          => $data['name'],
                'code'          => $data['code'],
                'start_time'    => $data['start_time'],
                'end_time'      => $data['end_time'],
                'is_cross_day'  => $data['is_cross_day'],
                'is_active'     => true,
            ]);
        }
    }
}
