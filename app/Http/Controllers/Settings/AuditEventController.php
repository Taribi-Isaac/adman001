<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Permissions;
use Inertia\Inertia;
use Inertia\Response;

class AuditEventController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permissions::AUDIT_VIEW);

        $events = AuditEvent::query()
            ->with('actor:id,name,email')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'description' => $event->description,
                'actor' => $event->actor?->only(['id', 'name', 'email']),
                'auditable_type' => $event->auditable_type,
                'auditable_id' => $event->auditable_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/Audit', [
            'events' => $events,
        ]);
    }
}
