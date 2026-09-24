<?php

use App\Http\Controllers\Communications\DocumentEmailController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('quotes/{quote}/send-email', [DocumentEmailController::class, 'sendQuote'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('quotes.send-email');

    Route::post('invoices/{invoice}/send-email', [DocumentEmailController::class, 'sendInvoice'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('invoices.send-email');

    Route::post('payments/{payment}/send-email', [DocumentEmailController::class, 'sendPaymentAcknowledgement'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('payments.send-email');

    Route::post('messages/{message}/retry-email', [DocumentEmailController::class, 'retry'])
        ->middleware('permission:'.Permissions::MESSAGES_RETRY)
        ->name('messages.retry-email');
});
