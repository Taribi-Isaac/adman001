<?php

use App\Http\Controllers\Payments\PaymentClaimController;
use App\Http\Controllers\Payments\PaymentController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('permission:'.Permissions::PAYMENTS_VIEW)
        ->name('payments.index');

    Route::get('payments/create', [PaymentController::class, 'create'])
        ->middleware('permission:'.Permissions::PAYMENTS_RECORD)
        ->name('payments.create');

    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('permission:'.Permissions::PAYMENTS_RECORD)
        ->name('payments.store');

    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('permission:'.Permissions::PAYMENTS_VIEW)
        ->name('payments.show');

    Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])
        ->middleware('permission:'.Permissions::PAYMENTS_CONFIRM)
        ->name('payments.confirm');

    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])
        ->middleware('permission:'.Permissions::PAYMENTS_REJECT)
        ->name('payments.reject');

    Route::post('payments/{payment}/generate-acknowledgement', [PaymentController::class, 'generateAcknowledgement'])
        ->middleware('permission:'.Permissions::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE)
        ->name('payments.generate-acknowledgement');

    Route::get('payment-claims', [PaymentClaimController::class, 'index'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_VIEW)
        ->name('payment-claims.index');

    Route::get('payment-claims/create', [PaymentClaimController::class, 'create'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_CREATE)
        ->name('payment-claims.create');

    Route::post('payment-claims', [PaymentClaimController::class, 'store'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_CREATE)
        ->name('payment-claims.store');

    Route::get('payment-claims/{paymentClaim}', [PaymentClaimController::class, 'show'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_VIEW)
        ->name('payment-claims.show');

    Route::post('payment-claims/{paymentClaim}/confirm', [PaymentClaimController::class, 'confirm'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_REVIEW)
        ->name('payment-claims.confirm');

    Route::post('payment-claims/{paymentClaim}/reject', [PaymentClaimController::class, 'reject'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_REVIEW)
        ->name('payment-claims.reject');

    Route::post('payment-claims/{paymentClaim}/request-information', [PaymentClaimController::class, 'requestInformation'])
        ->middleware('permission:'.Permissions::PAYMENTS_CLAIMS_REVIEW)
        ->name('payment-claims.request-information');
});
