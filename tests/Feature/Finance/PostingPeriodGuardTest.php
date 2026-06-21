<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Services\PostingPeriodGuard;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodMissingException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodNotOpenException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodAmbiguousException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodInvalidStateException;
use Shared\Exceptions\PropertyNotResolvedException;
use Shared\Exceptions\BusinessLogicException;
use Carbon\Carbon;
use ReflectionMethod;

class PostingPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    private CurrentPropertyService $currentPropertyService;
    private PostingPeriodGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentPropertyService = $this->app->make(CurrentPropertyService::class);
        $this->guard = $this->app->make(PostingPeriodGuard::class);
    }

    private function createProperty(): Property
    {
        $company = \Modules\Foundation\Property\Models\Company::first();
        if (!$company) {
            $company = new \Modules\Foundation\Property\Models\Company([
                'name' => 'Test Company',
                'slug' => 'test-company-' . uniqid(),
            ]);
            $company->save();
        }

        $property = new Property([
            'name' => 'Test Property ' . uniqid(),
            'slug' => 'test-property-' . uniqid(),
            'code' => 'TST' . rand(10, 99),
            'company_id' => $company->id,
        ]);
        $property->save();
        return $property;
    }

    private function setupBusinessDate(Property $property, string $status = 'Open', ?Carbon $date = null): PropertyBusinessDate
    {
        $date = $date ?? Carbon::parse('2026-06-21');
        return PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => $date->format('Y-m-d'),
            'status' => $status,
            'is_open' => $status === 'Open' ? true : null,
            'opened_at' => now(),
            'opened_by' => 'test-user',
        ]);
    }

    private function createFinancialPeriod(Property $property, Carbon $date, string $status = 'Open'): FinancialPeriod
    {
        $fp = new FinancialPeriod([
            'property_id' => $property->id,
            'period_year' => $date->year,
            'period_month' => $date->month,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => 'test-user',
        ]);
        $fp->save();
        return $fp;
    }

    private function executeGuardWithProofs(callable $action, Property $property, array $forbiddenPropertyIds = [], bool $expectDbQuery = true)
    {
        // Snapshot state
        $fpsBefore = FinancialPeriod::withTrashed()->orderBy('id')->get()->map(fn($m) => $m->getAttributes())->toArray();
        $bdsBefore = PropertyBusinessDate::orderBy('id')->get()->map(fn($m) => $m->getAttributes())->toArray();

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $log = [];
        try {
            $action();
        } finally {
            $log = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();
            
            $fpQueried = false;
            
            foreach ($log as $query) {
                $sql = strtolower($query['query']);
                
                // Strong no-mutation assertion
                $this->assertStringNotContainsString('insert into', $sql, "Guard executed INSERT: $sql");
                $this->assertStringNotContainsString('update ', $sql, "Guard executed UPDATE: $sql");
                $this->assertStringNotContainsString('delete from', $sql, "Guard executed DELETE: $sql");
                $this->assertStringNotContainsString('merge ', $sql, "Guard executed MERGE: $sql");

                // Isolation Proof
                if (str_contains($sql, 'gl_financial_periods')) {
                    $fpQueried = true;
                    
                    $this->assertStringContainsString('deleted_at', $sql, "Query must use normal soft-delete scoping");
                    $this->assertStringNotContainsString('withoutglobalscopes', str_replace(' ', '', $sql));
                    
                    $hasPropertyBinding = false;
                    foreach ($query['bindings'] as $binding) {
                        if ((string)$binding === (string)$property->id) {
                            $hasPropertyBinding = true;
                        }
                        foreach ($forbiddenPropertyIds as $forbiddenId) {
                            $this->assertNotEquals((string)$forbiddenId, (string)$binding, "FinancialPeriod query binding contained FORBIDDEN Property ID {$forbiddenId}");
                        }
                    }
                    $this->assertTrue($hasPropertyBinding, "FinancialPeriod query bindings must contain the current Property ID.");
                }
            }
            
            if ($expectDbQuery) {
                $this->assertTrue($fpQueried, "Expected at least one gl_financial_periods query.");
            }

            $fpsAfter = FinancialPeriod::withTrashed()->orderBy('id')->get()->map(fn($m) => $m->getAttributes())->toArray();
            $bdsAfter = PropertyBusinessDate::orderBy('id')->get()->map(fn($m) => $m->getAttributes())->toArray();
            
            $this->assertEquals($fpsBefore, $fpsAfter, "FinancialPeriod state mutated");
            $this->assertEquals($bdsBefore, $bdsAfter, "PropertyBusinessDate state mutated");
        }
        
        return $log;
    }

    public function test_primary_method_signature_contract()
    {
        $reflection = new ReflectionMethod(PostingPeriodGuard::class, 'assertPostingAllowed');
        $this->assertEquals(0, $reflection->getNumberOfParameters(), 'Guard must not accept parameters.');
    }

    public function test_static_non_use_proof()
    {
        $content = file_get_contents(app_path('../Modules/Finance/GeneralLedger/Services/PostingPeriodGuard.php'));
        
        // This proof applies ONLY to the primary posting guard source file (PostingPeriodGuard.php).
        // It ensures the production guard does not use forbidden APIs or remove scopes, 
        // which is strictly required for isolation. It does not apply to the rest of the repository 
        // nor to test-only snapshot logic which safely uses withTrashed.
        
        $this->assertStringNotContainsString('PeriodControlService', $content, 'PostingPeriodGuard must not use PeriodControlService');
        $this->assertStringNotContainsString('withTrashed', $content, 'PostingPeriodGuard must not use withTrashed');
        $this->assertStringNotContainsString('onlyTrashed', $content, 'PostingPeriodGuard must not use onlyTrashed');
        $this->assertStringNotContainsString('withoutGlobalScopes', $content, 'PostingPeriodGuard must not use withoutGlobalScopes');
        $this->assertStringNotContainsString('withoutGlobalScope', $content, 'PostingPeriodGuard must not use withoutGlobalScope');
    }

    public function test_no_current_property_rejects()
    {
        $this->currentPropertyService->clear();

        $property = $this->createProperty();

        $this->executeGuardWithProofs(function () {
            $this->expectException(PropertyNotResolvedException::class);
            $this->guard->assertPostingAllowed();
        }, $property, [], false);
    }

    public function test_current_property_with_no_active_business_date_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);

        $this->executeGuardWithProofs(function () {
            $this->expectException(\Shared\Exceptions\NotFoundException::class);
            $this->guard->assertPostingAllowed();
        }, $property, [], false);
    }

    public function test_closed_business_date_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $this->setupBusinessDate($property, 'Closed');

        $this->executeGuardWithProofs(function () {
            $this->expectException(BusinessLogicException::class);
            $this->expectExceptionMessage("Business Date history exists but no Open Business Date exists.");
            $this->guard->assertPostingAllowed();
        }, $property, [], false);
    }

    public function test_open_business_date_missing_financial_period_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $this->setupBusinessDate($property, 'Open');

        $this->executeGuardWithProofs(function () {
            try {
                $this->guard->assertPostingAllowed();
                $this->fail('Expected exception was not thrown.');
            } catch (FinancialPeriodMissingException $e) {
                $this->assertTrue(true);
            }
        }, $property);
    }

    public function test_open_business_date_open_financial_period_passes()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $fp = $this->createFinancialPeriod($property, $bd->business_date, 'Open');

        $this->executeGuardWithProofs(function () use ($fp, $bd) {
            $result = $this->guard->assertPostingAllowed();
            $this->assertEquals($fp->id, $result->financialPeriodId);
            $this->assertEquals($bd->business_date->year, $result->periodYear);
            $this->assertEquals($bd->business_date->month, $result->periodMonth);
        }, $property);
    }

    public function test_open_business_date_closing_financial_period_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $this->createFinancialPeriod($property, $bd->business_date, 'Closing');

        $this->executeGuardWithProofs(function () {
            $this->expectException(FinancialPeriodNotOpenException::class);
            $this->guard->assertPostingAllowed();
        }, $property);
    }

    public function test_open_business_date_closed_financial_period_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $this->createFinancialPeriod($property, $bd->business_date, 'Closed');

        $this->executeGuardWithProofs(function () {
            $this->expectException(FinancialPeriodNotOpenException::class);
            $this->guard->assertPostingAllowed();
        }, $property);
    }

    public function test_open_business_date_reopened_financial_period_rejects()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $this->createFinancialPeriod($property, $bd->business_date, 'Reopened');

        $this->executeGuardWithProofs(function () {
            $this->expectException(FinancialPeriodNotOpenException::class);
            $this->guard->assertPostingAllowed();
        }, $property);
    }

    public function test_isolation_property_b_missing_period_when_property_a_has_it()
    {
        $propertyA = $this->createProperty();
        $propertyB = $this->createProperty();
        
        $bdA = $this->setupBusinessDate($propertyA, 'Open');
        $fpA = $this->createFinancialPeriod($propertyA, $bdA->business_date, 'Open');

        $this->currentPropertyService->setPropertyId($propertyB->id);
        $bdB = $this->setupBusinessDate($propertyB, 'Open', $bdA->business_date);

        $this->executeGuardWithProofs(function () {
            try {
                $this->guard->assertPostingAllowed();
                $this->fail('Expected exception was not thrown.');
            } catch (FinancialPeriodMissingException $e) {
                $this->assertTrue(true);
            }
        }, $propertyB, [$propertyA->id]);
    }

    public function test_soft_deleted_financial_period_does_not_qualify()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $fp = $this->createFinancialPeriod($property, $bd->business_date, 'Open');
        $fp->delete(); // Soft delete

        $this->executeGuardWithProofs(function () {
            $this->expectException(FinancialPeriodMissingException::class);
            $this->guard->assertPostingAllowed();
        }, $property);
    }

    public function test_corrupted_status_rejects_as_invalid_state()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        $fp = $this->createFinancialPeriod($property, $bd->business_date, 'Open');

        // Force a corrupted state
        DB::table('gl_financial_periods')
            ->where('id', $fp->id)
            ->update(['status' => 'CorruptStatus123']);

        $this->executeGuardWithProofs(function () use ($property) {
            try {
                $this->guard->assertPostingAllowed();
                $this->fail('Expected exception was not thrown.');
            } catch (FinancialPeriodInvalidStateException $e) {
                $this->assertStringNotContainsString('CorruptStatus123', $e->getMessage());
                $this->assertStringNotContainsString($property->id, $e->getMessage());
            }
        }, $property);
    }
    
    public function test_ambiguous_period_rejects_defensively()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $bd = $this->setupBusinessDate($property, 'Open');
        
        $guard = new class($this->currentPropertyService, $this->app->make(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class)) extends PostingPeriodGuard {
            protected function resolveCandidates(string $propertyId, int $year, int $month) {
                $fp1 = new \Modules\Finance\GeneralLedger\Models\FinancialPeriod();
                $fp2 = new \Modules\Finance\GeneralLedger\Models\FinancialPeriod();
                return collect([$fp1, $fp2]);
            }
        };

        $this->executeGuardWithProofs(function () use ($guard) {
            $this->expectException(FinancialPeriodAmbiguousException::class);
            $guard->assertPostingAllowed();
        }, $property, [], false);
    }
}
