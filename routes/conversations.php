<?php

use App\Http\Controllers\Conversations\ConversationController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('conversations', [ConversationController::class, 'index'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_VIEW)
        ->name('conversations.index');

    Route::get('conversations/create', [ConversationController::class, 'create'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_MANAGE)
        ->name('conversations.create');

    Route::post('conversations', [ConversationController::class, 'store'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_MANAGE)
        ->name('conversations.store');

    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_VIEW)
        ->name('conversations.show');

    Route::post('conversations/{conversation}/take-over', [ConversationController::class, 'takeOver'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_TAKEOVER)
        ->name('conversations.take-over');

    Route::post('conversations/{conversation}/return-to-ai', [ConversationController::class, 'returnToAi'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_TAKEOVER)
        ->name('conversations.return-to-ai');

    Route::post('conversations/{conversation}/close', [ConversationController::class, 'close'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_CLOSE)
        ->name('conversations.close');

    Route::post('conversations/{conversation}/reopen', [ConversationController::class, 'reopen'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_CLOSE)
        ->name('conversations.reopen');

    Route::post('conversations/{conversation}/link-contact', [ConversationController::class, 'linkContact'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_LINK_CONTACT)
        ->name('conversations.link-contact');

    Route::post('conversations/{conversation}/unlink-contact', [ConversationController::class, 'unlinkContact'])
        ->middleware('permission:'.Permissions::CONVERSATIONS_LINK_CONTACT)
        ->name('conversations.unlink-contact');

    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'compose'])
        ->middleware('permission:'.Permissions::MESSAGES_COMPOSE)
        ->name('conversations.messages.store');
});
