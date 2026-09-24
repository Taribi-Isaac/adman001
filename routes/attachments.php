<?php

use App\Http\Controllers\Attachments\MessageAttachmentController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('attachments/{attachment}/download', [MessageAttachmentController::class, 'download'])
        ->middleware('permission:'.Permissions::ATTACHMENTS_VIEW)
        ->name('attachments.download');

    Route::post('attachments/{attachment}/review', [MessageAttachmentController::class, 'review'])
        ->middleware('permission:'.Permissions::ATTACHMENTS_REVIEW)
        ->name('attachments.review');
});
