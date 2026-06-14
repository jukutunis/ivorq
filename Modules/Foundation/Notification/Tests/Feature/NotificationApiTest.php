<?php

namespace Modules\Foundation\Notification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Notification\Models\AppNotification;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_fetch_notifications(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser();

        AppNotification::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'type' => 'TestType',
            'title' => 'Test Notification',
            'body' => 'Body',
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_fetch_unread_count(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser();

        AppNotification::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'type' => 'TestType',
            'title' => 'Unread 1',
            'body' => 'Body',
            'is_read' => false,
        ]);

        AppNotification::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'type' => 'TestType',
            'title' => 'Read 1',
            'body' => 'Body',
            'is_read' => true,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertStatus(200);
        $response->assertJson(['count' => 1]);
    }

    public function test_can_mark_all_read(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser();

        AppNotification::create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'type' => 'TestType',
            'title' => 'Unread 1',
            'body' => 'Body',
            'is_read' => false,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/notifications/mark-all-read');

        $response->assertStatus(200);
        
        $this->assertEquals(0, AppNotification::where('is_read', false)->count());
    }
}
