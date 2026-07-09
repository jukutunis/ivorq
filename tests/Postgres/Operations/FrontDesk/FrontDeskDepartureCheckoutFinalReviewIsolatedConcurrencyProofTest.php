<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutFinalReviewIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    protected string $cdb, $stayId, $actorId;

    protected function setUp(): void {
        parent::setUp(); $this->cdb = 'ivorq_concurrency_fd_b7_'.Str::lower(Str::random(8));
        DB::statement("CREATE DATABASE \"{$this->cdb}\""); DB::disconnect();
        config(['database.connections.pgsql_concurrency' => ['driver'=>'pgsql','host'=>config('database.connections.pgsql.host'),'port'=>config('database.connections.pgsql.port'),'database'=>$this->cdb,'username'=>config('database.connections.pgsql.username'),'password'=>config('database.connections.pgsql.password')]]);
        DB::purge('pgsql_concurrency'); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00'));
        $this->artisan('migrate', ['--database'=>'pgsql_concurrency','--force'=>true]); $this->seedB7Data();
    }
    protected function tearDown(): void { Carbon::setTestNow(); DB::disconnect('pgsql_concurrency'); DB::statement("SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = '{$this->cdb}' AND pid <> pg_backend_pid()"); DB::statement("DROP DATABASE IF EXISTS \"{$this->cdb}\""); parent::tearDown(); }

    private function seedB7Data(): void { DB::setDefaultConnection('pgsql_concurrency'); $this->setUpFrontDeskFdA2Fixture(); $s=$this->checkedInStay('7601'); app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-seed-'.Str::ulid()); app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-seed-'.Str::ulid()); app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-seed-'.Str::ulid()); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-seed-'.Str::ulid()); $this->stayId=$s[0]->id; $this->actorId=$this->frontDeskActor->id; DB::setDefaultConnection('pgsql'); }

    public function test_idempotent_concurrency(): void { $k='concurrent-'.Str::ulid(); $this->expose(); $this->simCreate('A',$k,'CHECKOUT_FINAL_REVIEW_READY'); $this->simCreate('B',$k,'CHECKOUT_FINAL_REVIEW_READY'); $this->assertSame(1, FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->withoutGlobalScopes()->where('front_desk_stay_id', $this->stayId)->count()); }
    public function test_distinct_concurrency(): void { $k1='dist-1-'.Str::ulid(); $k2='dist-2-'.Str::ulid(); $this->expose(); $this->simCreate('A',$k1,'CHECKOUT_FINAL_REVIEW_READY'); $this->simCreate('B',$k2,'CHECKOUT_FINAL_REVIEW_BLOCKED'); $this->assertSame(2, FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->withoutGlobalScopes()->where('front_desk_stay_id', $this->stayId)->count()); }

    private function expose(): void { fwrite(STDERR, 'OS PID: '.getmypid().PHP_EOL); $p=DB::connection('pgsql_concurrency')->select('SELECT pg_backend_pid() as pid'); fwrite(STDERR, 'PG Backend PID: '.$p[0]->pid.PHP_EOL); }
    private function simCreate(string $w, string $k, string $s): void { $p=DB::connection('pgsql_concurrency'); $pid=$p->select('SELECT pg_backend_pid() as pid')[0]->pid; fwrite(STDERR, "Worker {$w} PG PID: {$pid}".PHP_EOL); try { DB::setDefaultConnection('pgsql_concurrency'); config(['database.default'=>'pgsql_concurrency']); $a=\Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($this->actorId)->first(); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($a, $this->stayId, $s, null, $k); } catch(DomainException $e){ fwrite(STDERR, "Worker {$w} domain error: {$e->getMessage()}".PHP_EOL); } finally { DB::setDefaultConnection('pgsql'); config(['database.default'=>'pgsql']); } }
}
