<?php

namespace Tests\Postgres\Operations\Inventory;

use Exception;
use DomainException;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Services\BusinessDateCloseExecutionService;
use Modules\Operations\Inventory\Services\BusinessDateCloseService;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Shared\Services\CurrentPropertyService;
use Illuminate\Support\Str;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;

class BusinessDateCloseExecutionServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private BusinessDateCloseExecutionService $executionService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $closeService = app(BusinessDateCloseService::class);
        $coordinator = app(InventoryPostingControlCoordinator::class);
        $this->executionService = new BusinessDateCloseExecutionService($closeService, $coordinator);
    }

    public function test_tenant_visible_open_business_date_persists_closed(): void
    {
        $property = PropertyFactory::new()->create();
        $user = UserFactory::new()->create();
        app(CurrentPropertyService::class)->setId($property->id);
        $this->actingAs($user);

        $businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'is_open' => true,
            'status' => PropertyBusinessDateStatusEnum::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $result = $this->executionService->executeClose($businessDate->id);

        $this->assertEquals($businessDate->id, $result->id);
        $this->assertNull($result->is_open);
        $this->assertSame(PropertyBusinessDateStatusEnum::Closed, $result->status);
        $this->assertNotNull($result->closed_at);
        $this->assertEquals($user->id, $result->closed_by);
        $this->assertEquals($property->id, $result->property_id);
        $this->assertEquals($businessDate->business_date->toDateString(), $result->business_date->toDateString());

        $fresh = PropertyBusinessDate::findOrFail($businessDate->id);
        $this->assertNull($fresh->is_open);
        $this->assertSame(PropertyBusinessDateStatusEnum::Closed, $fresh->status);
        $this->assertEquals($result->closed_at->toDateTimeString(), $fresh->closed_at->toDateTimeString());
        $this->assertEquals($user->id, $fresh->closed_by);

        $this->assertEquals(1, PropertyBusinessDate::count());
    }

    public function test_already_closed_business_date_does_not_persist_new_values(): void
    {
        $property = PropertyFactory::new()->create();
        $user = UserFactory::new()->create();
        app(CurrentPropertyService::class)->setId($property->id);
        $this->actingAs($user);

        $originalClosedAt = now()->subDay();
        $originalUserId = Str::ulid()->__toString();

        $businessDate = PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'closed_at' => $originalClosedAt,
            'closed_by' => $originalUserId,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Business Date is already closed.');

        try {
            $this->executionService->executeClose($businessDate->id);
        } finally {
            $fresh = PropertyBusinessDate::withoutGlobalScopes()->findOrFail($businessDate->id);
            $this->assertNull($fresh->is_open);
            $this->assertSame(PropertyBusinessDateStatusEnum::Closed, $fresh->status);
            $this->assertEquals($originalClosedAt->toDateTimeString(), $fresh->closed_at->toDateTimeString());
            $this->assertEquals($originalUserId, $fresh->closed_by);
            $this->assertEquals(1, PropertyBusinessDate::count());
        }
    }

    public function test_missing_authenticated_actor_fails_closed_without_persistence(): void
    {
        $property = PropertyFactory::new()->create();
        app(CurrentPropertyService::class)->setId($property->id);
        
        $businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'is_open' => true,
            'status' => PropertyBusinessDateStatusEnum::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Authenticated actor is required to close Business Date.');

        try {
            $this->executionService->executeClose($businessDate->id);
        } finally {
            $fresh = PropertyBusinessDate::findOrFail($businessDate->id);
            $this->assertTrue($fresh->is_open);
            $this->assertSame(PropertyBusinessDateStatusEnum::Open, $fresh->status);
            $this->assertNull($fresh->closed_at);
            $this->assertNull($fresh->closed_by);
            $this->assertEquals(1, PropertyBusinessDate::count());
        }
    }

    public function test_tenant_isolated_record_is_not_closeable_across_scope(): void
    {
        $propertyA = PropertyFactory::new()->create();
        $propertyB = PropertyFactory::new()->create();
        $user = UserFactory::new()->create();
        
        $businessDateA = PropertyBusinessDate::factory()->create([
            'property_id' => $propertyA->id,
            'is_open' => true,
            'status' => PropertyBusinessDateStatusEnum::Open,
        ]);

        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->actingAs($user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        try {
            $this->executionService->executeClose($businessDateA->id);
        } finally {
            $fresh = PropertyBusinessDate::withoutGlobalScopes()->findOrFail($businessDateA->id);
            $this->assertTrue($fresh->is_open);
            $this->assertSame(PropertyBusinessDateStatusEnum::Open, $fresh->status);
        }
    }

    public function test_save_time_failure_rolls_back_the_database_mutation(): void
    {
        $property = PropertyFactory::new()->create();
        $user = UserFactory::new()->create();
        app(CurrentPropertyService::class)->setId($property->id);
        $this->actingAs($user);

        $businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'is_open' => true,
            'status' => PropertyBusinessDateStatusEnum::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $originalDispatcher = PropertyBusinessDate::getEventDispatcher();
        PropertyBusinessDate::setEventDispatcher(clone $originalDispatcher);

        PropertyBusinessDate::saved(function () {
            throw new RuntimeException('Forced Save Failure');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced Save Failure');

        try {
            $this->executionService->executeClose($businessDate->id);
        } finally {
            PropertyBusinessDate::setEventDispatcher($originalDispatcher);
            $fresh = PropertyBusinessDate::findOrFail($businessDate->id);
            $this->assertTrue($fresh->is_open);
            $this->assertSame(PropertyBusinessDateStatusEnum::Open, $fresh->status);
            $this->assertNull($fresh->closed_at);
            $this->assertNull($fresh->closed_by);
            $this->assertEquals(1, PropertyBusinessDate::count());
        }
    }

    public function test_execution_boundary_static_contract(): void
    {
        $path = realpath(__DIR__ . '/../../../../Modules/Operations/Inventory/Services/BusinessDateCloseExecutionService.php');
        $content = file_get_contents($path);

        $required = [
            '->executeOnce(',
            'DB::transaction(',
            '->lockForUpdate()',
            '->firstOrFail()',
            '->close(',
            '->save()'
        ];

        foreach ($required as $req) {
            $this->assertStringContainsString($req, $content, "Required element missing: $req");
        }

        $forbidden = [
            'withoutGlobalScope',
            'newQueryWithoutScopes',
            'DB::table',
            'DB::statement',
            'DB::unprepared',
            'beginTransaction',
            'commit',
            'rollBack',
            'retry',
            'attempt',
            'loop',
            'sleep',
            'usleep',
            'dispatch',
            'event',
            'queue',
            'logger',
            'Log::',
            'InventoryTransaction',
            'InventoryStock',
            'CostLedger',
            'GeneralLedger',
            'Journal',
            'AccountsPayable',
            'Payable',
            'GRNI',
            'FinancialPeriod'
        ];

        foreach (explode("\n", $content) as $i => $line) {
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $line, "Forbidden keyword found on line " . ($i + 1) . ": $f");
            }
        }

        $saveCount = substr_count($content, '->save()');
        $this->assertEquals(1, $saveCount, "save() must be used exactly once");

        $this->assertMatchesRegularExpression('/DB::transaction\s*\(\s*function\s*\([^\)]*\)\s*(?:use\s*\([^\)]*\))?\s*\{.*\},\s*1\s*\)/s', $content, "Explicit attempts 1 is missing for DB::transaction");
    }

    public function test_operational_isolation(): void
    {
        $basePath = realpath(__DIR__ . '/../../../../');
        $scanDirs = [
            $basePath . DIRECTORY_SEPARATOR . 'Modules',
            $basePath . DIRECTORY_SEPARATOR . 'app'
        ];

        $targetToFind = 'BusinessDateCloseExecution' . 'Service';
        $declarationFile = realpath($basePath . DIRECTORY_SEPARATOR . 'Modules/Operations/Inventory/Services/BusinessDateCloseExecutionService.php');

        $foundCallers = [];

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    if ($file->getRealPath() === $declarationFile) {
                        continue;
                    }
                    $fileContent = file_get_contents($file->getRealPath());
                    if (strpos($fileContent, $targetToFind) !== false) {
                        $foundCallers[] = $file->getRealPath();
                    }
                }
            }
        }

        $this->assertEmpty($foundCallers, "Operational caller found in: " . implode(", ", $foundCallers));
    }
}
