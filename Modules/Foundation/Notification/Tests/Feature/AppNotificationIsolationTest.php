<?php

namespace Modules\Foundation\Notification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Notification\Models\AppNotification;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class AppNotificationIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_notifications_are_isolated_by_property(): void
    {
        $company = $this->createCompany();
        $prop1 = $this->createProperty($company);
        $prop2 = $this->createProperty($company, ['code' => 'P2']);

        $user1 = $this->createUser();
        $user2 = $this->createUser();

        $notif1 = AppNotification::create([
            'property_id' => $prop1->id,
            'user_id' => $user1->id,
            'type' => 'TestType',
            'title' => 'Prop 1 Notification',
            'body' => 'Body',
        ]);

        $notif2 = AppNotification::create([
            'property_id' => $prop2->id,
            'user_id' => $user2->id,
            'type' => 'TestType',
            'title' => 'Prop 2 Notification',
            'body' => 'Body',
        ]);

        $admin1 = $this->createPropertyAdmin($prop1);
        $this->actingAs($admin1);

        $visibleNotifications = AppNotification::all();

        $this->assertCount(1, $visibleNotifications);
        $this->assertEquals($notif1->id, $visibleNotifications->first()->id);
    }
}
