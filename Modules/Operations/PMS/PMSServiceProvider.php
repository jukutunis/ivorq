<?php

namespace Modules\Operations\PMS;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\PMS\Events\FolioCreated;
use Modules\Operations\PMS\Events\FolioItemPosted;
use Modules\Operations\PMS\Events\FolioItemVoided;
use Modules\Operations\PMS\Events\GuestCheckedIn;
use Modules\Operations\PMS\Events\GuestCheckedOut;
use Modules\Operations\PMS\Events\ReservationCancelled;
use Modules\Operations\PMS\Events\ReservationConfirmed;
use Modules\Operations\PMS\Events\ReservationCreated;
use Modules\Operations\PMS\Events\RoomBlockCreated;
use Modules\Operations\PMS\Events\RoomBlockReleased;
use Modules\Operations\PMS\Listeners\CreateFolioIfMissing;
use Modules\Operations\PMS\Listeners\LogPmsActivity;
use Modules\Operations\PMS\Listeners\UpdateRoomStatusToOccupied;
use Modules\Operations\PMS\Listeners\UpdateRoomStatusToDirty;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Policies\FolioPolicy;
use Modules\Operations\PMS\Policies\GuestPolicy;
use Modules\Operations\PMS\Policies\RatePlanPolicy;
use Modules\Operations\PMS\Policies\ReservationPolicy;
use Modules\Operations\PMS\Policies\RoomBlockPolicy;
use Modules\Operations\PMS\Policies\StayPolicy;
use Modules\Operations\PMS\Services\Adapters\UnavailableCompletedSettlementConflictAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailableCompletedSettlementConflictParticipationAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailablePostingCompletenessAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailablePostingCompletenessParticipationAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailableSettlementHoldAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailableSettlementHoldParticipationAdapter;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;

class PMSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // GLF-D production bindings — fail-closed unavailable adapters.
        // Tests may override these with CLEAR/BLOCKED/REVIEW_REQUIRED stubs.
        $this->app->singleton(
            GuestLedgerPostingCompletenessReadPort::class,
            UnavailablePostingCompletenessAdapter::class,
        );
        $this->app->singleton(
            GuestLedgerSettlementHoldReadPort::class,
            UnavailableSettlementHoldAdapter::class,
        );
        $this->app->singleton(
            GuestLedgerCompletedSettlementConflictReadPort::class,
            UnavailableCompletedSettlementConflictAdapter::class,
        );

        // GLF-E production bindings — fail-closed unavailable participation adapters.
        // Tests may override these with CLEAR/BLOCKED/REVIEW_REQUIRED/EVIDENCE_UNAVAILABLE stubs.
        $this->app->singleton(
            GuestLedgerPostingCompletenessParticipationPort::class,
            UnavailablePostingCompletenessParticipationAdapter::class,
        );
        $this->app->singleton(
            GuestLedgerSettlementHoldParticipationPort::class,
            UnavailableSettlementHoldParticipationAdapter::class,
        );
        $this->app->singleton(
            GuestLedgerCompletedSettlementConflictParticipationPort::class,
            UnavailableCompletedSettlementConflictParticipationAdapter::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // ── Policies ──────────────────────────────────────────────────────────
        Gate::policy(Guest::class,       GuestPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(RoomBlock::class,   RoomBlockPolicy::class);
        Gate::policy(Stay::class,        StayPolicy::class);
        Gate::policy(Folio::class,       FolioPolicy::class);
        Gate::policy(RatePlan::class,    RatePlanPolicy::class);

        // ── Reservation events ────────────────────────────────────────────────
        Event::listen(ReservationCreated::class,   [LogPmsActivity::class, 'handle']);
        Event::listen(ReservationConfirmed::class,  [LogPmsActivity::class, 'handle']);
        Event::listen(ReservationCancelled::class,  [LogPmsActivity::class, 'handle']);

        // ── Check-in events ───────────────────────────────────────────────────
        Event::listen(GuestCheckedIn::class, [UpdateRoomStatusToOccupied::class, 'handle']);
        Event::listen(GuestCheckedIn::class, [CreateFolioIfMissing::class,       'handle']);
        Event::listen(GuestCheckedIn::class, [LogPmsActivity::class,             'handle']);

        // ── Check-out events ──────────────────────────────────────────────────
        Event::listen(GuestCheckedOut::class, [UpdateRoomStatusToDirty::class, 'handle']);
        Event::listen(GuestCheckedOut::class, [LogPmsActivity::class,          'handle']);

        // ── Room block events ─────────────────────────────────────────────────
        Event::listen(RoomBlockCreated::class,  [LogPmsActivity::class, 'handle']);
        Event::listen(RoomBlockReleased::class, [LogPmsActivity::class, 'handle']);

        // ── Folio events ──────────────────────────────────────────────────────
        Event::listen(FolioCreated::class,     [LogPmsActivity::class, 'handle']);
        Event::listen(FolioItemPosted::class,  [LogPmsActivity::class, 'handle']);
        Event::listen(FolioItemVoided::class,  [LogPmsActivity::class, 'handle']);

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }
}
