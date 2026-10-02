<?php

use App\Http\Controllers\Broadcasts\BroadcastController;
use App\Http\Controllers\Broadcasts\BroadcastUnsubscribeController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('broadcasts', [BroadcastController::class, 'index'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.index');

    Route::get('broadcasts/create', [BroadcastController::class, 'create'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.create');

    Route::post('broadcasts', [BroadcastController::class, 'store'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.store');

    Route::get('broadcasts/{broadcast}', [BroadcastController::class, 'show'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.show');

    Route::get('broadcasts/{broadcast}/edit', [BroadcastController::class, 'edit'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.edit');

    Route::put('broadcasts/{broadcast}', [BroadcastController::class, 'update'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.update');

    Route::post('broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])
        ->middleware('permission:'.Permissions::BROADCASTS_SEND)
        ->name('broadcasts.send');

    Route::post('broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel'])
        ->middleware('permission:'.Permissions::BROADCASTS_MANAGE)
        ->name('broadcasts.cancel');
});

// Public, signed and login-free; POST also serves RFC 8058 one-click unsubscribe.
Route::get('email/unsubscribe/{contact}/{broadcast}', [BroadcastUnsubscribeController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('broadcasts.unsubscribe');

Route::post('email/unsubscribe/{contact}/{broadcast}', [BroadcastUnsubscribeController::class, 'store'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('broadcasts.unsubscribe.store');
