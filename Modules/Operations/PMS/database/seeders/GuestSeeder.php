<?php

namespace Modules\Operations\PMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\PMS\Models\Guest;

class GuestSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $guests = [
            [
                'guest_code'  => 'GST-00001',
                'full_name'   => 'Ahmad Fauzi',
                'email'       => 'ahmad.fauzi@example.com',
                'phone'       => '+6281234567890',
                'nationality' => 'Indonesian',
                'id_type'     => 'passport',
                'id_number'   => 'B1234567',
                'guest_type'  => 'individual',
                'vip_level'   => null,
                'notes'       => 'Regular business traveller.',
            ],
            [
                'guest_code'  => 'GST-00002',
                'full_name'   => 'Sarah Lim Wei Ling',
                'email'       => 'sarah.lim@example.com',
                'phone'       => '+60123456789',
                'nationality' => 'Malaysian',
                'id_type'     => 'nric',
                'id_number'   => '900101-01-1234',
                'guest_type'  => 'vip',
                'vip_level'   => 1,
                'notes'       => 'VIP — prefers high-floor room with city view.',
            ],
            [
                'guest_code'  => 'GST-00003',
                'full_name'   => 'James Harrington',
                'email'       => 'j.harrington@globalcorp.com',
                'phone'       => '+447911123456',
                'nationality' => 'British',
                'id_type'     => 'passport',
                'id_number'   => 'GB9876543',
                'guest_type'  => 'corporate',
                'vip_level'   => null,
                'notes'       => 'Corporate client — GlobalCorp account.',
            ],
            [
                'guest_code'  => 'GST-00004',
                'full_name'   => 'Siti Rahayu Binti Hassan',
                'email'       => 'siti.rahayu@example.com',
                'phone'       => '+60187654321',
                'nationality' => 'Malaysian',
                'id_type'     => 'nric',
                'id_number'   => '850515-02-5678',
                'guest_type'  => 'individual',
                'vip_level'   => null,
                'notes'       => null,
            ],
            [
                'guest_code'  => 'GST-00005',
                'full_name'   => 'Tanaka Hiroshi',
                'email'       => 'tanaka.h@example.jp',
                'phone'       => '+81901234567',
                'nationality' => 'Japanese',
                'id_type'     => 'passport',
                'id_number'   => 'TK2345678',
                'guest_type'  => 'vip',
                'vip_level'   => 2,
                'notes'       => 'Returning VIP — prefers room away from elevator.',
            ],
            [
                'guest_code'  => 'GST-00006',
                'full_name'   => 'Priya Krishnamurthy',
                'email'       => 'priya.k@techventures.in',
                'phone'       => '+919876543210',
                'nationality' => 'Indian',
                'id_type'     => 'passport',
                'id_number'   => 'IN3456789',
                'guest_type'  => 'corporate',
                'vip_level'   => null,
                'notes'       => 'Conference attendee — group booking expected.',
            ],
        ];

        foreach ($guests as $data) {
            Guest::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'guest_code'  => $data['guest_code'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}
