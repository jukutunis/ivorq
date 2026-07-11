<?php

namespace Tests\Postgres\Operations\PMS\Concerns;

use Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestArTransferRequestService;
use Modules\Operations\PMS\Services\GuestDepositLifecycleService;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Modules\Operations\PMS\Services\GuestRefundLifecycleService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;

trait CreatesGuestDepositRefundArData
{
    use CreatesGuestLedgerFolioData;

    protected GuestDepositLifecycleService $depositService;
    protected GuestRefundLifecycleService $refundService;
    protected GuestPaymentLifecycleService $paymentService;
    protected GuestArTransferRequestService $arRequestService;
    protected GuestArTransferDecisionService $arDecisionService;
    protected GuestLedgerFolioAggregateService $folioService;

    protected function setUpGlfCFixture(): void
    {
        $this->setUpGuestLedgerFolioFixture();
        $this->actingAs($this->glfActor)->withSession([
            'active_property_id'=>$this->glfProperty->id,'current_property_id'=>$this->glfProperty->id,'active_company_id'=>$this->glfCompany->id,
        ]);
        app(CurrentPropertyService::class)->setPropertyId($this->glfProperty->id);
        $permissions=[GuestDepositLifecycleService::RECORD_PERMISSION,GuestDepositLifecycleService::APPLY_PERMISSION,
            GuestDepositLifecycleService::VOID_PERMISSION,GuestDepositLifecycleService::REVERSE_PERMISSION,
            GuestRefundLifecycleService::RECORD_PERMISSION,GuestPaymentLifecycleService::RECORD_PERMISSION,
            GuestPaymentLifecycleService::ALLOCATE_PERMISSION,GuestPaymentLifecycleService::REVERSAL_PERMISSION,
            GuestArTransferRequestService::REQUEST_PERMISSION,GuestArTransferDecisionService::ACCEPT_PERMISSION,
            GuestArTransferDecisionService::REJECT_PERMISSION,GuestArTransferDecisionService::REVERSE_PERMISSION];
        foreach($permissions as $permission)Permission::firstOrCreate(['name'=>$permission,'guard_name'=>'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();$this->glfActor->givePermissionTo($permissions);
        $this->depositService=app(GuestDepositLifecycleService::class);$this->refundService=app(GuestRefundLifecycleService::class);
        $this->paymentService=app(GuestPaymentLifecycleService::class);$this->arRequestService=app(GuestArTransferRequestService::class);
        $this->arDecisionService=app(GuestArTransferDecisionService::class);$this->folioService=app(GuestLedgerFolioAggregateService::class);
    }

    protected function glfCCashierSession(array $overrides=[]):CashierSession
    {
        if ($overrides === []) {
            $existing = CashierSession::where('property_id', $this->glfProperty->id)
                ->where('cashier_user_id', $this->glfActor->id)
                ->where('status', CashierSessionStatusEnum::OPEN->value)
                ->first();
            if ($existing) return $existing->fresh();
        }
        $session=new CashierSession();$session->forceFill(array_merge(['property_id'=>$this->glfProperty->id,'cashier_user_id'=>$this->glfActor->id,
            'status'=>CashierSessionStatusEnum::OPEN->value,'opened_at'=>now(),'opened_by'=>$this->glfActor->id,'closed_at'=>null,'closed_by'=>null],$overrides))->save();return $session->fresh();
    }
    protected function confirmGlfC(string $intent):void{app(SensitiveActionConfirmationService::class)->confirm($this->glfActor,$intent,'password',$this->glfCompany->id,$this->glfProperty->id);}
    protected function glfCDepositAndFolio(string $amount='100.00'):array{$reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$deposit=$this->depositService->recordCashDeposit($this->glfActor,$reservation->id,$this->glfCCashierSession()->id,$amount,'dep-'.$reservation->id.'-'.$amount);return[$deposit,$folio,$reservation];}
    protected function glfCPayment(string $amount='100.00'):array{$reservation=$this->makeGlfReservation();$folio=$this->makeGlfFolio($reservation,$reservation->primaryGuest);$payment=$this->paymentService->recordCashPayment($this->glfActor,$reservation->id,$this->glfCCashierSession()->id,$amount,'pay-'.$reservation->id.'-'.$amount);return[$payment,$folio,$reservation];}
    protected function postGlfCCharge(Folio $folio,string $amount='100.00'):void{$this->folioService->postItem($this->glfActor,$folio->id,['item_type'=>'room_charge','description'=>'GLF-C charge','quantity'=>'1.00','amount'=>$amount]);}
}
