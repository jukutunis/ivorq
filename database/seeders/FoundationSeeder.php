<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        // Platform Administrator - Global
        $platformAdmin = User::firstOrCreate([
            'email' => 'admin@ivorq.com',
        ], [
            'name'            => 'Platform Administrator',
            'password'        => 'password', // Auto-hashed by model
            'is_system_admin' => true,
            'is_active'       => true,
            'email_verified_at' => now(),
        ]);
        setPermissionsTeamId(null);
        $platformAdmin->assignRole('super-admin');

        // IVORQ Hospitality Group
        $company = Company::firstOrCreate([
            'slug' => 'ivorq-hospitality-group',
        ], [
            'name'      => 'IVORQ Hospitality Group',
            'is_active' => true,
        ]);

        // Uluwatu Surf Villas
        $uluwatu = Property::firstOrCreate([
            'slug' => 'uluwatu-surf-villas',
        ], [
            'company_id' => $company->id,
            'name'       => 'Uluwatu Surf Villas',
            'code'       => 'USV',
            'timezone'   => 'Asia/Makassar',
            'currency'   => 'IDR',
            'is_active'  => true,
        ]);

        // IVORQ Demo Resort
        $demoResort = Property::firstOrCreate([
            'slug' => 'ivorq-demo-resort',
        ], [
            'company_id' => $company->id,
            'name'       => 'IVORQ Demo Resort',
            'code'       => 'IDR',
            'timezone'   => 'UTC',
            'currency'   => 'USD',
            'is_active'  => true,
        ]);

        // Create Users for Uluwatu Surf Villas
        $this->createUserForProperty($uluwatu, 'property-admin', 'Property Administrator', 'usv.admin@ivorq.com');
        $this->createUserForProperty($uluwatu, 'general-manager', 'General Manager', 'usv.gm@ivorq.com');
        $this->createUserForProperty($uluwatu, 'department-head', 'Department Head', 'usv.dh@ivorq.com');
        $this->createUserForProperty($uluwatu, 'supervisor', 'Supervisor', 'usv.supervisor@ivorq.com');
        $this->createUserForProperty($uluwatu, 'staff', 'Staff Member', 'usv.staff@ivorq.com');

        // Create Users for IVORQ Demo Resort
        $this->createUserForProperty($demoResort, 'property-admin', 'Property Administrator', 'demo.admin@ivorq.com');
        $this->createUserForProperty($demoResort, 'general-manager', 'General Manager', 'demo.gm@ivorq.com');
        $this->createUserForProperty($demoResort, 'department-head', 'Department Head', 'demo.dh@ivorq.com');
        $this->createUserForProperty($demoResort, 'supervisor', 'Supervisor', 'demo.supervisor@ivorq.com');
        $this->createUserForProperty($demoResort, 'staff', 'Staff Member', 'demo.staff@ivorq.com');
    }

    private function createUserForProperty(Property $property, string $role, string $name, string $email): User
    {
        $user = User::firstOrCreate([
            'email' => $email,
        ], [
            'name'            => $name,
            'password'        => 'password',
            'is_active'       => true,
            'email_verified_at' => now(),
        ]);

        if (!$user->properties()->where('properties.id', $property->id)->exists()) {
            $user->properties()->attach($property->id, [
                'is_default' => true,
                'status'     => 'active',
                'joined_at'  => now(),
            ]);
        }

        setPermissionsTeamId($property->id);
        $user->assignRole($role);
        
        return $user;
    }
}
