<?php

namespace App\Http\Controllers\Quotes;

use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\StoreQuoteRequest;
use App\Http\Requests\Quotes\UpdateQuoteRequest;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\DocumentService;
use App\Services\QuoteService;
use App\Support\EmailDeliveryPresenter;
use App\Support\Permissions;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::QUOTES_VIEW);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $quotes = Quote::query()
            ->with(['contact:id,display_name,status'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhereHas('contact', function ($contact) use ($like) {
                            $contact->where('display_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when(
                is_string($status) && in_array($status, QuoteStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quote $quote) => $this->listPayload($quote));

        return Inertia::render('quotes/Index', [
            'quotes' => $quotes,
            'filters' => [
                'search' => $search,
                'status' => is_string($status) ? $status : '',
            ],
            'statusOptions' => collect(QuoteStatus::cases())->map(fn (QuoteStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'canCreate' => auth()->user()?->can(Permissions::QUOTES_CREATE) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::QUOTES_CREATE);

        return Inertia::render('quotes/Create', [
            'customers' => $this->customerOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
            'defaults' => $this->formDefaults(),
        ]);
    }

    public function store(StoreQuoteRequest $request, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_CREATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        try {
            $quote = $quotes->create($data, $items, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Quote created.');
    }

    public function show(Quote $quote, QuoteService $quotes): Response
    {
        $this->authorize(Permissions::QUOTES_VIEW);

        $quotes->refreshExpiry($quote);
        $quote->refresh()->load(['items', 'contact', 'invoice:id,quote_id,number', 'documents']);

        $user = auth()->user();

        return Inertia::render('quotes/Show', [
            'quote' => $this->detailPayload($quote),
            'documents' => $quote->documents->map(fn (Document $doc) => $this->documentPayload($doc)),
            'permissions' => [
                'update' => ($user?->can(Permissions::QUOTES_UPDATE) ?? false) && $quote->isDraft(),
                'issue' => ($user?->can(Permissions::QUOTES_ISSUE) ?? false) && $quote->isDraft(),
                'accept' => ($user?->can(Permissions::QUOTES_ACCEPT) ?? false) && $quote->status === QuoteStatus::Issued,
                'reject' => ($user?->can(Permissions::QUOTES_REJECT) ?? false) && $quote->status === QuoteStatus::Issued,
                'cancel' => ($user?->can(Permissions::QUOTES_CANCEL) ?? false)
                    && ! in_array($quote->status, [QuoteStatus::Cancelled, QuoteStatus::Accepted], true)
                    && $quote->invoice === null,
                'convert' => ($user?->can(Permissions::QUOTES_CONVERT) ?? false)
                    && $quote->status === QuoteStatus::Accepted,
                'generate' => ($user?->can(Permissions::DOCUMENTS_GENERATE) ?? false)
                    && $quote->status !== QuoteStatus::Draft,
                'revoke_link' => $user?->can(Permissions::DOCUMENTS_REVOKE_LINK) ?? false,
                'send_email' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && $quote->status !== QuoteStatus::Draft
                    && filled($quote->contact?->email),
                'send_whatsapp' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && $quote->status !== QuoteStatus::Draft
                    && $quote->contact !== null
                    && ($quote->contact->whatsapp_opt_in ?? false)
                    && WhatsAppPhone::fromContact($quote->contact) !== null,
            ],
            'emailDeliveries' => EmailDeliveryPresenter::recentFor($quote),
            'whatsappDeliveries' => EmailDeliveryPresenter::recentFor($quote, channel: CommunicationChannel::WhatsApp),
            'customerEmail' => $quote->contact?->email,
            'customerWhatsApp' => $quote->contact ? WhatsAppPhone::fromContact($quote->contact) : null,
            'flashSecureUrl' => session('secure_url'),
        ]);
    }

    public function edit(Quote $quote): Response
    {
        $this->authorize(Permissions::QUOTES_UPDATE);

        abort_unless($quote->isDraft(), 403, 'Only draft quotes can be edited.');

        $quote->load(['items', 'contact']);

        return Inertia::render('quotes/Edit', [
            'quote' => $this->detailPayload($quote),
            'customers' => $this->customerOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
        ]);
    }

    public function update(
        UpdateQuoteRequest $request,
        Quote $quote,
        QuoteService $quotes,
    ): RedirectResponse {
        $this->authorize(Permissions::QUOTES_UPDATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $quotes->updateDraft($quote, $data, $items);

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Quote updated.');
    }

    public function issue(Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_ISSUE);

        $quotes->issue($quote);

        return back()->with('success', 'Quote issued.');
    }

    public function accept(Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_ACCEPT);

        $quotes->accept($quote);

        return back()->with('success', 'Quote accepted.');
    }

    public function reject(Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_REJECT);

        $quotes->reject($quote);

        return back()->with('success', 'Quote rejected.');
    }

    public function cancel(Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_CANCEL);

        $quotes->cancel($quote);

        return back()->with('success', 'Quote cancelled.');
    }

    public function convert(Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize(Permissions::QUOTES_CONVERT);

        $invoice = $quotes->convertToInvoice($quote, auth()->user());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Quote converted to invoice '.$invoice->number.'.');
    }

    public function generateDocument(
        Quote $quote,
        DocumentService $documents,
    ): RedirectResponse {
        $this->authorize(Permissions::DOCUMENTS_GENERATE);

        $result = $documents->generateQuotePdf($quote, auth()->user());
        $plain = $result['plain_token'];

        $redirect = back()->with('success', 'Quote PDF generated.');

        if (is_string($plain) && $plain !== '') {
            $redirect->with('secure_url', url('/d/'.$plain));
        }

        return $redirect;
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => $quote->status->value,
            'status_label' => $quote->status->label(),
            'total' => $quote->total,
            'currency_code' => $quote->currency_code,
            'issue_date' => $quote->issue_date?->toDateString(),
            'expiry_date' => $quote->expiry_date?->toDateString(),
            'contact' => $quote->contact ? [
                'id' => $quote->contact->id,
                'display_name' => $quote->contact->display_name,
            ] : null,
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Quote $quote): array
    {
        return [
            ...$this->listPayload($quote),
            'discount_type' => $quote->discount_type->value,
            'discount_type_label' => $quote->discount_type->label(),
            'discount_value' => $quote->discount_value,
            'discount_amount' => $quote->discount_amount,
            'tax_enabled' => $quote->tax_enabled,
            'tax_name' => $quote->tax_name,
            'tax_rate' => $quote->tax_rate,
            'tax_amount' => $quote->tax_amount,
            'subtotal' => $quote->subtotal,
            'taxable_subtotal' => $quote->taxable_subtotal,
            'notes' => $quote->notes,
            'terms' => $quote->terms,
            'issued_at' => $quote->issued_at?->toIso8601String(),
            'accepted_at' => $quote->accepted_at?->toIso8601String(),
            'rejected_at' => $quote->rejected_at?->toIso8601String(),
            'cancelled_at' => $quote->cancelled_at?->toIso8601String(),
            'created_at' => $quote->created_at?->toIso8601String(),
            'invoice' => $quote->relationLoaded('invoice') && $quote->invoice
                ? ['id' => $quote->invoice->id, 'number' => $quote->invoice->number]
                : null,
            'items' => $quote->relationLoaded('items')
                ? $quote->items->map(fn (QuoteItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_subtotal' => $item->line_subtotal,
                ])->values()->all()
                : [],
            'contact_detail' => $quote->contact ? [
                'id' => $quote->contact->id,
                'display_name' => $quote->contact->display_name,
                'email' => $quote->contact->email,
                'phone' => $quote->contact->phone,
                'status' => $quote->contact->status->value,
                'status_label' => $quote->contact->status->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'filename' => $document->filename,
            'generated_at' => $document->generated_at?->toIso8601String(),
            'has_active_link' => $document->isAccessActive(),
            'access_expires_at' => $document->access_expires_at?->toIso8601String(),
            'access_revoked_at' => $document->access_revoked_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, display_name: string, email: string|null}>
     */
    private function customerOptions(): array
    {
        return Contact::query()
            ->active()
            ->where('status', ContactStatus::Customer)
            ->orderBy('display_name')
            ->limit(300)
            ->get(['id', 'display_name', 'email'])
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'email' => $contact->email,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function discountTypeOptions(): array
    {
        return collect(DiscountType::cases())->map(fn (DiscountType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(): array
    {
        $business = Business::current();

        return [
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => (bool) $business->tax_enabled,
            'tax_rate' => (string) ($business->tax_rate ?? '0'),
            'terms' => $business->default_terms,
            'currency_code' => $business->currency_code,
        ];
    }
}
