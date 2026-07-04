<?php

namespace Tests\Postgres\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FinanceFinalizationConfirmationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'finance.journal-entry-draft.authorize-finalization', 'guard_name' => 'web']);
    }

    public function test_finalization_without_confirmation_is_denied(): void
    {
        $this->createFixtures();

        $journalId = $this->makeDraftJournal();

        $this->withSession($this->propertySession())
            ->actingAs($this->authorizer, 'web')
            ->post(route('finance.general-ledger.grni-control.journals.authorize-finalization', ['journalEntry' => $journalId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertNull(
            DB::table('gl_journal_entries')->where('id', $journalId)->value('draft_finalization_authorized_by')
        );
    }

    public function test_finalization_with_valid_confirmation_succeeds(): void
    {
        $this->createFixtures();

        $journalId = $this->makeDraftJournal();

        $response = $this->withSession($this->confirmedSession())
            ->actingAs($this->authorizer, 'web')
            ->post(route('finance.general-ledger.grni-control.journals.authorize-finalization', ['journalEntry' => $journalId]));

        $response->assertRedirect();

        $this->assertStringContainsString(
            'finance',
            $response->headers->get('Location') ?: '',
            'Should redirect to a finance route'
        );
    }

    public function test_wrong_intent_confirmation_is_denied_for_finalization(): void
    {
        $this->createFixtures();

        $journalId = $this->makeDraftJournal();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'fx-break-glass' => [
                    'actor_id' => $this->authorizer->id,
                    'intent' => 'fx-break-glass',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->authorizer, 'web')
            ->post(route('finance.general-ledger.grni-control.journals.authorize-finalization', ['journalEntry' => $journalId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_expired_confirmation_is_denied_for_finalization(): void
    {
        $this->createFixtures();

        $journalId = $this->makeDraftJournal();

        $expiredTime = Carbon::now()->subMinutes(20);
        $expiredSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->authorizer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredTime->toISOString(),
                    'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($expiredSession)
            ->actingAs($this->authorizer, 'web')
            ->post(route('finance.general-ledger.grni-control.journals.authorize-finalization', ['journalEntry' => $journalId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_no_role_or_permission_mutation_on_denied_finalization(): void
    {
        $this->createFixtures();

        $journalId = $this->makeDraftJournal();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->authorizer->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->authorizer->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->authorizer, 'web')
            ->post(route('finance.general-ledger.grni-control.journals.authorize-finalization', ['journalEntry' => $journalId]))
            ->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->authorizer->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->authorizer->id)->count());
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'FFC Test Company',
            'slug' => 'ffc-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FFC Test Property',
            'slug' => 'ffc-test-property',
            'code' => 'FFCP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->authorizer = User::create([
            'name' => 'FFC Authorizer',
            'email' => 'ffc-authorizer@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->authorizer->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->authorizer->givePermissionTo('finance.journal-entry-draft.authorize-finalization');
    }

    private function makeDraftJournal(): string
    {
        $journalId = (string) Str::ulid();
        $candidateId = (string) Str::ulid();
        $receiptId = (string) Str::ulid();
        $timestamp = now();

        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $this->property->id,
            'receipt_number' => 'GRN-FIN-001',
            'supplier_name' => 'Test Supplier',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Test GRNI candidate',
            'approved_by' => $this->authorizer->id,
            'approved_at' => now(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $journalId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'reference' => 'JNL-FIN-001',
            'description' => 'Test journal draft',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Inventory',
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'journal_candidate_id' => $candidateId,
            'posting_event' => 'InventoryReceiptAccrual',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $journalId;
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function confirmedSession(): array
    {
        $now = Carbon::now();

        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->authorizer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]);
    }
}
