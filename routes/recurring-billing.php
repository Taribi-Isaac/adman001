<?php

use App\Http\Controllers\RecurringBilling\RecurringBillingScheduleController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('recurring-billing', [RecurringBillingScheduleController::class, 'index'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_VIEW)
        ->name('recurring-billing.index');

    Route::get('recurring-billing/create', [RecurringBillingScheduleController::class, 'create'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_CREATE)
        ->name('recurring-billing.create');

    Route::post('recurring-billing', [RecurringBillingScheduleController::class, 'store'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_CREATE)
        ->name('recurring-billing.store');

    Route::get('recurring-billing/{schedule}', [RecurringBillingScheduleController::class, 'show'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_VIEW)
        ->name('recurring-billing.show');

    Route::get('recurring-billing/{schedule}/edit', [RecurringBillingScheduleController::class, 'edit'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_UPDATE)
        ->name('recurring-billing.edit');

    Route::put('recurring-billing/{schedule}', [RecurringBillingScheduleController::class, 'update'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_UPDATE)
        ->name('recurring-billing.update');

    Route::post('recurring-billing/{schedule}/pause', [RecurringBillingScheduleController::class, 'pause'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_PAUSE)
        ->name('recurring-billing.pause');

    Route::post('recurring-billing/{schedule}/resume', [RecurringBillingScheduleController::class, 'resume'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_RESUME)
        ->name('recurring-billing.resume');

    Route::post('recurring-billing/{schedule}/cancel', [RecurringBillingScheduleController::class, 'cancel'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_CANCEL)
        ->name('recurring-billing.cancel');

    Route::post('recurring-billing/{schedule}/generate', [RecurringBillingScheduleController::class, 'generate'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_GENERATE)
        ->name('recurring-billing.generate');

    Route::post('recurring-billing/generations/{generation}/retry', [RecurringBillingScheduleController::class, 'retryGeneration'])
        ->middleware('permission:'.Permissions::RECURRING_BILLING_GENERATE)
        ->name('recurring-billing.retry-generation');
});
