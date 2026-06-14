<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\User\Http\Resources\UserResource;

// permission.team sets Spatie team context after Sanctum resolves the user.
Route::middleware(['auth:sanctum', 'permission.team'])->group(function () {
    Route::get('/user', fn(\Illuminate\Http\Request $req) => new UserResource($req->user()))
        ->name('api.user');
        
    Route::get('/notifications', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'getNotifications']);
    Route::get('/notifications/unread-count', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'getUnreadCount']);
    Route::put('/notifications/{id}/read', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'markAllAsRead']);
});
