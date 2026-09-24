<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Inertia\Inertia;
use Inertia\Response;

class SettingsSectionController extends Controller
{
    /**
     * Placeholder settings sections for future domains.
     */
    public function show(string $section): Response
    {
        $this->authorize(Permissions::SETTINGS_ACCESS);

        $sections = [
            'automation' => [
                'title' => 'Automation',
                'description' => 'Invoice reminder rules are configured under Settings → Automation.',
            ],
            'ai' => [
                'title' => 'AI',
                'description' => 'AI assistant settings are configured under Settings → AI. Business knowledge is under Settings → Knowledge.',
            ],
        ];

        abort_unless(array_key_exists($section, $sections), 404);

        return Inertia::render('settings/Placeholder', [
            'section' => $sections[$section],
        ]);
    }
}
