<?php

namespace Tests\Feature\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Modules\Finance\Payables\Enums\PaymentMethodEnum;
use Modules\Finance\Payables\Enums\PaymentVoucherStatusEnum;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Finance\Payables\Models\PaymentVoucherLine;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Foundation\Authorization\Models\Permission;
use Tests\TestCase;

class PaymentProcessingModuleTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Operations\Concerns\CreatesPurchasingData;

    private User $user;
    private $property;
    private $otherProperty;
    private $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        $this->seedPurchasingPermissions();

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->otherProperty = $this->createProperty($company);
        
        $this->user = $this->createPropertyAdmin($this->property);

        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, ['is_approved' => true]);

        Permission::firstOrCreate(['name' => 'payables.payment.view']);
        Permission::firstOrCreate(['name' => 'payables.payment.create']);
        Permission::firstOrCreate(['name' => 'payables.payment.post']);
        Permission::firstOrCreate(['name' => 'payables.payment.cancel']);
        $this->user->givePermissionTo([
            'payables.payment.view',
            'payables.payment.create',
            'payables.payment.post',
            'payables.payment.cancel',
        ]);
    }

    private function createAp(float $amount, float $outstanding, AccountPayableStatusEnum $status): AccountPayable
    {
        $invoice = \Modules\Finance\AccountsPayable\Models\ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => \Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum::APPROVED,
        ]);

        return AccountPayable::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'vendor_invoice_id' => $invoice->id,
            'amount' => $amount,
            'outstanding_amount' => $outstanding,
            'status' => $status,
        ]);
    }

    public function test_can_create_draft_payment_voucher()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/payment-vouchers', [
                'vendor_id' => $this->vendor->id,
                'payment_date' => '2026-06-11',
                'payment_method' => PaymentMethodEnum::BankTransfer->value,
                'lines' => [
                    [
                        'account_payable_id' => $ap->id,
                        'amount_paid' => 500,
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', PaymentVoucherStatusEnum::Draft->value)
            ->assertJsonPath('data.total_amount', '500.00');

        $this->assertEquals('1000.00', $ap->fresh()->outstanding_amount); // AP is untouched until posted
    }

    public function test_cannot_pay_more_than_outstanding_amount()
    {
        $ap = $this->createAp(1000, 600, AccountPayableStatusEnum::PartiallyPaid);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/payment-vouchers', [
                'vendor_id' => $this->vendor->id,
                'payment_date' => '2026-06-11',
                'payment_method' => PaymentMethodEnum::BankTransfer->value,
                'lines' => [
                    [
                        'account_payable_id' => $ap->id,
                        'amount_paid' => 600.01,
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(400);
    }

    public function test_cannot_pay_cancelled_or_paid_ap()
    {
        $ap = $this->createAp(1000, 0, AccountPayableStatusEnum::Paid);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/payment-vouchers', [
                'vendor_id' => $this->vendor->id,
                'payment_date' => '2026-06-11',
                'payment_method' => PaymentMethodEnum::BankTransfer->value,
                'lines' => [
                    [
                        'account_payable_id' => $ap->id,
                        'amount_paid' => 500,
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(400);
    }

    public function test_post_payment_deducts_ap_outstanding_and_updates_status()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [
                [
                    'account_payable_id' => $ap->id,
                    'amount_paid' => 1000,
                ]
            ]
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/payment-vouchers/{$pv->id}/post", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals('0.00', $ap->fresh()->outstanding_amount);
        $this->assertEquals(AccountPayableStatusEnum::Paid, $ap->fresh()->status);
        $this->assertEquals(PaymentVoucherStatusEnum::Posted, $pv->fresh()->status);
    }

    public function test_partial_payment_sets_ap_partially_paid()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [
                [
                    'account_payable_id' => $ap->id,
                    'amount_paid' => 400,
                ]
            ]
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payables/payment-vouchers/{$pv->id}/post", [], ['X-Property-Id' => $this->property->id]);

        $this->assertEquals('600.00', $ap->fresh()->outstanding_amount);
        $this->assertEquals(AccountPayableStatusEnum::PartiallyPaid, $ap->fresh()->status);
    }

    public function test_payment_line_stores_ap_snapshot()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [
                [
                    'account_payable_id' => $ap->id,
                    'amount_paid' => 400,
                ]
            ]
        ]);

        $line = $pv->lines->first();
        $this->assertEquals($ap->payable_no, $line->ap_payable_no);
        $this->assertEquals('1000.00', $line->ap_original_amount);
        $this->assertEquals('1000.00', $line->ap_outstanding_before);
        $this->assertNull($line->ap_outstanding_after);

        app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->post($pv);

        $this->assertEquals('600.00', $line->fresh()->ap_outstanding_after);
    }

    public function test_cancel_payment_reverts_ap_outstanding()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [['account_payable_id' => $ap->id, 'amount_paid' => 400]]
        ]);

        app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->post($pv);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/payment-vouchers/{$pv->id}/cancel", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);

        $this->assertEquals(PaymentVoucherStatusEnum::Cancelled, $pv->fresh()->status);
        $this->assertEquals('1000.00', $ap->fresh()->outstanding_amount);
        $this->assertEquals(AccountPayableStatusEnum::Open, $ap->fresh()->status);
    }

    public function test_cannot_edit_or_post_already_posted_voucher()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [['account_payable_id' => $ap->id, 'amount_paid' => 400]]
        ]);

        app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->post($pv);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/payment-vouchers/{$pv->id}/post", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(400); // Already posted
    }

    public function test_property_isolation_on_payment()
    {
        $invoice = \Modules\Finance\AccountsPayable\Models\ApInvoice::factory()->create([
            'property_id' => $this->otherProperty->id,
            'vendor_id' => $this->vendor->id,
            'status' => \Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum::APPROVED,
        ]);

        $ap = AccountPayable::factory()->create([
            'property_id' => $this->otherProperty->id,
            'vendor_id' => $this->vendor->id,
            'vendor_invoice_id' => $invoice->id,
            'amount' => 1000,
            'outstanding_amount' => 1000,
            'status' => AccountPayableStatusEnum::Open,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/payment-vouchers', [
                'vendor_id' => $this->vendor->id,
                'payment_date' => '2026-06-11',
                'payment_method' => PaymentMethodEnum::BankTransfer->value,
                'lines' => [
                    [
                        'account_payable_id' => $ap->id,
                        'amount_paid' => 500,
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        // Returns 404 because AP is scoped by property
        $response->assertStatus(404);
    }

    public function test_cancelled_payment_creates_audit_log()
    {
        $ap = $this->createAp(1000, 1000, AccountPayableStatusEnum::Open);

        $pv = app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'payment_date' => '2026-06-11',
            'payment_method' => PaymentMethodEnum::BankTransfer->value,
            'lines' => [['account_payable_id' => $ap->id, 'amount_paid' => 400]]
        ]);

        app(\Modules\Finance\Payables\Services\PaymentVoucherService::class)->post($pv);
        
        // Clear audit logs created by setup
        AuditLog::truncate();

        $this->actingAs($this->user)->postJson("/api/v1/payables/payment-vouchers/{$pv->id}/cancel", [], ['X-Property-Id' => $this->property->id]);

        $logs = AuditLog::where('auditable_type', PaymentVoucher::class)
            ->where('auditable_id', $pv->id)
            ->get();

        $this->assertNotEmpty($logs);
        $this->assertEquals(PaymentVoucherStatusEnum::Cancelled->value, collect($logs->last()->new_values)->get('status'));
    }
}
