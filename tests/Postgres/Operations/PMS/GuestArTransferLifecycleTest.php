<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestArTransferRequestService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestDepositRefundArData;
use Tests\PostgresTestCase;

class GuestArTransferLifecycleTest extends PostgresTestCase
{
    use CreatesGuestDepositRefundArData, RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->setUpGlfCFixture();}

    public function test_permission_seeders_register_every_narrow_glf_c_command_permission():void
    {
        $this->seed(\Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Modules\Operations\PMS\Database\Seeders\PmsPermissionSeeder::class);
        foreach ([
            \Modules\Operations\PMS\Services\GuestDepositLifecycleService::RECORD_PERMISSION,
            \Modules\Operations\PMS\Services\GuestDepositLifecycleService::APPLY_PERMISSION,
            \Modules\Operations\PMS\Services\GuestDepositLifecycleService::VOID_PERMISSION,
            \Modules\Operations\PMS\Services\GuestDepositLifecycleService::REVERSE_PERMISSION,
            \Modules\Operations\PMS\Services\GuestRefundLifecycleService::RECORD_PERMISSION,
            GuestArTransferRequestService::REQUEST_PERMISSION,
            GuestArTransferDecisionService::ACCEPT_PERMISSION,
            GuestArTransferDecisionService::REJECT_PERMISSION,
            GuestArTransferDecisionService::REVERSE_PERMISSION,
        ] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_requests_transfer_from_current_open_positive_folio_without_financial_effect():void
    {
        $reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$this->postGlfCCharge($folio,'150.00');
        $request=$this->arRequestService->requestTransfer($this->glfActor,$folio->id,'100.00','DIRECT_BILL','request-1');
        $this->assertSame($folio->id,$request->folio_id);$this->assertSame($reservation->id,$request->reservation_id);$this->assertSame('USD',$request->currency);
        $this->assertSame(GuestArTransferStatusEnum::Requested,$request->lifecycle_status);$this->assertSame(1,FolioItem::count());$this->assertSame('150.00',$folio->fresh()->balance);
        $replay=$this->arRequestService->requestTransfer($this->glfActor,$folio->id,'100.00','DIRECT_BILL','request-1');$this->assertSame($request->id,$replay->id);
        try{$this->arRequestService->requestTransfer($this->glfActor,$folio->id,'151.00','OVER','request-over');$this->fail('Over-transfer accepted.');}catch(DomainException){$this->assertTrue(true);}
    }

    public function test_accounting_ar_acceptance_revalidates_balance_and_creates_one_exact_effect():void
    {
        [$request,$folio]=$this->requestFixture('120.00','80.00');
        try{$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'APPROVED','accept-1');$this->fail('Confirmation missing.');}catch(DomainException){$this->assertTrue(true);}
        $this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$decision=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'APPROVED','accept-1');
        $item=FolioItem::where('guest_ar_transfer_decision_id',$decision->id)->firstOrFail();$this->assertSame(GuestArTransferDecisionTypeEnum::Accepted,$decision->decision_type);
        $this->assertSame(FolioItemTypeEnum::ArTransfer,$item->item_type);$this->assertSame('-80.00',(string)$item->amount);$this->assertSame('accounting_ar',$item->source_domain);
        $this->assertSame('80.00',$folio->fresh()->total_ar_transfers);$this->assertSame('40.00',$folio->fresh()->balance);$this->assertSame(GuestArTransferStatusEnum::Accepted,$request->fresh()->lifecycle_status);
        $replay=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'APPROVED','accept-1');$this->assertSame($decision->id,$replay->id);$this->assertSame(1,GuestArTransferDecision::where('decision_type','ACCEPTED')->count());
    }

    public function test_rejection_is_terminal_and_creates_no_folio_or_accounting_effect():void
    {
        [$request,$folio]=$this->requestFixture('75.00','50.00');$items=FolioItem::count();$this->confirmGlfC(GuestArTransferDecisionService::REJECT_INTENT);
        $decision=$this->arDecisionService->rejectTransfer($this->glfActor,$request->id,'CREDIT_REJECTED','reject');
        $this->assertSame(GuestArTransferDecisionTypeEnum::Rejected,$decision->decision_type);$this->assertSame($items,FolioItem::count());$this->assertSame('75.00',$folio->fresh()->balance);
        $this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$this->expectException(DomainException::class);$this->expectExceptionMessage('terminal');$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'LATE','late-accept');
    }

    public function test_accepted_transfer_reversal_is_append_only_and_restores_exact_balance():void
    {
        [$request,$folio]=$this->requestFixture('100.00','100.00');$this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$accepted=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','accept');$original=FolioItem::where('guest_ar_transfer_decision_id',$accepted->id)->firstOrFail();
        $this->confirmGlfC(GuestArTransferDecisionService::REVERSE_INTENT);$reversal=$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$request->id,'WRONG_ACCOUNT','reverse');
        $item=FolioItem::where('guest_ar_transfer_decision_id',$reversal->id)->firstOrFail();$this->assertSame(GuestArTransferDecisionTypeEnum::Reversed,$reversal->decision_type);
        $this->assertSame(FolioItemTypeEnum::ArTransferReversal,$item->item_type);$this->assertSame('100.00',(string)$item->amount);$this->assertSame($original->id,$item->reverses_folio_item_id);
        $this->assertSame('-100.00',(string)$original->fresh()->amount);$this->assertSame('0.00',$folio->fresh()->total_ar_transfers);$this->assertSame('100.00',$folio->fresh()->balance);
        $this->assertSame(GuestArTransferStatusEnum::Reversed,$request->fresh()->lifecycle_status);$this->assertSame(2,GuestArTransferDecision::count());
        $this->expectException(DomainException::class);$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$request->id,'AGAIN','reverse-again');
    }

    public function test_permission_immutability_and_zero_balance_non_readiness_boundaries_hold():void
    {
        [$request,$folio]=$this->requestFixture('30.00','30.00');$this->glfActor->revokePermissionTo(GuestArTransferDecisionService::ACCEPT_PERMISSION);app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'NO','no-permission');$this->fail('Permission bypassed.');}catch(AuthorizationException){$this->assertTrue(true);}
        $this->glfActor->givePermissionTo(GuestArTransferDecisionService::ACCEPT_PERMISSION);app(PermissionRegistrar::class)->forgetCachedPermissions();$this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$decision=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','accepted');
        $this->assertSame('0.00',$folio->fresh()->balance);$this->assertSame('open',$folio->fresh()->status->value);$this->assertFalse(DB::getSchemaBuilder()->hasTable('guest_ledger_settlement_readiness'));
        try{DB::transaction(fn()=>DB::table('guest_ar_transfer_decisions')->where('id',$decision->id)->update(['reason_code'=>'EDIT']));$this->fail('Decision mutation accepted.');}catch(QueryException){$this->assertTrue(true);}
        try{$request->delete();$this->fail('Request deletion accepted.');}catch(DomainException){$this->assertTrue(true);}
    }

    public function test_canonical_totals_keep_operational_negative_adjustments_separate_from_settlement_categories():void
    {
        $reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$this->postGlfCCharge($folio,'100.00');
        $this->folioService->postItem($this->glfActor,$folio->id,['item_type'=>'adjustment','description'=>'credit adjustment','quantity'=>'1.00','amount'=>'-10.00']);
        $this->assertSame('90.00',$folio->fresh()->total_charges);$this->assertSame('0.00',$folio->fresh()->total_payments);$this->assertSame('0.00',$folio->fresh()->total_deposits);$this->assertSame('0.00',$folio->fresh()->total_ar_transfers);$this->assertSame('90.00',$folio->fresh()->balance);
        $this->folioService->recalculateTotals($folio->id,$this->glfProperty->id);$this->assertSame('90.00',$folio->fresh()->balance);
    }

    public function test_ar_reject_requires_confirmation():void
    {
        [$request,$folio]=$this->requestFixture('75.00','50.00');$decisionCount=GuestArTransferDecision::count();$items=FolioItem::count();
        $lifecycle=$request->fresh()->lifecycle_status;$totals=$folio->fresh()->total_ar_transfers;
        try{$this->arDecisionService->rejectTransfer($this->glfActor,$request->id,'CREDIT_REJECTED','reject-no-confirm');$this->fail('Reject without confirmation accepted.');}catch(DomainException $e){$this->assertStringContainsString('confirmation',$e->getMessage());}
        $this->assertSame($decisionCount,GuestArTransferDecision::count());$this->assertSame($items,FolioItem::count());$this->assertSame($lifecycle,$request->fresh()->lifecycle_status);$this->assertSame($totals,$folio->fresh()->total_ar_transfers);
    }

    public function test_ar_reverse_requires_confirmation():void
    {
        [$request,$folio]=$this->requestFixture('100.00','100.00');$this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$accepted=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','accept-conf-rev');
        $decisionCount=GuestArTransferDecision::count();$items=FolioItem::count();$lifecycle=$request->fresh()->lifecycle_status;$totals=$folio->fresh()->total_ar_transfers;
        try{$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$request->id,'WRONG','reverse-no-confirm');$this->fail('Reverse without confirmation accepted.');}catch(DomainException $e){$this->assertStringContainsString('confirmation',$e->getMessage());}
        $this->assertSame($decisionCount,GuestArTransferDecision::count());$this->assertSame($items,FolioItem::count());$this->assertSame($lifecycle,$request->fresh()->lifecycle_status);$this->assertSame($totals,$folio->fresh()->total_ar_transfers);
    }

    public function test_duplicate_ar_acceptance_folio_item_rejected_by_database():void
    {
        [$request,$folio]=$this->requestFixture('50.00','50.00');$this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$decision=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','dup-ar-accept');
        $idx=DB::selectOne("SELECT pg_get_indexdef(i.indexrelid) as def FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname='folio_items_ar_decision_source_unique'");
        $this->assertNotNull($idx,'partial unique index folio_items_ar_decision_source_unique missing');$this->assertStringContainsString('guest_ar_transfer_decision_id',$idx->def);$this->assertStringContainsString('UNIQUE',strtoupper($idx->def));$this->assertStringContainsString('IS NOT NULL',strtoupper($idx->def));
        $this->assertSame(1,FolioItem::where('guest_ar_transfer_decision_id',$decision->id)->count());
        $dupId=(string)Str::ulid();try{DB::transaction(fn()=>DB::table('folio_items')->insert(['id'=>$dupId,'property_id'=>$folio->property_id,'folio_id'=>$folio->id,'item_type'=>'ar_transfer','description'=>'duplicate-ar','quantity'=>'1.00','amount'=>'-50.00','is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'source_domain'=>'accounting_ar','source_type'=>'guest_ar_transfer_acceptance','source_id'=>$decision->id,'guest_deposit_application_id'=>null,'guest_deposit_reversal_id'=>null,'guest_ar_transfer_decision_id'=>$decision->id,'guest_payment_allocation_id'=>null,'guest_payment_reversal_id'=>null,'reverses_folio_item_id'=>null,'created_at'=>now(),'updated_at'=>now()]));$this->fail('Duplicate AR acceptance insert accepted.');}catch(QueryException $e){$code=(string)$e->getCode();$this->assertTrue(str_contains($code,'23505')||str_contains($code,'P0001'),"Expected 23505 or P0001, got: {$code}");}
        $this->assertSame(1,FolioItem::where('guest_ar_transfer_decision_id',$decision->id)->count());$this->assertSame('ACCEPTED',$decision->fresh()->decision_type->value);
    }

    public function test_duplicate_ar_reversal_folio_item_rejected_by_database():void
    {
        [$request,$folio]=$this->requestFixture('50.00','50.00');$this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$accepted=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','dup-ar-rev-setup');
        $this->confirmGlfC(GuestArTransferDecisionService::REVERSE_INTENT);$reversal=$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$request->id,'WRONG','dup-ar-rev');
        $this->assertSame(1,FolioItem::where('guest_ar_transfer_decision_id',$reversal->id)->count());$originalItem=FolioItem::where('guest_ar_transfer_decision_id',$accepted->id)->first();
        $dupId=(string)Str::ulid();try{DB::transaction(fn()=>DB::table('folio_items')->insert(['id'=>$dupId,'property_id'=>$folio->property_id,'folio_id'=>$folio->id,'item_type'=>'ar_transfer_reversal','description'=>'duplicate-ar-rev','quantity'=>'1.00','amount'=>'50.00','is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'source_domain'=>'accounting_ar','source_type'=>'guest_ar_transfer_reversal','source_id'=>$reversal->id,'guest_deposit_application_id'=>null,'guest_deposit_reversal_id'=>null,'guest_ar_transfer_decision_id'=>$reversal->id,'guest_payment_allocation_id'=>null,'guest_payment_reversal_id'=>null,'reverses_folio_item_id'=>$originalItem->id,'created_at'=>now(),'updated_at'=>now()]));$this->fail('Duplicate AR reversal insert accepted.');}catch(QueryException $e){$code=(string)$e->getCode();$this->assertTrue(str_contains($code,'23505')||str_contains($code,'P0001'),"Expected 23505 or P0001, got: {$code}");}
        $this->assertSame(1,FolioItem::where('guest_ar_transfer_decision_id',$reversal->id)->count());$this->assertSame('REVERSED',$reversal->fresh()->decision_type->value);
    }

    public function test_cross_property_ar_transfer_access_is_non_disclosing():void
    {
        $unknownId=(string)Str::ulid();[$request,$folio]=$this->requestFixture('80.00','50.00');
        $guestB=$this->makeGlfGuest($this->glfOtherProperty);$reservationB=$this->makeGlfReservation($this->glfOtherProperty,$guestB);$folioB=$this->makeGlfFolio($reservationB,$guestB,['currency'=>'EUR','folio_number'=>'CPB-AR-FOL']);
        // 1. Property-B Folio during request
        try{$this->arRequestService->requestTransfer($this->glfActor,$folioB->id,'10.00','TEST','cp-ar-req-1');$this->fail('Cross-property folio accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->arRequestService->requestTransfer($this->glfActor,$unknownId,'10.00','TEST','cp-ar-req-unknown');$this->fail('Unknown folio accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // 2. Property-B transfer request during accept
        $reqBId=(string)Str::ulid();DB::table('guest_ar_transfer_requests')->insert(['id'=>$reqBId,'property_id'=>$this->glfOtherProperty->id,'transfer_number'=>'CPB-GAR','folio_id'=>$folioB->id,'reservation_id'=>$reservationB->id,'guest_id'=>$guestB->id,'currency'=>'EUR','amount'=>'30.00','lifecycle_status'=>'REQUESTED','request_reason_code'=>'TEST','request_idempotency_key'=>'cp-ar-req-b','requested_at'=>now(),'requested_by'=>$this->glfActor->id,'source_snapshot'=>'{}','created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);try{$this->arDecisionService->acceptTransfer($this->glfActor,$reqBId,'YES','cp-ar-acc-1');$this->fail('Cross-property request accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->arDecisionService->acceptTransfer($this->glfActor,$unknownId,'YES','cp-ar-acc-unknown');$this->fail('Unknown request accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // 3. Property-B transfer request during reject
        $this->confirmGlfC(GuestArTransferDecisionService::REJECT_INTENT);try{$this->arDecisionService->rejectTransfer($this->glfActor,$reqBId,'NO','cp-ar-rej-1');$this->fail('Cross-property reject accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->arDecisionService->rejectTransfer($this->glfActor,$unknownId,'NO','cp-ar-rej-unknown');$this->fail('Unknown reject accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // 4. Property-B accepted request during reversal
        $this->confirmGlfC(GuestArTransferDecisionService::ACCEPT_INTENT);$accepted=$this->arDecisionService->acceptTransfer($this->glfActor,$request->id,'YES','cp-ar-acc-setup');
        $this->confirmGlfC(GuestArTransferDecisionService::REVERSE_INTENT);try{$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$reqBId,'WRONG','cp-ar-rev-1');$this->fail('Cross-property reverse accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->arDecisionService->reverseAcceptedTransfer($this->glfActor,$unknownId,'WRONG','cp-ar-rev-unknown');$this->fail('Unknown reverse accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        $this->assertNotNull($folioB->fresh());$this->assertNotNull($request->fresh());$this->assertNotNull($accepted->fresh());
    }

    private function requestFixture(string $charge,string $transfer):array{$reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$this->postGlfCCharge($folio,$charge);$request=$this->arRequestService->requestTransfer($this->glfActor,$folio->id,$transfer,'DIRECT_BILL','req-'.$folio->id);return[$request,$folio];}
}
