<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\PMS\Http\Controllers\FolioController;
use Modules\Operations\PMS\Http\Controllers\FrontDeskController;
use Modules\Operations\PMS\Http\Controllers\GuestController;
use Modules\Operations\PMS\Http\Controllers\PmsDashboardController;
use Modules\Operations\PMS\Http\Controllers\RatePlanController;
use Modules\Operations\PMS\Http\Controllers\ReservationController;
use Modules\Operations\PMS\Http\Controllers\RoomBlockController;

Route::middleware(['web', 'auth'])
    ->prefix('operations/pms')
    ->name('operations.pms.')
    ->group(function () {

        // ── Dashboard ─────────────────────────────────────────────────────────
        Route::get('/', [PmsDashboardController::class, 'index'])
            ->name('dashboard');

        // ── Guests ────────────────────────────────────────────────────────────
        Route::resource('guests', GuestController::class);

        // ── Reservations ──────────────────────────────────────────────────────
        Route::resource('reservations', ReservationController::class);

        Route::post('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])
            ->name('reservations.confirm');

        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
            ->name('reservations.cancel');

        Route::post('reservations/{reservation}/no-show', [ReservationController::class, 'noShow'])
            ->name('reservations.no-show');

        Route::post('reservations/{reservation}/assign-room', [ReservationController::class, 'assignRoom'])
            ->name('reservations.assign-room');

        // ── Front Desk ────────────────────────────────────────────────────────
        Route::post('reservations/{reservation}/check-in', [FrontDeskController::class, 'checkIn'])
            ->name('reservations.check-in');

        Route::post('stays/{stay}/check-out', [FrontDeskController::class, 'checkOut'])
            ->name('stays.check-out');

        // ── Room Blocks ───────────────────────────────────────────────────────
        Route::resource('room-blocks', RoomBlockController::class)
            ->parameters(['room-blocks' => 'room_block']);

        Route::post('room-blocks/{room_block}/release', [RoomBlockController::class, 'release'])
            ->name('room-blocks.release');

        // ── Folios ────────────────────────────────────────────────────────────
        Route::get('folios', [FolioController::class, 'index'])
            ->name('folios.index');

        Route::get('folios/{folio}', [FolioController::class, 'show'])
            ->name('folios.show');

        Route::post('reservations/{reservation}/folios', [FolioController::class, 'store'])
            ->name('reservations.folios.store');

        Route::post('folios/{folio}/items', [FolioController::class, 'postItem'])
            ->name('folios.items.store');

        Route::post('folio-items/{folio_item}/void', [FolioController::class, 'voidItem'])
            ->name('folio-items.void');

        Route::post('folios/{folio}/close', [FolioController::class, 'close'])
            ->name('folios.close');

        Route::post('folios/{folio}/void', [FolioController::class, 'void'])
            ->name('folios.void');

        // ── Rate Plans ────────────────────────────────────────────────────────
        Route::resource('rate-plans', RatePlanController::class)
            ->parameters(['rate-plans' => 'rate_plan']);
    });
