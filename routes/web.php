<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn() => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

use App\Http\Controllers\Ivorq\WorkspaceController;

Route::get('/frontdesk', [WorkspaceController::class, 'frontdesk']);
Route::get('/housekeeping', [WorkspaceController::class, 'housekeeping']);
Route::get('/engineering', [WorkspaceController::class, 'engineering']);
Route::get('/finance', [WorkspaceController::class, 'finance']);
Route::get('/hris', [WorkspaceController::class, 'hris']);
