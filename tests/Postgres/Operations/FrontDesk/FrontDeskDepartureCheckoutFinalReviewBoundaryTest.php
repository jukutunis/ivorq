<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutFinalReviewBoundaryTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB3B4B5B6Ready(array $s): void {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid());
    }

    public function test_no_updated_at(): void { $this->assertNull((new FrontDeskDepartureCheckoutFinalReview())->getUpdatedAtColumn()); }
    public function test_no_financial_fields(): void {
        $forbidden = ['amount','currency','balance','folio_id','payment_id','invoice_id','tax_id','revenue_id','gl_account_id','night_audit_id','settlement_status','paid_status','checkout_status','checked_out_at'];
        foreach ($forbidden as $f) $this->assertNotContains($f, (new FrontDeskDepartureCheckoutFinalReview())->getFillable());
    }
    public function test_stay_remains_in_house(): void { $s=$this->checkedInStay('7301'); $this->seedB3B4B5B6Ready($s); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'Waiting.', 'dcfr-'.Str::ulid()); $s[0]->refresh(); $this->assertSame('IN_HOUSE', $s[0]->status->value); }
    public function test_does_not_mutate_b6(): void { $s=$this->checkedInStay('7302'); $this->seedB3B4B5B6Ready($s); $b6 = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'Original B6.', 'dca-b-'.Str::ulid()); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $b6After = \Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()->whereKey($b6['authorization']->id)->first(); $this->assertSame('CHECKOUT_AUTHORIZATION_READY', $b6After->authorization_status->value); $this->assertSame('Original B6.', $b6After->authorization_note); }
    public function test_no_folio_mutation(): void { $s=$this->checkedInStay('7303'); $before=$this->domainTableCounts(); $this->seedB3B4B5B6Ready($s); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $after=$this->domainTableCounts(); foreach(['folios','folio_items','journal_candidates','journal_candidate_lines','gl_journal_entries','gl_journal_entry_lines','gl_ledger_balances','payment_proposals','payment_proposal_items','payment_executions','cashbook_transactions','controlled_bank_statement_lines'] as $t){$this->assertSame($before[$t]??0,$after[$t]??0,"Table {$t} mutated — forbidden.");} }
    public function test_actor_is_server_resolved(): void { $s=$this->checkedInStay('7304'); $this->seedB3B4B5B6Ready($s); $r=app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $this->assertSame($this->frontDeskActor->id, $r['final_review']->created_by); $this->assertNotNull($r['final_review']->occurred_at); }
}
