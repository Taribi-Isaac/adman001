<?php

use App\Http\Controllers\Contacts\ContactController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])
        ->middleware('permission:'.Permissions::CONTACTS_VIEW)
        ->name('contacts.index');

    Route::get('contacts/create', [ContactController::class, 'create'])
        ->middleware('permission:'.Permissions::CONTACTS_CREATE)
        ->name('contacts.create');

    Route::post('contacts', [ContactController::class, 'store'])
        ->middleware('permission:'.Permissions::CONTACTS_CREATE)
        ->name('contacts.store');

    Route::get('contacts/{contact}', [ContactController::class, 'show'])
        ->middleware('permission:'.Permissions::CONTACTS_VIEW)
        ->name('contacts.show');

    Route::get('contacts/{contact}/edit', [ContactController::class, 'edit'])
        ->middleware('permission:'.Permissions::CONTACTS_UPDATE)
        ->name('contacts.edit');

    Route::put('contacts/{contact}', [ContactController::class, 'update'])
        ->middleware('permission:'.Permissions::CONTACTS_UPDATE)
        ->name('contacts.update');

    Route::post('contacts/{contact}/promote', [ContactController::class, 'promote'])
        ->middleware('permission:'.Permissions::CONTACTS_PROMOTE)
        ->name('contacts.promote');

    Route::post('contacts/{contact}/archive', [ContactController::class, 'archive'])
        ->middleware('permission:'.Permissions::CONTACTS_ARCHIVE)
        ->name('contacts.archive');

    Route::post('contacts/{contact}/restore', [ContactController::class, 'restore'])
        ->middleware('permission:'.Permissions::CONTACTS_ARCHIVE)
        ->name('contacts.restore');
});
