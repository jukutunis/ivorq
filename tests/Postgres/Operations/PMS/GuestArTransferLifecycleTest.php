<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
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

    private function requestFixture(string $charge,string $transfer):array{$reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$this->postGlfCCharge($folio,$charge);$request=$this->arRequestService->requestTransfer($this->glfActor,$folio->id,$transfer,'DIRECT_BILL','req-'.$folio->id);return[$request,$folio];}
}
