<?php

namespace Modules\Operations\PMS;

use Illuminate\Support\Facades\Event;
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

class PMSServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

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
    }
}
