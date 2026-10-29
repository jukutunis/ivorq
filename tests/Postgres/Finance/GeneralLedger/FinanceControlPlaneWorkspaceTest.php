<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\FxReference\Models\ExchangeRateEvidence;
use Modules\Finance\FxReference\Services\ExchangeRateEvidenceService;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Services\PeriodControlService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FinanceControlPlaneWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;

    private Property $property;

    private Property $otherProperty;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = CompanyFactory::new()->create(['is_active' => true]);
        $this->property = PropertyFactory::new()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->otherProperty = PropertyFactory::new()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->actor = UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);

        foreach ([
            'finance.journal-candidate.review',
            'generalledger.trialbalance.view',
            'generalledger.period.manage',
            ExchangeRateEvidenceService::RECORD_PERMISSION,
            ExchangeRateEvidenceService::APPROVE_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($this->property->id);
        $this->actor->givePermissionTo([
            'finance.journal-candidate.review',
            'generalledger.trialbalance.view',
            'generalledger.period.manage',
            ExchangeRateEvidenceService::RECORD_PERMISSION,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_general_ledger_workspace_is_property_scoped_and_read_only(): void
    {
        $visible = $this->candidate($this->property, 'VISIBLE-SOURCE');
        $hidden = $this->candidate($this->otherProperty, 'HIDDEN-SOURCE');
        $before = JournalCandidate::withoutGlobalScopes()->count();

        $response = $this->acting()->get(route('finance.general-ledger.operations'))->assertOk();
        $props = $response->inertiaProps();
        $ids = collect($props['candidates'])->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertSame($before, JournalCandidate::withoutGlobalScopes()->count());
        $this->assertSame('SPECIALIZED_WORKSPACES_ONLY', $props['boundaries']['mutation_mode']);
    }

    public function test_financial_reports_are_server_generated_for_the_active_property(): void
    {
        $response = $this->acting()->get(route('finance.general-ledger.reports', ['year' => 2026, 'month' => 8]))->assertOk();
        $props = $response->inertiaProps();

        $this->assertSame(2026, $props['period']['year']);
        $this->assertSame(8, $props['period']['month']);
        $this->assertTrue($props['permissions']['trial_balance']);
        $this->assertIsArray($props['reports']['trial_balance']);
        $this->assertArrayHasKey('balanced', $props['reports']['trial_balance']);
        $this->assertSame('SERVER_GENERATED_FROM_PROPERTY_SCOPED_LEDGER', $props['authority']);
    }

    public function test_period_workspace_and_legacy_compatibility_check_fail_closed_without_auto_creation(): void
    {
        $service = app(PeriodControlService::class);
        $this->assertFalse($service->isOpen($this->property->id, 2026, 8));
        $this->assertSame(0, FinancialPeriod::where('property_id', $this->property->id)->count());

        $response = $this->acting()->get(route('finance.general-ledger.period-control'))->assertOk();
        $props = $response->inertiaProps();
        $this->assertSame('DISABLED', $props['authority']['auto_creation']);
        $this->assertFalse($props['posting_readiness']['allowed']);
        $this->assertSame(0, FinancialPeriod::where('property_id', $this->property->id)->count());

        $this->acting()->post(route('finance.general-ledger.period-control.initialize'))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
        $this->assertSame(0, FinancialPeriod::where('property_id', $this->property->id)->count());
    }

    public function test_exchange_rate_workspace_wires_record_and_independent_approval_with_property_scope(): void
    {
        $this->acting()->post(route('finance.fx-rates.record'), [
            'base_currency' => 'USD',
            'quote_currency' => 'IDR',
            'rate' => '16325.125',
            'quote_convention' => 'BASE_TO_QUOTE',
            'effective_date' => '2026-08-01',
            'source_reference' => 'BANK-RATE-2026-08-01',
        ])->assertRedirect(route('finance.fx-rates.index'));

        $evidence = ExchangeRateEvidence::sole();
        $this->assertSame($this->property->id, $evidence->property_id);
        $this->assertSame('16325.12500000', (string) $evidence->rate);

        $approver = UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);
        setPermissionsTeamId($this->property->id);
        $approver->givePermissionTo(ExchangeRateEvidenceService::APPROVE_PERMISSION);
        $this->acting($approver)->post(route('finance.fx-rates.approve', $evidence))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
        $this->assertSame('RECORDED', $evidence->fresh()->getRawOriginal('status'));

        $now = now();
        $this->acting($approver)
            ->withSession([
                'sensitive_action_confirmation' => [
                    'finance-approval' => [
                        'actor_id' => $approver->id,
                        'intent' => 'finance-approval',
                        'company_id' => $this->company->id,
                        'property_id' => $this->property->id,
                        'confirmed_at' => $now->toISOString(),
                        'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                    ],
                ],
            ])
            ->post(route('finance.fx-rates.approve', $evidence))
            ->assertRedirect(route('finance.fx-rates.index'));
        $this->assertSame('APPROVED', $evidence->fresh()->getRawOriginal('status'));

        $otherRecorder = UserFactory::new()->withProperty($this->otherProperty)->create(['is_active' => true]);
        setPermissionsTeamId($this->otherProperty->id);
        $otherRecorder->givePermissionTo(ExchangeRateEvidenceService::RECORD_PERMISSION);
        app(ExchangeRateEvidenceService::class)->record(
            $this->otherProperty->id,
            'EUR',
            'IDR',
            '17000.00',
            'BASE_TO_QUOTE',
            '2026-08-01',
            'OTHER-PROPERTY-RATE',
            $otherRecorder,
        );

        $props = $this->acting()->get(route('finance.fx-rates.index'))->assertOk()->inertiaProps();
        $this->assertSame([$evidence->id], collect($props['evidence'])->pluck('id')->all());
    }

    public function test_workspace_permissions_fail_closed(): void
    {
        $unauthorized = UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);

        $this->acting($unauthorized)->get(route('finance.general-ledger.operations'))->assertForbidden();
        $this->acting($unauthorized)->get(route('finance.general-ledger.reports'))->assertForbidden();
        $this->acting($unauthorized)->get(route('finance.general-ledger.period-control'))->assertForbidden();
        $this->acting($unauthorized)->get(route('finance.fx-rates.index'))->assertForbidden();
    }

    public function test_legacy_period_control_is_not_a_general_ledger_posting_authority(): void
    {
        $subledger = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/SubledgerPostingService.php'));
        $package = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/FinancialPackageService.php'));
        $ledger = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/GeneralLedgerService.php'));

        $this->assertStringNotContainsString('PeriodControlService', $subledger);
        $this->assertStringNotContainsString('PeriodControlService', $package);
        $this->assertStringContainsString('FinancialPeriod::where', $ledger);
        $this->assertStringContainsString('Auto-creation is not permitted', $ledger);
    }

    private function candidate(Property $property, string $source): JournalCandidate
    {
        return JournalCandidate::create([
            'property_id' => $property->id,
            'source_type' => 'ControlledTestSource',
            'source_id' => $source,
            'posting_event' => 'ControlledTestPosting',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
            'candidate_date' => '2026-08-01',
            'description' => $source,
        ]);
    }

    private function acting(?User $user = null): static
    {
        setPermissionsTeamId($this->property->id);

        return $this->withSession([
            'active_company_id' => $this->company->id,
            'active_property_id' => $this->property->id,
        ])->actingAs($user ?? $this->actor, 'web');
    }
}
