<?php

namespace App\Http\Controllers\Conversations;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\ComposeMessageRequest;
use App\Http\Requests\Conversations\LinkContactRequest;
use App\Http\Requests\Conversations\StoreConversationRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\ConversationService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::CONVERSATIONS_VIEW);

        $search = trim((string) $request->query('search', ''));
        $mode = $request->query('mode');
        $channel = $request->query('channel');

        $conversations = Conversation::query()
            ->with(['identity', 'contact', 'assignedUser:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->whereHas('identity', function ($identity) use ($like) {
                        $identity->where('external_id', 'like', $like)
                            ->orWhere('display_name', 'like', $like);
                    })->orWhereHas('contact', function ($contact) use ($like) {
                        $contact->where('display_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
                });
            })
            ->when(
                is_string($mode) && in_array($mode, ConversationMode::values(), true),
                fn ($q) => $q->where('mode', $mode),
            )
            ->when(
                is_string($channel) && in_array($channel, CommunicationChannel::values(), true),
                fn ($q) => $q->where('channel', $channel),
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Conversation $conversation) => $this->listPayload($conversation));

        return Inertia::render('conversations/Index', [
            'conversations' => $conversations,
            'filters' => [
                'search' => $search,
                'mode' => is_string($mode) ? $mode : '',
                'channel' => is_string($channel) ? $channel : '',
            ],
            'modeOptions' => collect(ConversationMode::cases())->map(fn (ConversationMode $m) => [
                'value' => $m->value,
                'label' => $m->label(),
            ]),
            'channelOptions' => collect(CommunicationChannel::cases())->map(fn (CommunicationChannel $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
            'canManage' => auth()->user()?->can(Permissions::CONVERSATIONS_MANAGE) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::CONVERSATIONS_MANAGE);

        return Inertia::render('conversations/Create', [
            'channelOptions' => collect(CommunicationChannel::cases())->map(fn (CommunicationChannel $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
            'contacts' => Contact::query()
                ->active()
                ->orderBy('display_name')
                ->limit(100)
                ->get(['id', 'display_name', 'status', 'email', 'phone']),
        ]);
    }

    public function store(StoreConversationRequest $request, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_MANAGE);

        $data = $request->validated();
        $channel = CommunicationChannel::from($data['channel']);
        $contact = isset($data['contact_id'])
            ? Contact::query()->findOrFail($data['contact_id'])
            : null;

        $identity = $service->findOrCreateIdentity(
            channel: $channel,
            externalId: $data['external_id'],
            displayName: $data['display_name'] ?? null,
            contact: $contact,
        );

        if ($contact !== null && $identity->contact_id !== $contact->id) {
            $service->linkIdentityToContact($identity, $contact);
        }

        $conversation = $service->openConversation(
            identity: $identity->refresh(),
            mode: ConversationMode::Ai,
            subject: $data['subject'] ?? null,
        );

        if (! empty($data['initial_message'])) {
            $service->recordInboundMessage($conversation, $data['initial_message']);
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', 'Conversation created.');
    }

    public function show(Conversation $conversation): Response
    {
        $this->authorize(Permissions::CONVERSATIONS_VIEW);
        $this->authorize(Permissions::MESSAGES_VIEW);

        $conversation->load(['identity', 'contact', 'assignedUser:id,name']);

        $messages = $conversation->messages()
            ->with('actorUser:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => $this->messagePayload($message));

        $user = auth()->user();

        $attachments = [];
        if ($user?->can(Permissions::ATTACHMENTS_VIEW)) {
            $attachments = MessageAttachment::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (MessageAttachment $attachment) => [
                    'id' => $attachment->id,
                    'message_id' => $attachment->message_id,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'byte_size' => $attachment->byte_size,
                    'media_kind' => $attachment->media_kind,
                    'processing_status' => $attachment->processing_status,
                    'review_status' => $attachment->review_status->value,
                    'review_status_label' => $attachment->review_status->label(),
                    'failure_reason' => $attachment->failure_reason,
                    'created_at' => $attachment->created_at?->toIso8601String(),
                    'downloadable' => $attachment->processing_status === 'stored' && $attachment->existsOnDisk(),
                    'download_url' => route('attachments.download', $attachment, absolute: false),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('conversations/Show', [
            'conversation' => $this->detailPayload($conversation),
            'messages' => $messages,
            'attachments' => $attachments,
            'permissions' => [
                'takeover' => $user?->can(Permissions::CONVERSATIONS_TAKEOVER) ?? false,
                'close' => $user?->can(Permissions::CONVERSATIONS_CLOSE) ?? false,
                'link' => $user?->can(Permissions::CONVERSATIONS_LINK_CONTACT) ?? false,
                'compose' => $user?->can(Permissions::MESSAGES_COMPOSE) ?? false,
                'retry_email' => $user?->can(Permissions::MESSAGES_RETRY) ?? false,
                'attachments_view' => $user?->can(Permissions::ATTACHMENTS_VIEW) ?? false,
                'attachments_review' => $user?->can(Permissions::ATTACHMENTS_REVIEW) ?? false,
            ],
            'linkableContacts' => ($user?->can(Permissions::CONVERSATIONS_LINK_CONTACT) ?? false)
                ? Contact::query()
                    ->active()
                    ->orderBy('display_name')
                    ->limit(200)
                    ->get(['id', 'display_name', 'status', 'email', 'phone', 'type'])
                    ->map(fn (Contact $contact) => [
                        'id' => $contact->id,
                        'display_name' => $contact->display_name,
                        'status' => $contact->status->value,
                        'status_label' => $contact->status->label(),
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'type' => $contact->type->value,
                    ])
                : [],
        ]);
    }

    public function takeOver(Conversation $conversation, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_TAKEOVER);

        $service->takeOver($conversation, auth()->user());

        return back()->with('success', 'You are now handling this conversation. AI replies are paused.');
    }

    public function returnToAi(Conversation $conversation, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_TAKEOVER);

        $service->returnToAi($conversation);

        return back()->with('success', 'Conversation returned to AI. No AI response was generated.');
    }

    public function close(Conversation $conversation, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_CLOSE);

        $service->close($conversation);

        return back()->with('success', 'Conversation closed.');
    }

    public function reopen(Conversation $conversation, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_CLOSE);

        $service->reopen($conversation, ConversationMode::Human);

        return back()->with('success', 'Conversation reopened in Human mode.');
    }

    public function linkContact(
        LinkContactRequest $request,
        Conversation $conversation,
        ConversationService $service,
    ): RedirectResponse {
        $this->authorize(Permissions::CONVERSATIONS_LINK_CONTACT);

        $contact = Contact::query()->findOrFail($request->validated('contact_id'));
        $identity = $conversation->identity;

        $previousStatus = $contact->status->value;
        $service->linkIdentityToContact($identity, $contact);

        $contact->refresh();
        abort_unless($contact->status->value === $previousStatus, 500, 'Contact lifecycle changed unexpectedly.');

        return back()->with('success', 'Identity linked to contact. Contact lifecycle was not changed.');
    }

    public function unlinkContact(Conversation $conversation, ConversationService $service): RedirectResponse
    {
        $this->authorize(Permissions::CONVERSATIONS_LINK_CONTACT);

        $service->unlinkIdentity($conversation->identity);

        return back()->with('success', 'Identity unlinked from contact.');
    }

    public function compose(
        ComposeMessageRequest $request,
        Conversation $conversation,
        ConversationService $service,
    ): RedirectResponse {
        $this->authorize(Permissions::MESSAGES_COMPOSE);

        $service->composeInternalOutbound(
            $conversation,
            auth()->user(),
            $request->validated('body'),
        );

        return back()->with('success', 'Internal outbound message recorded. Not sent externally.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Conversation $conversation): array
    {
        $identity = $conversation->identity;

        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel->value,
            'channel_label' => $conversation->channel->label(),
            'mode' => $conversation->mode->value,
            'mode_label' => $conversation->mode->label(),
            'subject' => $conversation->subject,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'is_closed' => $conversation->isClosed(),
            'identity' => [
                'id' => $identity?->id,
                'external_id' => $identity?->external_id,
                'display_name' => $identity?->label(),
                'is_linked' => $identity?->isLinked() ?? false,
            ],
            'contact' => $conversation->contact ? [
                'id' => $conversation->contact->id,
                'display_name' => $conversation->contact->display_name,
                'status' => $conversation->contact->status->value,
                'status_label' => $conversation->contact->status->label(),
            ] : null,
            'assigned_user' => $conversation->assignedUser?->only(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Conversation $conversation): array
    {
        $base = $this->listPayload($conversation);
        $contact = $conversation->contact;

        $base['contact_detail'] = $contact ? [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'type' => $contact->type->value,
            'type_label' => $contact->type->label(),
            'status' => $contact->status->value,
            'status_label' => $contact->status->label(),
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp_id' => $contact->whatsapp_id,
            'organization_name' => $contact->organization_name,
        ] : null;

        $base['created_at'] = $conversation->created_at?->toIso8601String();
        $base['closed_at'] = $conversation->closed_at?->toIso8601String();

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(Message $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'direction_label' => $message->direction->label(),
            'channel' => $message->channel->value,
            'channel_label' => $message->channel->label(),
            'body' => $message->body,
            'subject' => $message->subject,
            'status' => $message->status->value,
            'status_label' => $message->status->label(),
            'actor_type' => $message->actor_type->value,
            'actor_type_label' => $message->actor_type->label(),
            'actor_name' => $message->actor_type === MessageActorType::Ai
                ? 'AI assistant'
                : $message->actorUser?->name,
            'occurred_at' => $message->occurred_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'failure_reason' => $message->failure_reason,
            'is_external_delivery' => $message->status !== MessageStatus::Recorded,
            'can_retry' => (
                ($message->channel === CommunicationChannel::Email
                    || $message->channel === CommunicationChannel::WhatsApp)
                && ($message->status === MessageStatus::Failed
                    || $message->status === MessageStatus::Pending)
            ),
        ];
    }
}
