<?php

namespace Modules\Foundation\Department\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Department\Models\Position;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            ['name' => 'Manager', 'code' => 'MGR', 'level' => 400],
            ['name' => 'Supervisor', 'code' => 'SPV', 'level' => 300],
            ['name' => 'Coordinator', 'code' => 'COORD', 'level' => 200],
            ['name' => 'Officer', 'code' => 'OFF', 'level' => 150],
            ['name' => 'Staff', 'code' => 'STAFF', 'level' => 100],
        ];

        foreach ($positions as $data) {
            Position::firstOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'level' => $data['level'],
                    'is_active' => true,
                ]
            );
        }
    }
}
