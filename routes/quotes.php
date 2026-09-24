<?php

use App\Http\Controllers\Quotes\QuoteController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('quotes', [QuoteController::class, 'index'])
        ->middleware('permission:'.Permissions::QUOTES_VIEW)
        ->name('quotes.index');

    Route::get('quotes/create', [QuoteController::class, 'create'])
        ->middleware('permission:'.Permissions::QUOTES_CREATE)
        ->name('quotes.create');

    Route::post('quotes', [QuoteController::class, 'store'])
        ->middleware('permission:'.Permissions::QUOTES_CREATE)
        ->name('quotes.store');

    Route::get('quotes/{quote}', [QuoteController::class, 'show'])
        ->middleware('permission:'.Permissions::QUOTES_VIEW)
        ->name('quotes.show');

    Route::get('quotes/{quote}/edit', [QuoteController::class, 'edit'])
        ->middleware('permission:'.Permissions::QUOTES_UPDATE)
        ->name('quotes.edit');

    Route::put('quotes/{quote}', [QuoteController::class, 'update'])
        ->middleware('permission:'.Permissions::QUOTES_UPDATE)
        ->name('quotes.update');

    Route::post('quotes/{quote}/issue', [QuoteController::class, 'issue'])
        ->middleware('permission:'.Permissions::QUOTES_ISSUE)
        ->name('quotes.issue');

    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])
        ->middleware('permission:'.Permissions::QUOTES_ACCEPT)
        ->name('quotes.accept');

    Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])
        ->middleware('permission:'.Permissions::QUOTES_REJECT)
        ->name('quotes.reject');

    Route::post('quotes/{quote}/cancel', [QuoteController::class, 'cancel'])
        ->middleware('permission:'.Permissions::QUOTES_CANCEL)
        ->name('quotes.cancel');

    Route::post('quotes/{quote}/convert', [QuoteController::class, 'convert'])
        ->middleware('permission:'.Permissions::QUOTES_CONVERT)
        ->name('quotes.convert');

    Route::post('quotes/{quote}/generate-document', [QuoteController::class, 'generateDocument'])
        ->middleware('permission:'.Permissions::DOCUMENTS_GENERATE)
        ->name('quotes.generate-document');
});
