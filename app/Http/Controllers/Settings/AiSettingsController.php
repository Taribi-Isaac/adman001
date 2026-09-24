<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAiSettingsRequest;
use App\Models\Business;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function edit(): Response
    {
        $this->authorize(Permissions::AI_VIEW);

        $business = Business::current();

        return Inertia::render('settings/ai/Edit', [
            'enabled' => (bool) $business->ai_enabled,
            'customerResponsesEnabled' => (bool) $business->ai_customer_responses_enabled,
            'providerConfigured' => filled(config('adman.ai.api_key'))
                || (string) config('adman.ai.provider') === 'fake',
            'provider' => (string) config('adman.ai.provider', 'openai'),
            'model' => (string) config('adman.ai.model', 'gpt-4o-mini'),
            'canManage' => auth()->user()?->can(Permissions::AI_MANAGE) ?? false,
        ]);
    }

    public function update(UpdateAiSettingsRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::AI_MANAGE);

        $business = Business::current();
        $data = $request->validated();

        $old = [
            'ai_enabled' => (bool) $business->ai_enabled,
            'ai_customer_responses_enabled' => (bool) $business->ai_customer_responses_enabled,
        ];

        $business->ai_enabled = (bool) $data['enabled'];
        $business->ai_customer_responses_enabled = (bool) $data['customer_responses_enabled'];
        $business->save();

        if ($old['ai_enabled'] !== $business->ai_enabled) {
            $auditLogger->record(
                event: $business->ai_enabled ? 'ai.enabled' : 'ai.disabled',
                description: $business->ai_enabled ? 'AI enabled' : 'AI disabled',
                auditable: $business,
                actor: $request->user(),
            );
        }

        if ($old['ai_customer_responses_enabled'] !== $business->ai_customer_responses_enabled) {
            $auditLogger->record(
                event: 'ai.customer_responses_updated',
                description: 'AI customer responses setting updated',
                auditable: $business,
                oldValues: ['ai_customer_responses_enabled' => $old['ai_customer_responses_enabled']],
                newValues: ['ai_customer_responses_enabled' => $business->ai_customer_responses_enabled],
                actor: $request->user(),
            );
        }

        return back()->with('success', 'AI settings saved.');
    }
}
