<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Events\GuestArTransferRequested;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestArTransferRequestService
{
    public const REQUEST_PERMISSION = 'pms.guest-ledger.ar-transfer.request';
    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    public function requestTransfer(User $actor, string $folioId, string $amount, string $reasonCode, string $idempotencyKey): GuestArTransferRequest
    {
        $propertyId=$this->propertyId(); $actor=$this->actor($actor,$propertyId); $amount=$this->positiveAmount($amount);
        $reasonCode=$this->value($reasonCode,'reason_code',80); $idempotencyKey=$this->value($idempotencyKey,'idempotency_key',96);
        return DB::transaction(function() use($actor,$folioId,$amount,$reasonCode,$idempotencyKey,$propertyId){
            $folio=Folio::withoutGlobalScope('property')->whereKey($folioId)->where('property_id',$propertyId)->lockForUpdate()->firstOrFail();
            if($folio->status!==FolioStatusEnum::Open)throw new DomainException('AR transfer requires an OPEN Folio.');
            $balance=bcadd((string)$folio->balance,'0.00',2);
            if(bccomp($balance,'0.00',2)<=0||bccomp($amount,$balance,2)>0)throw new DomainException('AR transfer amount exceeds the current positive Folio balance.');
            $existing=GuestArTransferRequest::where('property_id',$propertyId)->where('request_idempotency_key',$idempotencyKey)->lockForUpdate()->first();
            if($existing){if($existing->folio_id!==$folio->id||$this->amount($existing->amount)!==$amount||$existing->request_reason_code!==$reasonCode||$existing->requested_by!==$actor->id)throw new DomainException('GUEST_AR_TRANSFER_REQUEST_IDEMPOTENCY_CONFLICT');return $existing->fresh();}
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))',['glf-c-ar-transfer-number-'.$propertyId]);
            $request=new GuestArTransferRequest();
            $request->forceFill(['property_id'=>$propertyId,'transfer_number'=>sprintf('GAR-%05d',GuestArTransferRequest::where('property_id',$propertyId)->count()+1),
                'folio_id'=>$folio->id,'reservation_id'=>$folio->reservation_id,'guest_id'=>$folio->guest_id,'currency'=>$folio->currency,'amount'=>$amount,
                'lifecycle_status'=>GuestArTransferStatusEnum::Requested,'request_reason_code'=>$reasonCode,'request_idempotency_key'=>$idempotencyKey,
                'requested_at'=>now(),'requested_by'=>$actor->id,'source_snapshot'=>['folio_id'=>$folio->id,'folio_number'=>$folio->folio_number,
                    'reservation_id'=>$folio->reservation_id,'guest_id'=>$folio->guest_id,'currency'=>$folio->currency,'amount'=>$amount,'folio_balance'=>$balance,'reason_code'=>$reasonCode],
                'created_by'=>$actor->id,'updated_by'=>$actor->id])->save();
            DB::afterCommit(fn()=>event(new GuestArTransferRequested($request->fresh()))); return $request->fresh();
        });
    }
    private function actor(User $actor,string $propertyId):User{if(!auth()->check()||auth()->id()!==$actor->id)throw new AuthorizationException('AR transfer actor must match the authenticated session.');$fresh=User::whereKey($actor->id)->where('is_active',true)->first();if(!$fresh||!$fresh->properties()->where('properties.id',$propertyId)->wherePivot('status','active')->exists())throw new AuthorizationException('AR transfer requires active property access.');try{$ok=$fresh->can(self::REQUEST_PERMISSION);}catch(Throwable){$ok=false;}if(!$ok)throw new AuthorizationException('AR transfer request permission is required.');return $fresh;}
    private function propertyId():string{$id=session('active_property_id')??session('current_property_id')??$this->currentProperty->resolveOrFail();$this->currentProperty->setPropertyId($id);return $id;}
    private function value(string $v,string $field,int $max):string{$v=trim($v);if($v===''||mb_strlen($v)>$max)throw ValidationException::withMessages([$field=>['A valid '.$field.' is required.']]);return $v;}
    private function positiveAmount(string $v):string{if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/',$v))throw ValidationException::withMessages(['amount'=>['Amount must be a plain positive decimal.']]);[$i,$f]=array_pad(explode('.',$v,2),2,'');if(strlen($i)>10||(strlen($f)>2&&rtrim(substr($f,2),'0')!==''))throw ValidationException::withMessages(['amount'=>['Amount exceeds decimal(12,2).']]);$n=bcadd($i.'.'.str_pad(substr($f,0,2),2,'0'),'0.00',2);if(bccomp($n,'0.00',2)<=0)throw ValidationException::withMessages(['amount'=>['Amount must be positive.']]);return $n;}
    private function amount(mixed $v):string{return bcadd((string)$v,'0.00',2);}
}
