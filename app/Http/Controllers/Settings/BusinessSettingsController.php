<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessLogoRequest;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\Services\AuditLogger;
use App\Support\DocumentPresentation;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BusinessSettingsController extends Controller
{
    public function edit(): InertiaResponse
    {
        $this->authorize(Permissions::BUSINESS_VIEW);

        $business = Business::current();

        return Inertia::render('settings/business/Edit', [
            'business' => $business,
            'timezones' => timezone_identifiers_list(),
            'canUpdate' => auth()->user()?->can(Permissions::BUSINESS_UPDATE) ?? false,
            'logo' => $this->logoPayload($business),
        ]);
    }

    public function update(UpdateBusinessSettingsRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_UPDATE);

        $business = Business::current();
        $validated = $request->validated();
        unset($validated['logo_path']);

        $old = $business->only(array_keys($validated));

        $business->fill($validated);
        $business->save();

        $auditLogger->record(
            event: 'business.settings_updated',
            description: 'Business settings were updated',
            auditable: $business,
            oldValues: $old,
            newValues: $business->only(array_keys($validated)),
        );

        return back()->with('success', 'Business settings saved.');
    }

    public function updateLogo(UpdateBusinessLogoRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_UPDATE);

        $business = Business::current();
        $file = $request->file('logo');
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'png'));
        if (! in_array($extension, ['jpeg', 'jpg', 'png', 'webp', 'gif'], true)) {
            $extension = 'png';
        }

        $path = 'branding/logo.'.$extension;
        $previousPath = $business->logo_path;

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $path) {
            Storage::disk('local')->delete($previousPath);
        }

        $business->logo_path = $path;
        $business->save();

        $auditLogger->record(
            event: 'business.logo_updated',
            description: 'Business logo was updated',
            auditable: $business,
            oldValues: ['logo_path' => $previousPath],
            newValues: ['logo_path' => $path],
        );

        return back()->with('success', 'Business logo updated.');
    }

    public function destroyLogo(AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_UPDATE);

        $business = Business::current();
        $previousPath = $business->logo_path;

        if (is_string($previousPath) && $previousPath !== '') {
            Storage::disk('local')->delete($previousPath);
        }

        $business->logo_path = null;
        $business->save();

        $auditLogger->record(
            event: 'business.logo_removed',
            description: 'Business logo was reset to the ADMAN default',
            auditable: $business,
            oldValues: ['logo_path' => $previousPath],
            newValues: ['logo_path' => null],
        );

        return back()->with('success', 'Business logo reset to the ADMAN default.');
    }

    public function showLogo(): BinaryFileResponse|Response
    {
        $this->authorize(Permissions::BUSINESS_VIEW);

        $business = Business::current();
        $path = $business->logo_path;

        if (! DocumentPresentation::hasCustomLogo($path)) {
            $default = public_path(DocumentPresentation::DEFAULT_LOGO_FILENAME);
            abort_unless(is_file($default), 404);

            return response()->file($default);
        }

        /** @var string $path */
        $absolute = Storage::disk('local')->path($path);
        $mime = mime_content_type($absolute) ?: 'image/png';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * @return array{has_custom: bool, preview_url: string}
     */
    private function logoPayload(Business $business): array
    {
        $hasCustom = DocumentPresentation::hasCustomLogo($business->logo_path);

        return [
            'has_custom' => $hasCustom,
            'preview_url' => $hasCustom
                ? route('settings.business.logo.show', ['v' => $business->updated_at?->timestamp ?? time()])
                : DocumentPresentation::defaultLogoPublicUrl(),
        ];
    }
}
