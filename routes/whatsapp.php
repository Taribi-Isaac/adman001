<?php

use App\Http\Controllers\Communications\DocumentWhatsAppController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('webhooks.whatsapp.verify');

Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->name('webhooks.whatsapp.handle');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('quotes/{quote}/send-whatsapp', [DocumentWhatsAppController::class, 'sendQuote'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('quotes.send-whatsapp');

    Route::post('invoices/{invoice}/send-whatsapp', [DocumentWhatsAppController::class, 'sendInvoice'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('invoices.send-whatsapp');

    Route::post('payments/{payment}/send-whatsapp', [DocumentWhatsAppController::class, 'sendPaymentAcknowledgement'])
        ->middleware('permission:'.Permissions::MESSAGES_SEND)
        ->name('payments.send-whatsapp');
});
