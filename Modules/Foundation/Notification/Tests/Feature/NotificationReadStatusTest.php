<?php

namespace Modules\Foundation\Notification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Notification\Models\AppNotification;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class NotificationReadStatusTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_mark_notification_as_read(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser();

        $notif = AppNotification::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'type' => 'TestType',
            'title' => 'Test Notification',
            'body' => 'Body',
            'is_read' => false,
        ]);

        $this->assertFalse($notif->is_read);
        $this->assertNull($notif->read_at);

        $notif->markAsRead();

        $this->assertTrue($notif->is_read);
        $this->assertNotNull($notif->read_at);
    }
}
