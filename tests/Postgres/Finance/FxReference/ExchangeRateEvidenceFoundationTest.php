<?php

namespace Tests\Postgres\Finance\FxReference;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Services\ExchangeRateEvidenceService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ExchangeRateEvidenceFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $recorder;
    private User $approver;
    private ExchangeRateEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->recorder = $this->makeUser();
        $this->approver = $this->makeUser();
        $this->attachActorToProperty($this->recorder, $this->property);
        $this->attachActorToProperty($this->approver, $this->property);

        foreach ([ExchangeRateEvidenceService::RECORD_PERMISSION, ExchangeRateEvidenceService::APPROVE_PERMISSION] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->recorder->givePermissionTo(ExchangeRateEvidenceService::RECORD_PERMISSION, ExchangeRateEvidenceService::APPROVE_PERMISSION);
        $this->approver->givePermissionTo(ExchangeRateEvidenceService::RECORD_PERMISSION, ExchangeRateEvidenceService::APPROVE_PERMISSION);

        $this->service = app(ExchangeRateEvidenceService::class);
    }

    public function test_authorized_actor_records_and_independent_actor_approves_rate_evidence(): void
    {
        $before = $this->controlledSnapshot();

        $evidence = $this->service->record(
            $this->property->id,
            'USD',
            'IDR',
            '16325.12345678',
            'BASE_TO_QUOTE',
            '2026-07-01',
            'FX-SOURCE-' . $this->sequence++,
            $this->recorder
        );

        $this->assertSame($this->property->id, $evidence->property_id);
        $this->assertSame('USD', $evidence->base_currency);
        $this->assertSame('IDR', $evidence->quote_currency);
        $this->assertSame('16325.12345678', (string) $evidence->rate);
        $this->assertSame(ExchangeRateEvidenceStatusEnum::RECORDED, $evidence->status);
        $this->assertSame($this->recorder->id, $evidence->recorded_by);

        $replay = $this->service->record(
            $this->property->id,
            'USD',
            'IDR',
            '16325.12345678',
            'BASE_TO_QUOTE',
            '2026-07-01',
            $evidence->source_reference,
            $this->recorder
        );
        $this->assertSame($evidence->id, $replay->id);

        try {
            $this->service->approve($evidence->id, $this->recorder);
            $this->fail('Exchange Rate recorder must not approve their own evidence.');
        } catch (AuthorizationException) {
            $this->assertSame(ExchangeRateEvidenceStatusEnum::RECORDED, $evidence->fresh()->status);
        }

        $approved = $this->service->approve($evidence->id, $this->approver);
        $this->assertSame(ExchangeRateEvidenceStatusEnum::APPROVED, $approved->status);
        $this->assertSame($this->approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        try {
            $approved->rate = '1.00000000';
            $approved->save();
            $this->fail('Approved Exchange Rate evidence must be immutable.');
        } catch (DomainException) {
            $this->assertSame('16325.12345678', (string) $approved->fresh()->rate);
        }

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'exchange_rate_evidences' => 1,
        ]);
    }

    public function test_rate_evidence_rejection_requires_reason_and_is_terminal(): void
    {
        $evidence = $this->service->record(
            $this->property->id,
            'EUR',
            'IDR',
            '17500.00000000',
            'BASE_TO_QUOTE',
            '2026-07-01',
            'FX-SOURCE-' . $this->sequence++,
            $this->recorder
        );

        try {
            $this->service->reject($evidence->id, '', $this->approver);
            $this->fail('Exchange Rate rejection must require a reason.');
        } catch (DomainException) {
            $this->assertSame(ExchangeRateEvidenceStatusEnum::RECORDED, $evidence->fresh()->status);
        }

        $rejected = $this->service->reject($evidence->id, 'Unsupported source document.', $this->approver);
        $this->assertSame(ExchangeRateEvidenceStatusEnum::REJECTED, $rejected->status);
        $this->assertSame($this->approver->id, $rejected->rejected_by);

        try {
            $rejected->rate = '1.00000000';
            $rejected->save();
            $this->fail('Rejected Exchange Rate evidence must be immutable.');
        } catch (DomainException) {
            $this->assertSame('17500.00000000', (string) $rejected->fresh()->rate);
        }

        try {
            $this->service->approve($rejected->id, $this->approver);
            $this->fail('Rejected Exchange Rate evidence must not be approved.');
        } catch (DomainException) {
            $this->assertSame(ExchangeRateEvidenceStatusEnum::REJECTED, $rejected->fresh()->status);
        }
    }

    public function test_invalid_scope_currency_rate_and_conflicting_replay_fail_closed(): void
    {
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $crossProperty = $this->makeProperty();

        foreach ([
            fn () => $this->service->record($this->property->id, 'USD', 'USD', '1.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '0', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '0.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '-1.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '1.000000001', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '1e-8', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', 1.0, 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($this->property->id, 'USD', 'IDR', '1.00000000', '', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
            fn () => $this->service->record($crossProperty->id, 'USD', 'IDR', '1.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-' . $this->sequence++, $this->recorder),
        ] as $invalidRecord) {
            try {
                $invalidRecord();
                $this->fail('Invalid Exchange Rate evidence input must fail closed.');
            } catch (DomainException|AuthorizationException) {
                $this->assertSame(0, DB::table('exchange_rate_evidences')->count());
            }
        }

        try {
            $this->service->record($this->property->id, 'USD', 'IDR', '1.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-X', $unauthorized);
            $this->fail('Unauthorized Exchange Rate evidence actor must fail closed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('exchange_rate_evidences')->count());
        }

        $this->service->record($this->property->id, 'USD', 'IDR', '1.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-X', $this->recorder);

        try {
            $this->service->record($this->property->id, 'USD', 'IDR', '2.00000000', 'BASE_TO_QUOTE', '2026-07-01', 'FX-SOURCE-X', $this->recorder);
            $this->fail('Conflicting Exchange Rate evidence replay must fail controlled.');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('exchange_rate_evidences')->count());
        }
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'exchange_rate_evidences',
            'payment_executions',
            'ap_settlement_allocations',
            'journal_candidates',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'FX Reference Company ' . $suffix,
            'slug' => 'fx-reference-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'FX Reference Property ' . $suffix,
            'slug' => 'fx-reference-property-' . $suffix,
            'code' => 'FXR' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'FX Reference User ' . $suffix,
            'email' => 'fx-reference-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
