<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

abstract class ReceivingTestCase extends TestCase
{
    use RefreshDatabase;

    protected $property;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        $this->user = User::first();
        $this->actingAs($this->user);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
    }
}
