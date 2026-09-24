<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\SecureDocumentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::get('d/{token}', [SecureDocumentController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('documents.secure');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/contacts.php';
require __DIR__.'/conversations.php';
require __DIR__.'/attachments.php';
require __DIR__.'/quotes.php';
require __DIR__.'/invoices.php';
require __DIR__.'/recurring-billing.php';
require __DIR__.'/payments.php';
require __DIR__.'/documents.php';
require __DIR__.'/email.php';
require __DIR__.'/whatsapp.php';
require __DIR__.'/settings.php';
