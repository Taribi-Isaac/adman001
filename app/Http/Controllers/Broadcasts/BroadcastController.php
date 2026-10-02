<?php

namespace App\Http\Controllers\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastIneligibilityReason;
use App\Enums\BroadcastRecipientStatus;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Broadcasts\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Business;
use App\Models\Contact;
use App\Services\BroadcastService;
use App\Support\Permissions;
use App\Support\WhatsAppBroadcastTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permissions::BROADCASTS_MANAGE);

        $sentStatuses = [BroadcastRecipientStatus::Sent->value, BroadcastRecipientStatus::Delivered->value];

        $broadcasts = Broadcast::query()
            ->withCount([
                'recipients as sent_count' => fn ($q) => $q->whereIn('status', $sentStatuses),
                'recipients as failed_count' => fn ($q) => $q->where('status', BroadcastRecipientStatus::Failed->value),
            ])
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Broadcast $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'channel' => $b->channel->value,
                'channel_label' => $b->channel->label(),
                'audience_label' => $b->audience_type->label(),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'recipient_count' => $b->recipient_count,
                'sent_count' => (int) $b->sent_count,
                'failed_count' => (int) $b->failed_count,
                'created_at' => $b->created_at?->toIso8601String(),
            ]);

        return Inertia::render('broadcasts/Index', [
            'broadcasts' => $broadcasts,
            'broadcastsEnabled' => (bool) Business::current()->broadcasts_enabled,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::BROADCASTS_MANAGE);

        return Inertia::render('broadcasts/Create', $this->formProps(null));
    }

    public function store(StoreBroadcastRequest $request, BroadcastService $broadcasts): RedirectResponse
    {
        $broadcast = $broadcasts->create($request->validated(), $request->user());

        return redirect()
            ->route('broadcasts.show', $broadcast)
            ->with('success', 'Broadcast draft saved. Review the audience below; nothing has been sent.');
    }

    public function edit(Broadcast $broadcast): Response|RedirectResponse
    {
        $this->authorize(Permissions::BROADCASTS_MANAGE);

        if (! $broadcast->isDraft()) {
            return redirect()->route('broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        return Inertia::render('broadcasts/Edit', $this->formProps($broadcast));
    }

    public function update(StoreBroadcastRequest $request, Broadcast $broadcast, BroadcastService $broadcasts): RedirectResponse
    {
        $broadcasts->update($broadcast, $request->validated(), $request->user());

        return redirect()
            ->route('broadcasts.show', $broadcast)
            ->with('success', 'Broadcast draft updated. Review the audience below; nothing has been sent.');
    }

    public function show(Request $request, Broadcast $broadcast, BroadcastService $broadcasts): Response
    {
        $this->authorize(Permissions::BROADCASTS_MANAGE);

        $broadcast->load(['creator:id,name', 'sender:id,name', 'canceller:id,name']);
        $user = $request->user();

        $preview = null;
        if ($broadcast->isDraft()) {
            $result = $broadcasts->preview($broadcast);
            $preview = [
                'eligible_count' => $result['eligible_count'],
                'excluded_count' => $result['excluded_count'],
                'exclusions' => $this->exclusionRows($result['exclusions']),
                'limit' => $result['limit'],
                'over_limit' => $result['over_limit'],
                'template' => $result['template'],
                'blockers' => $result['blockers'],
                'sample' => array_map(fn (Contact $c) => ['id' => $c->id, 'name' => $c->display_name], array_slice($result['eligible'], 0, 10)),
            ];
        }

        $recipients = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->with(['contact:id,display_name', 'message:id,status,failure_reason'])
            ->orderBy('id')
            ->paginate(50)
            ->through(fn (BroadcastRecipient $r) => [
                'id' => $r->id,
                'contact_id' => $r->contact_id,
                'contact_name' => $r->contact?->display_name,
                'address' => $r->address,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'message_id' => $r->message_id,
                'provider_message_id' => $r->provider_message_id,
                'failure_reason' => $r->failure_reason,
                'queued_at' => $r->queued_at?->toIso8601String(),
                'sent_at' => $r->sent_at?->toIso8601String(),
                'delivered_at' => $r->delivered_at?->toIso8601String(),
            ]);

        return Inertia::render('broadcasts/Show', [
            'broadcast' => [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'channel' => $broadcast->channel->value,
                'channel_label' => $broadcast->channel->label(),
                'audience_type' => $broadcast->audience_type->value,
                'audience_label' => $broadcast->audience_type->label(),
                'selected_count' => count($broadcast->selected_contact_ids ?? []),
                'subject' => $broadcast->subject,
                'body' => $broadcast->body,
                'whatsapp_template_name' => $broadcast->whatsapp_template_name,
                'whatsapp_template_language' => $broadcast->whatsapp_template_language,
                'status' => $broadcast->status->value,
                'status_label' => $broadcast->status->label(),
                'recipient_count' => $broadcast->recipient_count,
                'recipient_limit' => $broadcast->recipient_limit,
                'exclusions' => $this->exclusionRows($broadcast->exclusion_summary ?? []),
                'failure_reason' => $broadcast->failure_reason,
                'created_by' => $broadcast->creator?->name,
                'sent_by' => $broadcast->sender?->name,
                'cancelled_by' => $broadcast->canceller?->name,
                'created_at' => $broadcast->created_at?->toIso8601String(),
                'send_requested_at' => $broadcast->send_requested_at?->toIso8601String(),
                'started_at' => $broadcast->started_at?->toIso8601String(),
                'completed_at' => $broadcast->completed_at?->toIso8601String(),
                'cancelled_at' => $broadcast->cancelled_at?->toIso8601String(),
                'failed_at' => $broadcast->failed_at?->toIso8601String(),
            ],
            'counts' => $broadcasts->counts($broadcast->id),
            'recipients' => $recipients,
            'preview' => $preview,
            'permissions' => [
                'edit' => $broadcast->isDraft(),
                'send' => $broadcast->isDraft() && ($user?->can(Permissions::BROADCASTS_SEND) ?? false),
                'cancel' => $broadcast->status->canBeCancelled(),
            ],
        ]);
    }

    public function send(Request $request, Broadcast $broadcast, BroadcastService $broadcasts): RedirectResponse
    {
        $this->authorize(Permissions::BROADCASTS_SEND);

        $validated = $request->validate([
            'confirm_recipient_count' => ['required', 'integer', 'min:1'],
        ], [
            'confirm_recipient_count.required' => 'Confirm the number of recipients before sending.',
        ]);

        $broadcasts->start($broadcast, $request->user(), (int) $validated['confirm_recipient_count']);

        return redirect()
            ->route('broadcasts.show', $broadcast)
            ->with('success', 'Broadcast queued. Messages are sent in small batches; this page shows progress.');
    }

    public function cancel(Request $request, Broadcast $broadcast, BroadcastService $broadcasts): RedirectResponse
    {
        $this->authorize(Permissions::BROADCASTS_MANAGE);

        $broadcasts->cancel($broadcast, $request->user());

        return redirect()
            ->route('broadcasts.show', $broadcast)
            ->with('success', 'Broadcast cancelled. No further messages will be sent; messages already sent stay recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(?Broadcast $broadcast): array
    {
        $template = WhatsAppBroadcastTemplate::configured();

        return [
            'broadcast' => $broadcast === null ? null : [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'channel' => $broadcast->channel->value,
                'audience_type' => $broadcast->audience_type->value,
                'selected_contact_ids' => $broadcast->selected_contact_ids ?? [],
                'subject' => $broadcast->subject,
                'body' => $broadcast->body,
            ],
            'channelOptions' => collect(CommunicationChannel::cases())->map(fn (CommunicationChannel $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
            'audienceOptions' => collect(BroadcastAudience::cases())->map(fn (BroadcastAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ]),
            'contactOptions' => Contact::query()
                ->whereNull('archived_at')
                ->whereIn('status', [ContactStatus::Customer->value, ContactStatus::Prospect->value])
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'status', 'email', 'phone', 'whatsapp_id'])
                ->map(fn (Contact $c) => [
                    'id' => $c->id,
                    'name' => $c->display_name,
                    'status_label' => $c->status->label(),
                    'email' => $c->email,
                    'phone' => $c->whatsapp_id ?: $c->phone,
                ]),
            'whatsappTemplate' => [
                'configured' => $template !== null,
                'name' => $template?->name,
                'language' => $template?->language,
                'problem' => WhatsAppBroadcastTemplate::problem(),
            ],
            'recipientLimit' => BroadcastService::recipientLimit(),
            'broadcastsEnabled' => (bool) Business::current()->broadcasts_enabled,
        ];
    }

    /**
     * @param  array<string, int>  $exclusions
     * @return list<array{reason: string, label: string, count: int}>
     */
    private function exclusionRows(array $exclusions): array
    {
        $rows = [];
        foreach ($exclusions as $reason => $count) {
            $rows[] = [
                'reason' => (string) $reason,
                'label' => BroadcastIneligibilityReason::tryFrom((string) $reason)?->label() ?? (string) $reason,
                'count' => (int) $count,
            ];
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }
}
