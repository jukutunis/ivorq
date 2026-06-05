<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Foundation\Activity\Services\ActivityService;
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

class LogPmsActivity
{
    public function __construct(
        private ActivityService $activityService
    ) {}

    public function handle(
        ReservationCreated|ReservationConfirmed|ReservationCancelled|GuestCheckedIn|GuestCheckedOut|RoomBlockCreated|RoomBlockReleased|FolioCreated|FolioItemPosted|FolioItemVoided $event
    ): void {
        match (true) {
            $event instanceof ReservationCreated   => $this->onReservationCreated($event),
            $event instanceof ReservationConfirmed => $this->onReservationConfirmed($event),
            $event instanceof ReservationCancelled => $this->onReservationCancelled($event),
            $event instanceof GuestCheckedIn       => $this->onGuestCheckedIn($event),
            $event instanceof GuestCheckedOut      => $this->onGuestCheckedOut($event),
            $event instanceof RoomBlockCreated     => $this->onRoomBlockCreated($event),
            $event instanceof RoomBlockReleased    => $this->onRoomBlockReleased($event),
            $event instanceof FolioCreated         => $this->onFolioCreated($event),
            $event instanceof FolioItemPosted      => $this->onFolioItemPosted($event),
            $event instanceof FolioItemVoided      => $this->onFolioItemVoided($event),
        };
    }

    private function onReservationCreated(ReservationCreated $event): void
    {
        $res      = $event->reservation;
        $guest    = $res->primaryGuest?->full_name ?? $res->primary_guest_id;

        $this->activityService->log(
            description: "Reservation [{$res->reservation_number}] created for guest {$guest} — {$res->arrival_date?->toDateString()} to {$res->departure_date?->toDateString()}",
            subject: $res,
        );
    }

    private function onReservationConfirmed(ReservationConfirmed $event): void
    {
        $res = $event->reservation;

        $this->activityService->log(
            description: "Reservation [{$res->reservation_number}] confirmed",
            subject: $res,
        );
    }

    private function onReservationCancelled(ReservationCancelled $event): void
    {
        $res    = $event->reservation;
        $reason = $event->reason ? " — {$event->reason}" : '';

        $this->activityService->log(
            description: "Reservation [{$res->reservation_number}] cancelled{$reason}",
            subject: $res,
        );
    }

    private function onGuestCheckedIn(GuestCheckedIn $event): void
    {
        $res   = $event->reservation;
        $stay  = $event->stay;
        $room  = $stay->room?->room_number ?? $stay->room_id;
        $guest = $stay->guest?->full_name ?? $stay->guest_id;

        $this->activityService->log(
            description: "Guest {$guest} checked in to room {$room} on reservation [{$res->reservation_number}]",
            subject: $stay,
        );
    }

    private function onGuestCheckedOut(GuestCheckedOut $event): void
    {
        $res   = $event->reservation;
        $stay  = $event->stay;
        $room  = $stay->room?->room_number ?? $stay->room_id;
        $guest = $stay->guest?->full_name ?? $stay->guest_id;

        $this->activityService->log(
            description: "Guest {$guest} checked out of room {$room} on reservation [{$res->reservation_number}]",
            subject: $stay,
        );
    }

    private function onRoomBlockCreated(RoomBlockCreated $event): void
    {
        $block  = $event->roomBlock;
        $room   = $block->room?->room_number ?? $block->room_id;
        $type   = $block->block_type?->label() ?? $block->block_type;
        $start  = $block->start_at?->toDateTimeString();
        $end    = $block->end_at ? $block->end_at->toDateTimeString() : 'indefinite';

        $this->activityService->log(
            description: "Room {$room} blocked [{$type}] from {$start} to {$end}",
            subject: $block,
        );
    }

    private function onRoomBlockReleased(RoomBlockReleased $event): void
    {
        $block = $event->roomBlock;
        $room  = $block->room?->room_number ?? $block->room_id;
        $by    = $block->releasedBy?->name ?? $block->released_by ?? 'system';

        $this->activityService->log(
            description: "Room {$room} block released by {$by}",
            subject: $block,
        );
    }

    private function onFolioCreated(FolioCreated $event): void
    {
        $folio = $event->folio;
        $guest = $folio->guest?->full_name ?? $folio->guest_id;

        $this->activityService->log(
            description: "Folio [{$folio->folio_number}] created for guest {$guest}",
            subject: $folio,
        );
    }

    private function onFolioItemPosted(FolioItemPosted $event): void
    {
        $item  = $event->folioItem;
        $by    = $item->postedBy?->name ?? $item->posted_by ?? 'system';
        $type  = $item->item_type?->label() ?? $item->item_type;

        $this->activityService->log(
            description: "Folio item [{$type}] posted — {$item->description} ({$item->amount}) by {$by}",
            subject: $item,
        );
    }

    private function onFolioItemVoided(FolioItemVoided $event): void
    {
        $item = $event->folioItem;
        $type = $item->item_type?->label() ?? $item->item_type;

        $this->activityService->log(
            description: "Folio item [{$type}] voided — {$item->description} ({$item->amount})",
            subject: $item,
        );
    }
}
