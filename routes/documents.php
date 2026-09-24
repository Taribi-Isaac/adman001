<?php

use App\Http\Controllers\Documents\DocumentController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('documents', [DocumentController::class, 'index'])
        ->middleware('permission:'.Permissions::DOCUMENTS_VIEW)
        ->name('documents.index');

    Route::get('documents/{document}/download', [DocumentController::class, 'download'])
        ->middleware('permission:'.Permissions::DOCUMENTS_VIEW)
        ->name('documents.download');

    Route::post('documents/{document}/revoke-link', [DocumentController::class, 'revokeLink'])
        ->middleware('permission:'.Permissions::DOCUMENTS_REVOKE_LINK)
        ->name('documents.revoke-link');

    Route::post('documents/{document}/create-link', [DocumentController::class, 'createLink'])
        ->middleware('permission:'.Permissions::DOCUMENTS_GENERATE)
        ->name('documents.create-link');
});
