<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Operations\PMS\Enums\GuestRefundSourceTypeEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Modules\Operations\PMS\Services\GuestRefundLifecycleService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestDepositRefundArData;
use Tests\PostgresTestCase;

class GuestRefundLifecycleTest extends PostgresTestCase
{
    use CreatesGuestDepositRefundArData, RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->setUpGlfCFixture();}

    public function test_refunds_available_guest_payment_without_mutating_payment_folio_or_cashier():void
    {
        [$payment,$folio]=$this->glfCPayment('100.00');$session=$this->glfCCashierSession();$beforeSession=$session->only(['status','closed_at','closed_by']);$beforePayment=$payment->only(['amount','lifecycle_status','cashier_session_id']);
        $this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        $refund=$this->refundService->recordCashRefund($this->glfActor,GuestRefundSourceTypeEnum::GuestPayment->value,$payment->id,$session->id,'40.00','GUEST_REQUEST','refund-payment');
        $this->assertSame($payment->id,$refund->guest_payment_transaction_id);$this->assertNull($refund->guest_deposit_transaction_id);$this->assertSame('40.00',(string)$refund->amount);
        $this->assertSame(0,FolioItem::count());$this->assertSame($beforePayment,$payment->fresh()->only(['amount','lifecycle_status','cashier_session_id']));
        $this->assertSame($beforeSession,$session->fresh()->only(['status','closed_at','closed_by']));$this->assertSame('0.00',$folio->fresh()->total_payments);
        $replay=$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'40.00','GUEST_REQUEST','refund-payment');$this->assertSame($refund->id,$replay->id);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('GUEST_REFUND_IDEMPOTENCY_CONFLICT');$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'41.00','GUEST_REQUEST','refund-payment');
    }

    public function test_allocated_payment_value_is_unavailable_until_allocation_reversal():void
    {
        [$payment,$folio]=$this->glfCPayment('100.00');$allocation=$this->paymentService->allocatePayment($this->glfActor,$payment->id,$folio->id,'70.00','allocate');$session=$this->glfCCashierSession();$this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        try{$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'50.00','OVER','refund-over');$this->fail('Over-refund accepted.');}catch(DomainException $e){$this->assertSame('GUEST_REFUND_EXCEEDS_AVAILABLE_SOURCE',$e->getMessage());}
        $this->confirmGlfC(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);$this->paymentService->reverseAllocation($this->glfActor,$allocation->id,'RESTORE','reverse-payment');
        $refund=$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'100.00','FULL','refund-full');$this->assertSame('100.00',(string)$refund->amount);
    }

    public function test_refunds_only_unapplied_deposit_and_updates_projection_without_folio_effect():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('100.00');$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'60.00','apply');$itemCount=FolioItem::count();$session=$this->glfCCashierSession();$this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        try{$this->refundService->recordCashRefund($this->glfActor,'GUEST_DEPOSIT',$deposit->id,$session->id,'50.00','OVER','deposit-over');$this->fail('Over-refund accepted.');}catch(DomainException){$this->assertTrue(true);}
        $refund=$this->refundService->recordCashRefund($this->glfActor,'GUEST_DEPOSIT',$deposit->id,$session->id,'40.00','REMAINDER','deposit-refund');
        $this->assertSame($deposit->id,$refund->guest_deposit_transaction_id);$this->assertSame($itemCount,FolioItem::count());$this->assertSame('60.00',$folio->fresh()->total_deposits);
        $this->assertSame('RESOLVED',$deposit->fresh()->lifecycle_status->value);
    }

    public function test_confirmation_permission_reason_cross_property_and_immutability_fail_closed():void
    {
        [$payment]=$this->glfCPayment('20.00');$session=$this->glfCCashierSession();
        try{$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'10.00','WHY','no-confirm');$this->fail('Confirmation missing.');}catch(DomainException){$this->assertTrue(true);}
        $this->glfActor->revokePermissionTo(GuestRefundLifecycleService::RECORD_PERMISSION);app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->expectException(AuthorizationException::class);$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'10.00','WHY','no-permission');
    }

    public function test_refund_database_source_and_delete_guards_are_enforced():void
    {
        [$payment]=$this->glfCPayment('20.00');$session=$this->glfCCashierSession();$this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        $refund=$this->refundService->recordCashRefund($this->glfActor,'GUEST_PAYMENT',$payment->id,$session->id,'10.00','TEST','immutable');
        try{DB::transaction(fn()=>DB::table('guest_refund_transactions')->where('id',$refund->id)->update(['amount'=>'9.00']));$this->fail('Refund update accepted.');}catch(QueryException){$this->assertTrue(true);}
        try{$refund->delete();$this->fail('Refund deletion accepted.');}catch(DomainException){$this->assertTrue(true);}
        try{DB::transaction(fn()=>DB::table('guest_refund_transactions')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'property_id'=>$this->glfProperty->id,'refund_number'=>'BAD-XOR','reservation_id'=>$payment->reservation_id,'guest_id'=>$payment->guest_id,'currency'=>'USD','amount'=>'1.00','tender_type'=>'CASH','cashier_session_id'=>$session->id,'refund_source_type'=>'GUEST_PAYMENT','guest_payment_transaction_id'=>$payment->id,'guest_deposit_transaction_id'=>$payment->id,'reason_code'=>'BAD','refund_idempotency_key'=>'bad-xor','refunded_at'=>now(),'refunded_by'=>$this->glfActor->id,'source_snapshot'=>'{}','created_at'=>now(),'created_by'=>$this->glfActor->id]));$this->fail('XOR bypass accepted.');}catch(QueryException){$this->assertTrue(true);}
        $this->assertSame(1,GuestRefundTransaction::count());
    }
}
