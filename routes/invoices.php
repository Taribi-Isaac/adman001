<?php

use App\Http\Controllers\Invoices\InvoiceController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->middleware('permission:'.Permissions::INVOICES_VIEW)
        ->name('invoices.index');

    Route::get('invoices/create', [InvoiceController::class, 'create'])
        ->middleware('permission:'.Permissions::INVOICES_CREATE)
        ->name('invoices.create');

    Route::post('invoices', [InvoiceController::class, 'store'])
        ->middleware('permission:'.Permissions::INVOICES_CREATE)
        ->name('invoices.store');

    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->middleware('permission:'.Permissions::INVOICES_VIEW)
        ->name('invoices.show');

    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->middleware('permission:'.Permissions::INVOICES_UPDATE)
        ->name('invoices.edit');

    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
        ->middleware('permission:'.Permissions::INVOICES_UPDATE)
        ->name('invoices.update');

    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->middleware('permission:'.Permissions::INVOICES_ISSUE)
        ->name('invoices.issue');

    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->middleware('permission:'.Permissions::INVOICES_CANCEL)
        ->name('invoices.cancel');

    Route::post('invoices/{invoice}/generate-document', [InvoiceController::class, 'generateDocument'])
        ->middleware('permission:'.Permissions::DOCUMENTS_GENERATE)
        ->name('invoices.generate-document');
});
