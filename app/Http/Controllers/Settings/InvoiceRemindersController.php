<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateInvoiceRemindersRequest;
use App\Models\Business;
use App\Models\ReminderRule;
use App\Services\ReminderService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceRemindersController extends Controller
{
    public function edit(ReminderService $reminders): Response
    {
        $this->authorize(Permissions::AUTOMATION_REMINDERS_VIEW);

        $business = Business::current();
        $rules = $reminders->ensureDefaultRules($business);

        return Inertia::render('settings/automation/InvoiceReminders', [
            'enabled' => (bool) $business->invoice_reminders_enabled,
            'timezone' => $business->timezone,
            'rules' => $rules->map(fn (ReminderRule $rule) => [
                'id' => $rule->id,
                'offset_days' => $rule->offset_days,
                'is_enabled' => $rule->is_enabled,
                'sort_order' => $rule->sort_order,
                'label' => $rule->label(),
            ])->values()->all(),
            'offsetMin' => ReminderService::OFFSET_MIN,
            'offsetMax' => ReminderService::OFFSET_MAX,
            'canManage' => auth()->user()?->can(Permissions::AUTOMATION_REMINDERS_MANAGE) ?? false,
        ]);
    }

    public function update(
        UpdateInvoiceRemindersRequest $request,
        ReminderService $reminders,
    ): RedirectResponse {
        $this->authorize(Permissions::AUTOMATION_REMINDERS_MANAGE);

        $data = $request->validated();
        $reminders->syncRules(
            Business::current(),
            (bool) $data['enabled'],
            $data['rules'],
            $request->user(),
        );

        return back()->with('success', 'Invoice reminder settings saved.');
    }
}
