<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\PromoteContactRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Models\Contact;
use App\Models\Payment;
use App\Services\ContactService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::CONTACTS_VIEW);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $type = $request->query('type');
        $archived = $request->query('archived', '0') === '1';

        $contacts = Contact::query()
            ->when($archived, fn ($q) => $q->archived(), fn ($q) => $q->active())
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('display_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('whatsapp_id', 'like', $like)
                        ->orWhere('organization_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            })
            ->when(
                is_string($status) && in_array($status, ContactStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->when(
                is_string($type) && in_array($type, ContactType::values(), true),
                fn ($q) => $q->where('type', $type),
            )
            ->orderBy('display_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contact $contact) => $this->listPayload($contact));

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'filters' => [
                'search' => $search,
                'status' => is_string($status) ? $status : '',
                'type' => is_string($type) ? $type : '',
                'archived' => $archived,
            ],
            'statusOptions' => collect(ContactStatus::cases())->map(fn (ContactStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'typeOptions' => collect(ContactType::cases())->map(fn (ContactType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            'canCreate' => auth()->user()?->can(Permissions::CONTACTS_CREATE) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::CONTACTS_CREATE);

        return Inertia::render('contacts/Create', [
            'typeOptions' => collect(ContactType::cases())->map(fn (ContactType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            'statusOptions' => collect(ContactStatus::cases())->map(fn (ContactStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    public function store(StoreContactRequest $request, ContactService $contacts): RedirectResponse
    {
        $this->authorize(Permissions::CONTACTS_CREATE);

        try {
            $contact = $contacts->create($request->validated());
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact created.');
    }

    public function show(Contact $contact): Response
    {
        $this->authorize(Permissions::CONTACTS_VIEW);

        $user = auth()->user();
        $canViewPayments = $user?->can(Permissions::PAYMENTS_VIEW) ?? false;

        return Inertia::render('contacts/Show', [
            'contact' => $this->detailPayload($contact),
            'permissions' => [
                'update' => $user?->can(Permissions::CONTACTS_UPDATE) ?? false,
                'promote' => $user?->can(Permissions::CONTACTS_PROMOTE) ?? false,
                'archive' => $user?->can(Permissions::CONTACTS_ARCHIVE) ?? false,
                'view_payments' => $canViewPayments,
            ],
            'availablePromotions' => $this->availablePromotions($contact),
            'paymentsSummary' => $canViewPayments
                ? [
                    'count' => Payment::query()->where('contact_id', $contact->id)->count(),
                ]
                : null,
        ]);
    }

    public function edit(Contact $contact): Response
    {
        $this->authorize(Permissions::CONTACTS_UPDATE);

        abort_if($contact->isArchived(), 403, 'Archived contacts cannot be edited.');

        return Inertia::render('contacts/Edit', [
            'contact' => $this->detailPayload($contact),
            'typeOptions' => collect(ContactType::cases())->map(fn (ContactType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
        ]);
    }

    public function update(
        UpdateContactRequest $request,
        Contact $contact,
        ContactService $contacts,
    ): RedirectResponse {
        $this->authorize(Permissions::CONTACTS_UPDATE);

        $contacts->update($contact, $request->validated());

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact updated.');
    }

    public function promote(
        PromoteContactRequest $request,
        Contact $contact,
        ContactService $contacts,
    ): RedirectResponse {
        $this->authorize(Permissions::CONTACTS_PROMOTE);

        $target = ContactStatus::from($request->validated('status'));
        $contacts->promote($contact, $target);

        return back()->with('success', 'Contact promoted to '.$target->label().'.');
    }

    public function archive(Contact $contact, ContactService $contacts): RedirectResponse
    {
        $this->authorize(Permissions::CONTACTS_ARCHIVE);

        $contacts->archive($contact);

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact archived.');
    }

    public function restore(Contact $contact, ContactService $contacts): RedirectResponse
    {
        $this->authorize(Permissions::CONTACTS_ARCHIVE);

        $contacts->restore($contact);

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'type' => $contact->type->value,
            'type_label' => $contact->type->label(),
            'status' => $contact->status->value,
            'status_label' => $contact->status->label(),
            'email' => $contact->email,
            'phone' => $contact->phone,
            'organization_name' => $contact->organization_name,
            'is_archived' => $contact->isArchived(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Contact $contact): array
    {
        return [
            ...$this->listPayload($contact),
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'whatsapp_id' => $contact->whatsapp_id,
            'whatsapp_opt_in' => (bool) $contact->whatsapp_opt_in,
            'reminder_channel' => $contact->reminder_channel instanceof \App\Enums\ReminderChannelPreference
                ? $contact->reminder_channel->value
                : (string) ($contact->reminder_channel ?? 'email'),
            'address_line_1' => $contact->address_line_1,
            'address_line_2' => $contact->address_line_2,
            'city' => $contact->city,
            'state' => $contact->state,
            'postal_code' => $contact->postal_code,
            'country' => $contact->country,
            'notes' => $contact->notes,
            'archived_at' => $contact->archived_at?->toIso8601String(),
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function availablePromotions(Contact $contact): array
    {
        if ($contact->isArchived()) {
            return [];
        }

        $targets = [];
        foreach ([ContactStatus::Prospect, ContactStatus::Customer] as $target) {
            if ($contact->status->canPromoteTo($target)) {
                $targets[] = [
                    'value' => $target->value,
                    'label' => $target->label(),
                ];
            }
        }

        return $targets;
    }
}
