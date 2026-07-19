<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationSourceIntegrityTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;

    private GuestLedgerPostingCompletenessParticipationPort $pcPort;
    private GuestLedgerSettlementHoldParticipationPort $shPort;
    private GuestLedgerCompletedSettlementConflictParticipationPort $csPort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);

        // Build clear ports directly — bypass container binding issues
        $this->pcPort = new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_pc','source_identifiers'=>['pc']]; }
        };
        $this->shPort = new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_sh','source_identifiers'=>['sh']]; }
        };
        $this->csPort = new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_cs','source_identifiers'=>['cs']]; }
        };

        $this->service = new GuestLedgerCheckoutTerminalFinancialAttestationService(
            app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class),
            $this->lockService,
            $this->pcPort,
            $this->shPort,
            $this->csPort,
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    private function openBD(): PropertyBusinessDate { $bd = new PropertyBusinessDate(); $bd->forceFill(['property_id'=>$this->glfProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfActor->id,'opened_at'=>now()])->save(); return $bd->fresh(); }
    private function ctx(): PropertyBusinessDateOperationalLockContext { $bd=$this->openBD(); return $this->lockService->acquire($this->glfCompany->id,$this->glfProperty->id,['property_business_date_id'=>$bd->id,'property_id'=>$this->glfProperty->id,'business_date'=>$bd->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfActor->id,'opened_at'=>$bd->opened_at->utc()->toISOString()]); }
    private function stay(string $rid, string $gid): FrontDeskStay { $s=new FrontDeskStay(); $s->forceFill(['property_id'=>$this->glfProperty->id,'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id])->save(); return $s->fresh(); }
    private function folio(string $rid, string $gid, array $o=[]): Folio { static $n=0; $n++; $f=new Folio(); $f->forceFill(array_merge(['property_id'=>$this->glfProperty->id,'folio_number'=>'S'.$n.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>'open','currency'=>'USD','window_number'=>$n,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'si-'.bin2hex(random_bytes(4))],$o))->save(); return $f->fresh(); }
    private function charge(Folio $f, string $a): void { $i=new FolioItem(); $i->forceFill(['property_id'=>$this->glfProperty->id,'folio_id'=>$f->id,'item_type'=>FolioItemTypeEnum::RoomCharge,'description'=>'C','quantity'=>'1.00','amount'=>$a,'is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id])->save(); }
    private function cs(): CashierSession { $c=new CashierSession(); $c->forceFill(['property_id'=>$this->glfProperty->id,'cashier_user_id'=>$this->glfActor->id,'status'=>CashierSessionStatusEnum::OPEN->value,'opened_at'=>now(),'opened_by'=>$this->glfActor->id])->save(); return $c->fresh(); }
    private function pay(string $rid, string $gid, string $a, string $lc='FULLY_ALLOCATED', ?Folio $f=null, ?string $csid=null): GuestPaymentTransaction { static $pn=0; $pn++; $csid=$csid??$this->cs()->id; $p=new GuestPaymentTransaction(); $p->forceFill(['property_id'=>$this->glfProperty->id,'payment_number'=>'GPM-'.$pn.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'currency'=>'USD','amount'=>$a,'cashier_session_id'=>$csid,'tender_type'=>'CASH','lifecycle_status'=>$lc,'recording_idempotency_key'=>'si-p-'.bin2hex(random_bytes(4)),'recorded_at'=>now(),'recorded_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([])])->save();
        if($f&&$lc!=='VOIDED'){$al=new GuestPaymentAllocation(); $al->forceFill(['property_id'=>$this->glfProperty->id,'guest_payment_transaction_id'=>$p->id,'folio_id'=>$f->id,'amount'=>$a,'allocation_idempotency_key'=>'si-a-'.bin2hex(random_bytes(4)),'allocated_at'=>now(),'allocated_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([]),'created_at'=>now()])->save();
        $fi=new FolioItem(); $fi->forceFill(['property_id'=>$this->glfProperty->id,'folio_id'=>$f->id,'item_type'=>FolioItemTypeEnum::Payment,'description'=>'P','quantity'=>'1.00','amount'=>bcmul($a,'-1',2),'is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'source_domain'=>'pms_cashiering','source_type'=>'guest_payment_allocation','source_id'=>$al->id,'guest_payment_allocation_id'=>$al->id])->save();}
        return $p->fresh(); }

    private function deposit(string $rid, string $gid, string $a, string $lc='RECORDED', ?string $csid=null): GuestDepositTransaction { static $dn=0; $dn++; $csid=$csid??$this->cs()->id; $d=new GuestDepositTransaction(); $d->forceFill(['property_id'=>$this->glfProperty->id,'deposit_number'=>'DEP-'.$dn.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'currency'=>'USD','amount'=>$a,'cashier_session_id'=>$csid,'tender_type'=>'CASH','lifecycle_status'=>$lc,'recording_idempotency_key'=>'si-d-'.bin2hex(random_bytes(4)),'recorded_at'=>now(),'recorded_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([])])->save(); return $d->fresh(); }

    private function depApply(GuestDepositTransaction $d, Folio $f, string $a): GuestDepositApplication { $ap=new GuestDepositApplication(); $ap->forceFill(['property_id'=>$this->glfProperty->id,'guest_deposit_transaction_id'=>$d->id,'folio_id'=>$f->id,'amount'=>$a,'application_idempotency_key'=>'si-da-'.bin2hex(random_bytes(4)),'applied_at'=>now(),'applied_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([]),'created_at'=>now()])->save();
        $fi=new FolioItem(); $fi->forceFill(['property_id'=>$this->glfProperty->id,'folio_id'=>$f->id,'item_type'=>FolioItemTypeEnum::Deposit,'description'=>'D','quantity'=>'1.00','amount'=>bcmul($a,'-1',2),'is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'guest_deposit_application_id'=>$ap->id])->save();
        return $ap->fresh(); }

    // ═══════════════════════════════════════════════════════════════════════
    // Folio tests
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_folio_evidence_unavailable(): void
    {
        DB::transaction(function () { $a=$this->service->attest($this->ctx(),$this->stay($this->makeGlfReservation()->id,$this->makeGlfReservation()->primaryGuest->id)->id); $this->assertEquals(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialEvidenceUnavailable,$a->status); });
    }

    public function test_zero_balance_ready(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $this->folio($r->id,$g->id); $this->assertEquals(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady,$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->status); });
    }

    public function test_non_zero_balance_blocked(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'150.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'150.00','balance'=>'150.00']); $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->blocker_codes); });
    }

    public function test_two_folios_aggregate_zero_individual_nonzero(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f1=$this->folio($r->id,$g->id); $f2=$this->folio($r->id,$g->id,['window_number'=>2]); $this->charge($f1,'100.00'); $this->charge($f2,'-100.00'); DB::table('folios')->where('id',$f1->id)->update(['total_charges'=>'100.00','balance'=>'100.00']); DB::table('folios')->where('id',$f2->id)->update(['total_charges'=>'-100.00','balance'=>'-100.00']); $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->blocker_codes); });
    }

    public function test_closed_folio_review_required(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $this->folio($r->id,$g->id,['status'=>'closed']); $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    public function test_void_folio_review_required(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $this->folio($r->id,$g->id,['status'=>'void']); $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    public function test_folio_guest_mismatch(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $g2=$this->makeGlfGuest(); $f=$this->folio($r->id,$g2->id); $this->assertContains('FOLIO_RELATIONSHIP_CONFLICT',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    public function test_multiple_currencies(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $this->folio($r->id,$g->id); $this->folio($r->id,$g->id,['window_number'=>2,'currency'=>'EUR']); $this->assertContains('FOLIO_CURRENCY_CONFLICT',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    public function test_cached_totals_mismatch_review_required(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'50.00'); $this->assertContains('FOLIO_CACHED_TOTALS_MISMATCH',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Payment tests
    // ═══════════════════════════════════════════════════════════════════════
    public function test_unresolved_payment_blocked(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'100.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'100.00','balance'=>'100.00']); $this->pay($r->id,$g->id,'100.00','RECORDED'); $this->assertContains('GUEST_PAYMENT_UNRESOLVED',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->blocker_codes); });
    }

    public function test_over_allocated_payment_review(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'100.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'100.00','balance'=>'100.00']); $this->pay($r->id,$g->id,'100.00','FULLY_ALLOCATED',$f);
            // Create a second allocation to over-allocate
            $csid=$this->cs()->id; $p2=new GuestPaymentTransaction(); $p2->forceFill(['property_id'=>$this->glfProperty->id,'payment_number'=>'OP-'.bin2hex(random_bytes(2)),'reservation_id'=>$r->id,'guest_id'=>$g->id,'currency'=>'USD','amount'=>'50.00','cashier_session_id'=>$csid,'tender_type'=>'CASH','lifecycle_status'=>'FULLY_ALLOCATED','recording_idempotency_key'=>'si-op-'.bin2hex(random_bytes(4)),'recorded_at'=>now(),'recorded_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([])])->save();
            $al=new GuestPaymentAllocation(); $al->forceFill(['property_id'=>$this->glfProperty->id,'guest_payment_transaction_id'=>$p2->id,'folio_id'=>$f->id,'amount'=>'50.00','allocation_idempotency_key'=>'si-oa-'.bin2hex(random_bytes(4)),'allocated_at'=>now(),'allocated_by'=>$this->glfActor->id,'source_snapshot'=>json_encode([]),'created_at'=>now()])->save();
            $fi=new FolioItem(); $fi->forceFill(['property_id'=>$this->glfProperty->id,'folio_id'=>$f->id,'item_type'=>FolioItemTypeEnum::Payment,'description'=>'OP','quantity'=>'1.00','amount'=>'-50.00','is_void'=>false,'posted_at'=>now(),'posted_by'=>$this->glfActor->id,'created_by'=>$this->glfActor->id,'guest_payment_allocation_id'=>$al->id])->save();
            $this->assertContains('PAYMENT_SOURCE_CONFLICT',$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id)->review_reasons); });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Port status matrix
    // ═══════════════════════════════════════════════════════════════════════
    public function test_posting_blocked(): void { $this->portTest(GuestLedgerPostingCompletenessParticipationPort::class,'AVAILABLE_BLOCKED','MANDATORY_POSTINGS_INCOMPLETE','blocker_codes'); }
    public function test_posting_review(): void { $this->portTest(GuestLedgerPostingCompletenessParticipationPort::class,'REVIEW_REQUIRED','POSTING_COMPLETENESS_REVIEW_REQUIRED','review_reasons'); }
    public function test_posting_unavailable(): void { $this->portTest(GuestLedgerPostingCompletenessParticipationPort::class,'EVIDENCE_UNAVAILABLE','POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }
    public function test_settlement_hold_blocked(): void { $this->portTest(GuestLedgerSettlementHoldParticipationPort::class,'AVAILABLE_BLOCKED','SETTLEMENT_HOLD_ACTIVE','blocker_codes'); }
    public function test_settlement_hold_review(): void { $this->portTest(GuestLedgerSettlementHoldParticipationPort::class,'REVIEW_REQUIRED','SETTLEMENT_HOLD_REVIEW_REQUIRED','review_reasons'); }
    public function test_settlement_hold_unavailable(): void { $this->portTest(GuestLedgerSettlementHoldParticipationPort::class,'EVIDENCE_UNAVAILABLE','SETTLEMENT_HOLD_EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }
    public function test_conflict_blocked(): void { $this->portTest(GuestLedgerCompletedSettlementConflictParticipationPort::class,'AVAILABLE_BLOCKED','CONFLICTING_COMPLETED_SETTLEMENT','blocker_codes'); }
    public function test_conflict_review(): void { $this->portTest(GuestLedgerCompletedSettlementConflictParticipationPort::class,'REVIEW_REQUIRED','COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED','review_reasons'); }
    public function test_conflict_unavailable(): void { $this->portTest(GuestLedgerCompletedSettlementConflictParticipationPort::class,'EVIDENCE_UNAVAILABLE','COMPLETED_SETTLEMENT_CONFLICT_EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }

    private function portTest(string $portClass, string $status, string $expectedCode, string $field): void
    {
        $pcP = ($portClass === GuestLedgerPostingCompletenessParticipationPort::class)
            ? new class($status) implements GuestLedgerPostingCompletenessParticipationPort {
                public function __construct(private string $s) {}
                public function participate(string $r, string $p): array { return ['status'=>$this->s,'code'=>'PT-'.$this->s,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
              } : $this->pcPort;
        $shP = ($portClass === GuestLedgerSettlementHoldParticipationPort::class)
            ? new class($status) implements GuestLedgerSettlementHoldParticipationPort {
                public function __construct(private string $s) {}
                public function participate(string $r, string $p): array { return ['status'=>$this->s,'code'=>'PT-'.$this->s,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
              } : $this->shPort;
        $csP = ($portClass === GuestLedgerCompletedSettlementConflictParticipationPort::class)
            ? new class($status) implements GuestLedgerCompletedSettlementConflictParticipationPort {
                public function __construct(private string $s) {}
                public function participate(string $r, string $p): array { return ['status'=>$this->s,'code'=>'PT-'.$this->s,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
              } : $this->csPort;

        $svc = new GuestLedgerCheckoutTerminalFinancialAttestationService(
            app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class),
            $this->lockService, $pcP, $shP, $csP,
        );

        DB::transaction(function () use ($svc, $expectedCode, $field) {
            $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $this->folio($r->id,$g->id);
            $a=$svc->attest($this->ctx(),$this->stay($r->id,$g->id)->id);
            $this->assertContains($expectedCode, $a->$field);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cash-linked reference tests
    // ═══════════════════════════════════════════════════════════════════════
    public function test_cash_payment_creates_reference(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'50.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'50.00','balance'=>'50.00']); $cs=$this->cs(); $this->pay($r->id,$g->id,'50.00','FULLY_ALLOCATED',$f,$cs->id); $a=$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id); $this->assertNotEmpty($a->cash_linked_references); $this->assertContains('GUEST_PAYMENT_TRANSACTION',array_column($a->cash_linked_references,'source_type')); $this->assertContains($cs->id,$a->cashier_session_ids); });
    }

    public function test_cash_deposit_creates_reference(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $cs=$this->cs(); $this->deposit($r->id,$g->id,'100.00','RECORDED',$cs->id); $this->depApply($this->deposit($r->id,$g->id,'100.00','RECORDED',$cs->id),$f,'100.00'); $a=$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id); $this->assertContains('GUEST_DEPOSIT_TRANSACTION',array_column($a->cash_linked_references,'source_type')); });
    }

    public function test_cash_references_deduplicated(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'100.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'100.00','balance'=>'100.00']); $cs=$this->cs(); $this->pay($r->id,$g->id,'50.00','FULLY_ALLOCATED',$f,$cs->id); $this->pay($r->id,$g->id,'50.00','FULLY_ALLOCATED',$f,$cs->id); $a=$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id); $this->assertEquals(array_values(array_unique($a->cashier_session_ids)),$a->cashier_session_ids); });
    }

    public function test_cash_references_exclude_amounts(): void
    {
        DB::transaction(function () { $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $f=$this->folio($r->id,$g->id); $this->charge($f,'50.00'); DB::table('folios')->where('id',$f->id)->update(['total_charges'=>'50.00','balance'=>'50.00']); $this->pay($r->id,$g->id,'50.00','FULLY_ALLOCATED',$f); $a=$this->service->attest($this->ctx(),$this->stay($r->id,$g->id)->id); foreach($a->cash_linked_references as $r) { $this->assertArrayNotHasKey('amount',$r); $this->assertArrayNotHasKey('guest_id',$r); } });
    }

    public function test_missing_cash_linkage_defensive_coverage(): void
    {
        $evaluator=app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class);
        $ref=new \ReflectionMethod($evaluator,'buildCashLinkedReferences'); $ref->setAccessible(true);
        $r=$ref->invoke($evaluator,'p','r','g',[['id'=>'x','tender_type'=>'CASH','cashier_session_id'=>'']],[],[]);
        $this->assertTrue($r['missing_linkage']);
        $this->assertEquals('PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE',$evaluator->determineStatusValue(['CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE'],[],[]));
    }
}
