<?php

use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\AuditEventController;
use App\Http\Controllers\Settings\BusinessKnowledgeController;
use App\Http\Controllers\Settings\BusinessSettingsController;
use App\Http\Controllers\Settings\CommunicationSettingsController;
use App\Http\Controllers\Settings\InvoiceRemindersController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\UserManagementController;
use App\Support\Permissions;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::middleware('permission:'.Permissions::SETTINGS_ACCESS)->group(function () {
        Route::get('settings/business', [BusinessSettingsController::class, 'edit'])
            ->name('settings.business.edit');
        Route::put('settings/business', [BusinessSettingsController::class, 'update'])
            ->middleware('permission:'.Permissions::BUSINESS_UPDATE)
            ->name('settings.business.update');
        Route::get('settings/business/logo', [BusinessSettingsController::class, 'showLogo'])
            ->name('settings.business.logo.show');
        Route::post('settings/business/logo', [BusinessSettingsController::class, 'updateLogo'])
            ->middleware('permission:'.Permissions::BUSINESS_UPDATE)
            ->name('settings.business.logo.update');
        Route::delete('settings/business/logo', [BusinessSettingsController::class, 'destroyLogo'])
            ->middleware('permission:'.Permissions::BUSINESS_UPDATE)
            ->name('settings.business.logo.destroy');

        Route::get('settings/communication', [CommunicationSettingsController::class, 'edit'])
            ->name('settings.communication.edit');

        Route::get('settings/knowledge', [BusinessKnowledgeController::class, 'index'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_VIEW)
            ->name('settings.knowledge.index');
        Route::post('settings/knowledge/offerings', [BusinessKnowledgeController::class, 'storeOffering'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.offerings.store');
        Route::put('settings/knowledge/offerings/{offering}', [BusinessKnowledgeController::class, 'updateOffering'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.offerings.update');
        Route::delete('settings/knowledge/offerings/{offering}', [BusinessKnowledgeController::class, 'destroyOffering'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.offerings.destroy');
        Route::post('settings/knowledge/articles', [BusinessKnowledgeController::class, 'storeArticle'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.articles.store');
        Route::put('settings/knowledge/articles/{article}', [BusinessKnowledgeController::class, 'updateArticle'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.articles.update');
        Route::delete('settings/knowledge/articles/{article}', [BusinessKnowledgeController::class, 'destroyArticle'])
            ->middleware('permission:'.Permissions::BUSINESS_KNOWLEDGE_MANAGE)
            ->name('settings.knowledge.articles.destroy');

        Route::get('settings/users', [UserManagementController::class, 'index'])
            ->middleware('permission:'.Permissions::USERS_VIEW)
            ->name('settings.users.index');
        Route::post('settings/users', [UserManagementController::class, 'store'])
            ->middleware('permission:'.Permissions::USERS_CREATE)
            ->name('settings.users.store');
        Route::put('settings/users/{user}', [UserManagementController::class, 'update'])
            ->middleware('permission:'.Permissions::USERS_UPDATE)
            ->name('settings.users.update');

        Route::get('settings/audit', [AuditEventController::class, 'index'])
            ->middleware('permission:'.Permissions::AUDIT_VIEW)
            ->name('settings.audit.index');

        Route::get('settings/automation', [InvoiceRemindersController::class, 'edit'])
            ->middleware('permission:'.Permissions::AUTOMATION_REMINDERS_VIEW)
            ->name('settings.automation.reminders.edit');

        Route::put('settings/automation', [InvoiceRemindersController::class, 'update'])
            ->middleware('permission:'.Permissions::AUTOMATION_REMINDERS_MANAGE)
            ->name('settings.automation.reminders.update');

        Route::get('settings/ai', [AiSettingsController::class, 'edit'])
            ->middleware('permission:'.Permissions::AI_VIEW)
            ->name('settings.ai.edit');

        Route::put('settings/ai', [AiSettingsController::class, 'update'])
            ->middleware('permission:'.Permissions::AI_MANAGE)
            ->name('settings.ai.update');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
