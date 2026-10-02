<?php

namespace App\Http\Controllers\Broadcasts;

use App\Enums\ConsentSource;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\Business;
use App\Models\Contact;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Public, signed email-broadcast unsubscribe (no login). Only ever turns broadcast email
 * off; transactional email (quotes, invoices, reminders, receipts) is unaffected.
 */
class BroadcastUnsubscribeController extends Controller
{
    public function show(Request $request, Contact $contact, Broadcast $broadcast): View
    {
        return view('broadcasts.unsubscribe', [
            'businessName' => Business::current()->name,
            'unsubscribed' => $contact->email_broadcast_unsubscribed_at !== null,
            'actionUrl' => $request->fullUrl(),
        ]);
    }

    public function store(Contact $contact, Broadcast $broadcast, AuditLogger $auditLogger): View
    {
        DB::transaction(function () use ($contact, $broadcast, $auditLogger) {
            $locked = Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();

            if ($locked->email_broadcast_unsubscribed_at !== null) {
                return;
            }

            $locked->forceFill([
                'email_broadcast_unsubscribed_at' => now(),
                'email_broadcast_unsubscribe_source' => ConsentSource::UnsubscribeLink,
            ])->save();

            $auditLogger->record(
                event: 'contact.consent_changed',
                description: 'Email broadcast unsubscribe recorded',
                auditable: $locked,
                oldValues: ['email_broadcast_unsubscribed' => false],
                newValues: [
                    'consent' => 'email_broadcast_unsubscribed',
                    'channel' => 'email',
                    'state' => true,
                    'source' => ConsentSource::UnsubscribeLink->value,
                    'at' => $locked->email_broadcast_unsubscribed_at?->toIso8601String(),
                ],
                meta: ['broadcast_id' => $broadcast->id],
            );
        });

        return view('broadcasts.unsubscribe', [
            'businessName' => Business::current()->name,
            'unsubscribed' => true,
            'actionUrl' => null,
        ]);
    }
}
