<?php

use Illuminate\Support\Facades\Route;

use Modules\Foundation\Approval\Http\Controllers\ApprovalWorkflowController;

Route::middleware(['auth:sanctum', \Illuminate\Routing\Middleware\SubstituteBindings::class])->prefix('api/v1/foundation/approvals')->name('foundation.approvals.')->group(function () {
    Route::apiResource('workflows', ApprovalWorkflowController::class);
});
