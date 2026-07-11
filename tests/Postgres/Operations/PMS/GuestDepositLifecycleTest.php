<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestDepositLifecycleService;
use Modules\Operations\PMS\Services\GuestLedgerDepositEffectService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestDepositRefundArData;
use Tests\PostgresTestCase;

class GuestDepositLifecycleTest extends PostgresTestCase
{
    use CreatesGuestDepositRefundArData, RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->setUpGlfCFixture();}

    public function test_records_source_proven_cash_deposit_idempotently_without_folio_or_cashier_mutation():void
    {
        $reservation=$this->makeGlfReservation();$session=$this->glfCCashierSession();$before=$session->only(['status','closed_at','closed_by']);
        $deposit=$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$session->id,'125.00','deposit-record');
        $this->assertSame($this->glfProperty->id,$deposit->property_id);$this->assertSame($reservation->id,$deposit->reservation_id);
        $this->assertSame($reservation->primary_guest_id,$deposit->guest_id);$this->assertSame('USD',$deposit->currency);$this->assertSame('125.00',(string)$deposit->amount);
        $this->assertSame(GuestDepositLifecycleStatusEnum::Recorded,$deposit->lifecycle_status);$this->assertSame(0,FolioItem::count());
        $this->assertSame($before,$session->fresh()->only(['status','closed_at','closed_by']));
        $replay=$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$session->id,'125.00','deposit-record');
        $this->assertSame($deposit->id,$replay->id);$this->assertSame(1,GuestDepositTransaction::count());
        $this->expectException(DomainException::class);$this->expectExceptionMessage('GUEST_DEPOSIT_IDEMPOTENCY_CONFLICT');
        $this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$session->id,'126.00','deposit-record');
    }

    public function test_cash_session_and_decimal_and_permission_guards_fail_closed():void
    {
        $reservation=$this->makeGlfReservation();$session=$this->glfCCashierSession();
        foreach(['1e2','1.239','0','10000000000.00'] as $invalid){try{$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$session->id,$invalid,'invalid-'.$invalid);$this->fail('Invalid decimal accepted.');}catch(\Illuminate\Validation\ValidationException){$this->assertTrue(true);}}
        $this->glfActor->revokePermissionTo(GuestDepositLifecycleService::RECORD_PERMISSION);app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->expectException(AuthorizationException::class);$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$session->id,'1.00','no-permission');
    }

    public function test_partial_full_and_split_application_create_exact_source_items_and_totals():void
    {
        [$deposit,$folioA,$reservation]=$this->glfCDepositAndFolio();$folioB=$this->makeGlfFolio($reservation,$reservation->primaryGuest);
        $a=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folioA->id,'60.00','apply-a');
        $b=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folioB->id,'40.00','apply-b');
        $this->assertSame(2,GuestDepositApplication::count());$this->assertSame(2,FolioItem::where('item_type','deposit')->count());
        $item=FolioItem::where('guest_deposit_application_id',$a->id)->firstOrFail();$this->assertSame(FolioItemTypeEnum::Deposit,$item->item_type);
        $this->assertSame('-60.00',(string)$item->amount);$this->assertSame(GuestLedgerDepositEffectService::SOURCE_APPLICATION,$item->source_type);
        $this->assertSame('60.00',$folioA->fresh()->total_deposits);$this->assertSame('40.00',$folioB->fresh()->total_deposits);
        $this->assertSame(GuestDepositLifecycleStatusEnum::Resolved,$deposit->fresh()->lifecycle_status);
        $replay=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folioA->id,'60.00','apply-a');$this->assertSame($a->id,$replay->id);
        try{$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folioA->id,'1.00','apply-over');$this->fail('Over-application accepted.');}catch(DomainException $e){$this->assertSame('GUEST_DEPOSIT_OVER_APPLICATION',$e->getMessage());}
        $this->assertNotSame($a->id,$b->id);
    }

    public function test_void_requires_confirmation_reason_and_permanently_rejects_application_history():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio();
        try{$this->depositService->voidDeposit($this->glfActor,$deposit->id,'UNUSED','void-1');$this->fail('Confirmation missing.');}catch(DomainException $e){$this->assertStringContainsString('confirmation',$e->getMessage());}
        $this->confirmGlfC(GuestDepositLifecycleService::VOID_INTENT);$void=$this->depositService->voidDeposit($this->glfActor,$deposit->id,'UNUSED','void-1');
        $this->assertSame(GuestDepositReversalTypeEnum::DepositVoid,$void->reversal_type);$this->assertSame(GuestDepositLifecycleStatusEnum::Voided,$deposit->fresh()->lifecycle_status);$this->assertSame(0,FolioItem::count());
        [$used,$usedFolio]=$this->glfCDepositAndFolio('20.00');$this->depositService->applyDeposit($this->glfActor,$used->id,$usedFolio->id,'20.00','used');
        $this->confirmGlfC(GuestDepositLifecycleService::REVERSE_INTENT);$app=GuestDepositApplication::where('guest_deposit_transaction_id',$used->id)->firstOrFail();$this->depositService->reverseDepositApplication($this->glfActor,$app->id,'RESTORE','restore');
        $this->confirmGlfC(GuestDepositLifecycleService::VOID_INTENT);$this->expectException(DomainException::class);$this->expectExceptionMessage('history');$this->depositService->voidDeposit($this->glfActor,$used->id,'NOT_ALLOWED','void-used');
    }

    public function test_full_application_reversal_preserves_original_and_restores_available_balance():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('80.00');$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'80.00','apply');$original=FolioItem::where('guest_deposit_application_id',$application->id)->where('item_type','deposit')->firstOrFail();
        $this->confirmGlfC(GuestDepositLifecycleService::REVERSE_INTENT);$reversal=$this->depositService->reverseDepositApplication($this->glfActor,$application->id,'ERROR','reverse');
        $item=FolioItem::where('guest_deposit_reversal_id',$reversal->id)->firstOrFail();$this->assertSame('80.00',(string)$item->amount);$this->assertSame($original->id,$item->reverses_folio_item_id);
        $this->assertSame('-80.00',(string)$original->fresh()->amount);$this->assertFalse($original->fresh()->is_void);$this->assertSame('0.00',$folio->fresh()->total_deposits);
        $this->assertSame(GuestDepositLifecycleStatusEnum::Recorded,$deposit->fresh()->lifecycle_status);
        $this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'80.00','reapply');$this->assertSame(3,FolioItem::count());
    }

    public function test_database_and_generic_boundaries_protect_source_evidence():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('30.00');$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'30.00','apply-db');
        try{DB::transaction(fn()=>DB::table('guest_deposit_applications')->where('id',$application->id)->update(['amount'=>'29.00']));$this->fail('Immutable update accepted.');}catch(QueryException){$this->assertTrue(true);}
        try{$deposit->delete();$this->fail('Delete accepted.');}catch(DomainException){$this->assertTrue(true);}
        foreach([FolioItemTypeEnum::Deposit,FolioItemTypeEnum::DepositReversal,FolioItemTypeEnum::ArTransfer,FolioItemTypeEnum::ArTransferReversal] as $type){try{$this->folioService->postItem($this->glfActor,$folio->id,['item_type'=>$type->value,'description'=>'bypass','quantity'=>'1.00','amount'=>'1.00']);$this->fail('Generic source category accepted.');}catch(\Illuminate\Validation\ValidationException){$this->assertTrue(true);}}
        $sourceItem=FolioItem::where('guest_deposit_application_id',$application->id)->firstOrFail();$this->expectException(\Illuminate\Validation\ValidationException::class);$this->folioService->voidItem($this->glfActor,$sourceItem->id);
    }

    public function test_deposit_application_reversal_requires_confirmation():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('50.00');$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'50.00','conf-rev-setup');
        $reversalCount=GuestDepositReversal::count();$itemCount=FolioItem::count();$status=$deposit->fresh()->lifecycle_status;$totals=$folio->fresh()->total_deposits;
        try{$this->depositService->reverseDepositApplication($this->glfActor,$application->id,'TEST','conf-rev-1');$this->fail('Confirmation missing.');}catch(DomainException $e){$this->assertStringContainsString('confirmation',$e->getMessage());}
        $this->assertSame($reversalCount,GuestDepositReversal::count());$this->assertSame($itemCount,FolioItem::count());$this->assertSame($status,$deposit->fresh()->lifecycle_status);$this->assertSame($totals,$folio->fresh()->total_deposits);
    }

    public function test_duplicate_deposit_application_folio_item_rejected_by_database():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('30.00');$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'30.00','dup-app-setup');
        $idx=DB::selectOne("SELECT pg_get_indexdef(i.indexrelid) as def FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname='folio_items_deposit_app_source_unique'");
        $this->assertNotNull($idx,'partial unique index folio_items_deposit_app_source_unique missing');$this->assertStringContainsString('guest_deposit_application_id',$idx->def);$this->assertStringContainsString('UNIQUE',strtoupper($idx->def));$this->assertStringContainsString('IS NOT NULL',strtoupper($idx->def));
        $this->assertSame(1,FolioItem::where('guest_deposit_application_id',$application->id)->count());
        $dupId=(string)Str::ulid();try{DB::transaction(fn()=>DB::table('folio_items')->insert(['id'=>$dupId,'property_id'=>$folio->property_id,'folio_id'=>$folio->id,'item_type'=>'deposit','description'=>'duplicate','quantity'=>'1.00','amount'=>'-30.00','is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'source_domain'=>'pms_cashiering','source_type'=>'guest_deposit_application','source_id'=>$application->id,'guest_deposit_application_id'=>$application->id,'guest_deposit_reversal_id'=>null,'guest_ar_transfer_decision_id'=>null,'guest_payment_allocation_id'=>null,'guest_payment_reversal_id'=>null,'reverses_folio_item_id'=>null,'created_at'=>now(),'updated_at'=>now()]));$this->fail('Duplicate insert accepted.');}catch(QueryException $e){$code=(string)$e->getCode();$this->assertTrue(str_contains($code,'23505')||str_contains($code,'P0001'),"Expected 23505 or P0001, got: {$code}");}
        $this->assertSame(1,FolioItem::where('guest_deposit_application_id',$application->id)->count());$this->assertSame('30.00',(string)$application->fresh()->amount);$this->assertNotNull(FolioItem::where('guest_deposit_application_id',$application->id)->first());
    }

    public function test_duplicate_deposit_reversal_folio_item_rejected_by_database():void
    {
        [$deposit,$folio]=$this->glfCDepositAndFolio('30.00');$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'30.00','dup-rev-setup');
        $this->confirmGlfC(GuestDepositLifecycleService::REVERSE_INTENT);$reversal=$this->depositService->reverseDepositApplication($this->glfActor,$application->id,'TEST','dup-rev-reverse');
        $idx=DB::selectOne("SELECT pg_get_indexdef(i.indexrelid) as def FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname='folio_items_deposit_reversal_source_unique'");
        $this->assertNotNull($idx,'partial unique index folio_items_deposit_reversal_source_unique missing');$this->assertStringContainsString('guest_deposit_reversal_id',$idx->def);$this->assertStringContainsString('UNIQUE',strtoupper($idx->def));$this->assertStringContainsString('IS NOT NULL',strtoupper($idx->def));
        $this->assertSame(1,FolioItem::where('guest_deposit_reversal_id',$reversal->id)->count());$firstRev=FolioItem::where('guest_deposit_reversal_id',$reversal->id)->first();
        $dupId=(string)Str::ulid();try{DB::transaction(fn()=>DB::table('folio_items')->insert(['id'=>$dupId,'property_id'=>$folio->property_id,'folio_id'=>$folio->id,'item_type'=>'deposit_reversal','description'=>'duplicate-rev','quantity'=>'1.00','amount'=>'30.00','is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'source_domain'=>'pms_cashiering','source_type'=>'guest_deposit_application_reversal','source_id'=>$reversal->id,'guest_deposit_application_id'=>$application->id,'guest_deposit_reversal_id'=>$reversal->id,'guest_ar_transfer_decision_id'=>null,'guest_payment_allocation_id'=>null,'guest_payment_reversal_id'=>null,'reverses_folio_item_id'=>$firstRev->reverses_folio_item_id,'created_at'=>now(),'updated_at'=>now()]));$this->fail('Duplicate reversal insert accepted.');}catch(QueryException $e){$code=(string)$e->getCode();$this->assertTrue(str_contains($code,'23505')||str_contains($code,'P0001'),"Expected 23505 or P0001, got: {$code}");}
        $this->assertSame(1,FolioItem::where('guest_deposit_reversal_id',$reversal->id)->count());$this->assertSame('30.00',(string)$reversal->fresh()->amount);
    }

    public function test_cross_property_deposit_access_is_non_disclosing():void
    {
        $unknownId=(string)Str::ulid();$reservation=$this->makeGlfReservation();$session=$this->glfCCashierSession();
        $guestB=$this->makeGlfGuest($this->glfOtherProperty);$reservationB=$this->makeGlfReservation($this->glfOtherProperty,$guestB);$folioB=$this->makeGlfFolio($reservationB,$guestB,['currency'=>'EUR','folio_number'=>'CPB-FOL']);
        $sessionB=new CashierSession();$sessionB->forceFill(['property_id'=>$this->glfOtherProperty->id,'cashier_user_id'=>$this->glfActor->id,'status'=>'OPEN','opened_at'=>now(),'opened_by'=>$this->glfActor->id])->save();
        [$deposit,$folio]=$this->glfCDepositAndFolio();$sessionA=$this->glfCCashierSession();$application=$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folio->id,'40.00','cp-dep-setup');
        // 1. Property-B CashierSession during recording
        try{$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$sessionB->id,'10.00','cp-session');$this->fail('Cross-property session accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$unknownId,'10.00','cp-unknown-1');$this->fail('Unknown session accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        $this->assertSame(0,GuestDepositTransaction::where('recording_idempotency_key','like','cp-%')->count());
        // 2. Property-B Deposit during application
        $depositB=new GuestDepositTransaction();$depositB->forceFill(['property_id'=>$this->glfOtherProperty->id,'deposit_number'=>'CPB-DEP','reservation_id'=>$reservationB->id,'guest_id'=>$guestB->id,'currency'=>'EUR','amount'=>'50.00','tender_type'=>'CASH','cashier_session_id'=>$sessionB->id,'lifecycle_status'=>'RECORDED','recording_idempotency_key'=>'cp-dep-b','recorded_at'=>now(),'recorded_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([]),'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id])->save();
        try{$this->depositService->applyDeposit($this->glfActor,$depositB->id,$folio->id,'10.00','cp-dep-app');$this->fail('Cross-property deposit accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->depositService->applyDeposit($this->glfActor,$unknownId,$folio->id,'10.00','cp-unknown-2');$this->fail('Unknown deposit accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // 3. Property-B Folio during application
        try{$this->depositService->applyDeposit($this->glfActor,$deposit->id,$folioB->id,'10.00','cp-folio-app');$this->fail('Cross-property folio accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->depositService->applyDeposit($this->glfActor,$deposit->id,$unknownId,'10.00','cp-unknown-3');$this->fail('Unknown folio accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        $this->assertSame(1,GuestDepositApplication::count());
        // 4. Property-B DepositApplication during reversal
        $appBId=(string)Str::ulid();DB::table('guest_deposit_applications')->insert(['id'=>$appBId,'property_id'=>$this->glfOtherProperty->id,'guest_deposit_transaction_id'=>$depositB->id,'folio_id'=>$folioB->id,'amount'=>'10.00','application_idempotency_key'=>'cp-app-b','applied_at'=>now(),'applied_by'=>$this->glfActor->id,'source_snapshot'=>'{}','created_at'=>now()]);
        app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm($this->glfActor,GuestDepositLifecycleService::REVERSE_INTENT,'password',$this->glfCompany->id,$this->glfProperty->id);
        try{$this->depositService->reverseDepositApplication($this->glfActor,$appBId,'TEST','cp-app-rev');$this->fail('Cross-property application accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->depositService->reverseDepositApplication($this->glfActor,$unknownId,'TEST','cp-unknown-4');$this->fail('Unknown application accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // 5. Property-B Deposit during applyDeposit (no confirmation gate) — cross-property deposit is non-disclosing
        try{$this->depositService->applyDeposit($this->glfActor,$depositB->id,$folio->id,'10.00','cp-dep-app-b');$this->fail('Cross-property deposit application accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        try{$this->depositService->applyDeposit($this->glfActor,$unknownId,$folio->id,'10.00','cp-dep-unknown-b');$this->fail('Unknown deposit application accepted.');}catch(ModelNotFoundException $e){$this->assertNotNull($e);}
        // Verify no mutation in either property
        $this->assertSame(1,GuestDepositTransaction::where('property_id',$this->glfProperty->id)->count());$this->assertNotNull($depositB->fresh());$this->assertNotNull($folioB->fresh());$this->assertNotNull(GuestDepositApplication::find($application->id));
    }
}
