<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Services\BusinessDateCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

use Tests\PostgresTestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;

class BusinessDateCloseServiceTest extends PostgresTestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private function createFixture(): array
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $user = $this->createUser($property);

        return [$property, $user];
    }

    public function test_it_revalidates_and_closes_business_date()
    {
        [$property, $user] = $this->createFixture();

        $businessDate = \Database\Factories\PropertyBusinessDateFactory::new()->create([
            'property_id' => $property->id,
            'business_date' => now()->toDateString(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
        ]);

        $this->actingAs($user);
        $session = app('session')->driver();
        $session->put('active_property_id', $property->id);
        request()->setLaravelSession($session);

        $service = new BusinessDateCloseService();

        $closed = $service->closeCurrentBusinessDate();

        $this->assertEquals(PropertyBusinessDateStatusEnum::Closed, $closed->status);
        $this->assertNull($closed->is_open);
        $this->assertNotNull($closed->closed_at);
        $this->assertEquals($user->id, $closed->closed_by);
    }

    public function test_it_rejects_missing_actor()
    {
        $service = new BusinessDateCloseService();

        $this->expectException(RuntimeException::class);
        $service->closeCurrentBusinessDate();
    }

    public function test_it_rejects_missing_property()
    {
        [$property, $user] = $this->createFixture();
        $this->actingAs($user);

        $service = new BusinessDateCloseService();

        $this->expectException(RuntimeException::class);
        $service->closeCurrentBusinessDate();
    }
}
