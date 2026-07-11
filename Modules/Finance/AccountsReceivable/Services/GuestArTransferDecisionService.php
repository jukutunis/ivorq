<?php

namespace Modules\Finance\AccountsReceivable\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Events\GuestArTransferAccepted;
use Modules\Finance\AccountsReceivable\Events\GuestArTransferRejected;
use Modules\Finance\AccountsReceivable\Events\GuestArTransferReversed;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Services\GuestLedgerArTransferEffectService;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestArTransferDecisionService
{
    public const ACCEPT_PERMISSION='accounting.ar.guest-transfer.accept';
    public const REJECT_PERMISSION='accounting.ar.guest-transfer.reject';
    public const REVERSE_PERMISSION='accounting.ar.guest-transfer.reverse';
    public const ACCEPT_INTENT='guest-ar-transfer-accept';
    public const REJECT_INTENT='guest-ar-transfer-reject';
    public const REVERSE_INTENT='guest-ar-transfer-reverse';
    public function __construct(private readonly CurrentPropertyService $currentProperty,private readonly SensitiveActionConfirmationService $confirmation,private readonly GuestLedgerArTransferEffectService $effects){}

    public function acceptTransfer(User $actor,string $transferRequestId,string $reasonCode,string $idempotencyKey):GuestArTransferDecision{return $this->terminal($actor,$transferRequestId,$reasonCode,$idempotencyKey,GuestArTransferDecisionTypeEnum::Accepted,self::ACCEPT_PERMISSION,self::ACCEPT_INTENT);}
    public function rejectTransfer(User $actor,string $transferRequestId,string $reasonCode,string $idempotencyKey):GuestArTransferDecision{return $this->terminal($actor,$transferRequestId,$reasonCode,$idempotencyKey,GuestArTransferDecisionTypeEnum::Rejected,self::REJECT_PERMISSION,self::REJECT_INTENT);}

    private function terminal(User $actor,string $requestId,string $reason,string $key,GuestArTransferDecisionTypeEnum $type,string $permission,string $intent):GuestArTransferDecision
    {
        $propertyId=$this->propertyId();$actor=$this->actor($actor,$permission,$propertyId);$reason=$this->value($reason,'reason_code',80);$key=$this->value($key,'idempotency_key',96);
        $this->confirmation->requireValidConfirmation($actor,$intent,session('active_company_id'),$propertyId);
        return DB::transaction(function()use($actor,$requestId,$reason,$key,$type,$propertyId){
            $request=GuestArTransferRequest::whereKey($requestId)->where('property_id',$propertyId)->lockForUpdate()->firstOrFail();
            $folio=null;if($type===GuestArTransferDecisionTypeEnum::Accepted){$folio=Folio::withoutGlobalScope('property')->whereKey($request->folio_id)->where('property_id',$propertyId)->lockForUpdate()->firstOrFail();}
            $existing=GuestArTransferDecision::where('property_id',$propertyId)->where('decision_idempotency_key',$key)->lockForUpdate()->first();
            if($existing){if($existing->guest_ar_transfer_request_id!==$request->id||$existing->decision_type!==$type||$existing->reason_code!==$reason||$existing->decided_by!==$actor->id)throw new DomainException('GUEST_AR_TRANSFER_DECISION_IDEMPOTENCY_CONFLICT');return $existing->fresh();}
            if($request->lifecycle_status!==GuestArTransferStatusEnum::Requested)throw new DomainException('AR transfer request already has a terminal decision.');
            if($type===GuestArTransferDecisionTypeEnum::Accepted){if($folio->status!==FolioStatusEnum::Open||$folio->currency!==$request->currency||bccomp(bcadd((string)$folio->balance,'0.00',2),$this->amount($request->amount),2)<0)throw new DomainException('AR transfer acceptance failed current Folio revalidation.');}
            $decision=$this->create($request,$type,null,$reason,$key,$actor);
            if($type===GuestArTransferDecisionTypeEnum::Accepted){$this->effects->applyAcceptedArTransfer($decision,$request,$folio);$request->forceFill(['lifecycle_status'=>GuestArTransferStatusEnum::Accepted,'updated_by'=>$actor->id])->save();DB::afterCommit(fn()=>event(new GuestArTransferAccepted($decision->fresh())));}
            else{$request->forceFill(['lifecycle_status'=>GuestArTransferStatusEnum::Rejected,'updated_by'=>$actor->id])->save();DB::afterCommit(fn()=>event(new GuestArTransferRejected($decision->fresh())));}
            return $decision->fresh();
        });
    }

    public function reverseAcceptedTransfer(User $actor,string $transferRequestId,string $reasonCode,string $idempotencyKey):GuestArTransferDecision
    {
        $propertyId=$this->propertyId();$actor=$this->actor($actor,self::REVERSE_PERMISSION,$propertyId);$reasonCode=$this->value($reasonCode,'reason_code',80);$idempotencyKey=$this->value($idempotencyKey,'idempotency_key',96);
        $this->confirmation->requireValidConfirmation($actor,self::REVERSE_INTENT,session('active_company_id'),$propertyId);
        return DB::transaction(function()use($actor,$transferRequestId,$reasonCode,$idempotencyKey,$propertyId){
            $request=GuestArTransferRequest::whereKey($transferRequestId)->where('property_id',$propertyId)->lockForUpdate()->firstOrFail();
            $folio=Folio::withoutGlobalScope('property')->whereKey($request->folio_id)->where('property_id',$propertyId)->lockForUpdate()->firstOrFail();
            $accepted=GuestArTransferDecision::where('property_id',$propertyId)->where('guest_ar_transfer_request_id',$request->id)->where('decision_type',GuestArTransferDecisionTypeEnum::Accepted->value)->lockForUpdate()->first();
            if(!$accepted||$request->lifecycle_status!==GuestArTransferStatusEnum::Accepted)throw new DomainException('Only an accepted AR transfer may be reversed.');
            $existing=GuestArTransferDecision::where('property_id',$propertyId)->where('decision_idempotency_key',$idempotencyKey)->lockForUpdate()->first();
            if($existing){if($existing->guest_ar_transfer_request_id!==$request->id||$existing->decision_type!==GuestArTransferDecisionTypeEnum::Reversed||$existing->reverses_decision_id!==$accepted->id||$existing->reason_code!==$reasonCode||$existing->decided_by!==$actor->id)throw new DomainException('GUEST_AR_TRANSFER_REVERSAL_IDEMPOTENCY_CONFLICT');return $existing->fresh();}
            if(GuestArTransferDecision::where('property_id',$propertyId)->where('reverses_decision_id',$accepted->id)->where('decision_type','REVERSED')->exists())throw new DomainException('AR transfer acceptance has already been reversed.');
            $decision=$this->create($request,GuestArTransferDecisionTypeEnum::Reversed,$accepted->id,$reasonCode,$idempotencyKey,$actor);
            $this->effects->applyAcceptedArTransferReversal($decision,$request,$folio);$request->forceFill(['lifecycle_status'=>GuestArTransferStatusEnum::Reversed,'updated_by'=>$actor->id])->save();
            DB::afterCommit(fn()=>event(new GuestArTransferReversed($decision->fresh())));return $decision->fresh();
        });
    }
    private function create(GuestArTransferRequest $request,GuestArTransferDecisionTypeEnum $type,?string $reverses,string $reason,string $key,User $actor):GuestArTransferDecision{$d=new GuestArTransferDecision();$d->forceFill(['property_id'=>$request->property_id,'guest_ar_transfer_request_id'=>$request->id,'decision_type'=>$type,'reverses_decision_id'=>$reverses,'reason_code'=>$reason,'decision_idempotency_key'=>$key,'decided_at'=>now(),'decided_by'=>$actor->id,'source_snapshot'=>['transfer_request_id'=>$request->id,'transfer_number'=>$request->transfer_number,'folio_id'=>$request->folio_id,'amount'=>$this->amount($request->amount),'currency'=>$request->currency,'decision_type'=>$type->value,'reason_code'=>$reason,'reverses_decision_id'=>$reverses],'created_at'=>now()])->save();return $d;}
    private function actor(User $actor,string $permission,string $propertyId):User{if(!auth()->check()||auth()->id()!==$actor->id)throw new AuthorizationException('Accounting AR actor must match the authenticated session.');$fresh=User::whereKey($actor->id)->where('is_active',true)->first();if(!$fresh||!$fresh->properties()->where('properties.id',$propertyId)->wherePivot('status','active')->exists())throw new AuthorizationException('Accounting AR action requires active property access.');try{$ok=$fresh->can($permission);}catch(Throwable){$ok=false;}if(!$ok)throw new AuthorizationException('Accounting AR permission is required.');return $fresh;}
    private function propertyId():string{$id=session('active_property_id')??session('current_property_id')??$this->currentProperty->resolveOrFail();$this->currentProperty->setPropertyId($id);return $id;}
    private function value(string $v,string $field,int $max):string{$v=trim($v);if($v===''||mb_strlen($v)>$max)throw ValidationException::withMessages([$field=>['A valid '.$field.' is required.']]);return $v;}
    private function amount(mixed $v):string{return bcadd((string)$v,'0.00',2);}
}
