<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Foundation\Notification\Http\Controllers\NotificationController;
use Modules\Foundation\User\Http\Resources\UserResource;

// permission.team sets Spatie team context after Sanctum resolves the user.
Route::middleware(['auth:sanctum', 'auth.security', 'permission.team'])->group(function () {
    Route::get('/user', fn (Request $req) => new UserResource($req->user()))
        ->name('api.user');

    Route::get('/notifications', [NotificationController::class, 'getNotifications']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
});
