<?php

namespace Tests\Unit\Operations\Inventory;

use DomainException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Carbon;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Services\BusinessDateCloseService;
use PHPUnit\Framework\TestCase;

class BusinessDateCloseServiceTest extends TestCase
{
    private function createGuard(?string $userId = null): Guard
    {
        $guard = $this->createMock(Guard::class);
        $guard->method('id')->willReturn($userId);
        return $guard;
    }

    private function makeBusinessDate(): PropertyBusinessDate
    {
        $reflection = new \ReflectionClass(PropertyBusinessDate::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock connection resolver so Eloquent doesn't try to boot real DB connections for datetime casts
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $grammar = $this->createMock(\Illuminate\Database\Query\Grammars\Grammar::class);
        $grammar->method('getDateFormat')->willReturn('Y-m-d H:i:s');
        $connection->method('getQueryGrammar')->willReturn($grammar);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authenticated_open_date_closes_successfully(): void
    {
        $now = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($now);

        $guard = $this->createGuard('actor-123');
        $service = new BusinessDateCloseService($guard);

        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = true;

        $result = $service->close($businessDate);

        $this->assertSame($businessDate, $result);
        $this->assertFalse($result->is_open);
        $this->assertEquals($now, $result->closed_at);
        $this->assertEquals('actor-123', $result->closed_by);
    }

    public function test_already_closed_date_is_rejected(): void
    {
        $guard = $this->createGuard('actor-123');
        $service = new BusinessDateCloseService($guard);

        $existingDate = Carbon::create(2026, 6, 22, 10, 0, 0);
        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = false;
        $businessDate->closed_at = clone $existingDate;
        $businessDate->closed_by = 'actor-456';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Business Date is already closed.');

        try {
            $service->close($businessDate);
        } finally {
            $this->assertFalse($businessDate->is_open);
            $this->assertEquals($existingDate, $businessDate->closed_at);
            $this->assertEquals('actor-456', $businessDate->closed_by);
        }
    }

    public function test_missing_actor_fails_closed(): void
    {
        $guard = $this->createGuard(null);
        $service = new BusinessDateCloseService($guard);

        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = true;
        $businessDate->closed_at = null;
        $businessDate->closed_by = null;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Authenticated actor is required');

        try {
            $service->close($businessDate);
        } finally {
            $this->assertTrue($businessDate->is_open);
            $this->assertNull($businessDate->closed_at);
            $this->assertNull($businessDate->closed_by);
        }
    }

    public function test_actor_with_blank_identifier_fails_closed(): void
    {
        $guard = $this->createGuard('   ');
        $service = new BusinessDateCloseService($guard);

        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = true;
        $businessDate->closed_at = null;
        $businessDate->closed_by = null;

        $this->expectException(DomainException::class);

        try {
            $service->close($businessDate);
        } finally {
            $this->assertTrue($businessDate->is_open);
            $this->assertNull($businessDate->closed_at);
            $this->assertNull($businessDate->closed_by);
        }
    }

    public function test_actor_with_null_identifier_fails_closed(): void
    {
        $guard = $this->createGuard(null);
        $service = new BusinessDateCloseService($guard);

        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = true;
        $businessDate->closed_at = null;
        $businessDate->closed_by = null;

        $this->expectException(DomainException::class);

        try {
            $service->close($businessDate);
        } finally {
            $this->assertTrue($businessDate->is_open);
            $this->assertNull($businessDate->closed_at);
            $this->assertNull($businessDate->closed_by);
        }
    }

    public function test_server_side_time_is_used(): void
    {
        $now = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($now);

        $guard = $this->createGuard('actor-123');
        $service = new BusinessDateCloseService($guard);

        $businessDate = $this->makeBusinessDate();
        $businessDate->is_open = true;

        $service->close($businessDate);

        $this->assertEquals($now, $businessDate->closed_at);

        $method = new \ReflectionMethod(BusinessDateCloseService::class, 'close');
        $this->assertEquals(1, $method->getNumberOfParameters(), 'close() should not accept a timestamp parameter');
    }

    public function test_pure_transition_boundary(): void
    {
        // Must use an absolute path that exists relative to this test file to prevent path issues during pure unit execution.
        $path = realpath(__DIR__ . '/../../../../Modules/Operations/Inventory/Services/BusinessDateCloseService.php');
        $content = file_get_contents($path);

        $forbidden = [
            '->' . 'save(', '->' . 'update(', '->' . 'create(', '->' . 'delete(',
            'D' . 'B::', 'lockFor' . 'Update', 'trans' . 'action(', 're' . 'try(',
            'att' . 'empt(', 'disp' . 'atch(', 'Ev' . 'ent::', 'Qu' . 'eue::',
            'InventoryPostingControl' . 'Coordinator', 'Cost' . 'Ledger',
            'General' . 'Ledger', 'Jour' . 'nal', 'Accounts' . 'Payable',
            'Pay' . 'able', 'GR' . 'NI'
        ];

        foreach (explode("\n", $content) as $i => $line) {
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $line, "Forbidden keyword found on line " . ($i + 1) . ": $f");
            }
        }
    }
}
