<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\DocumentService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::DOCUMENTS_VIEW);

        $search = trim((string) $request->query('search', ''));

        $documents = Document::query()
            ->with(['documentable', 'generator:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('filename', 'like', $like)
                        ->orWhere('type', 'like', $like)
                        ->orWhere('meta->number', 'like', $like);
                });
            })
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Document $document) => $this->listPayload($document));

        $user = auth()->user();

        return Inertia::render('documents/Index', [
            'documents' => $documents,
            'filters' => [
                'search' => $search,
            ],
            'permissions' => [
                'revoke_link' => $user?->can(Permissions::DOCUMENTS_REVOKE_LINK) ?? false,
                'generate' => $user?->can(Permissions::DOCUMENTS_GENERATE) ?? false,
            ],
            'flashSecureUrl' => session('secure_url'),
        ]);
    }

    public function download(Document $document): StreamedResponse|HttpResponse
    {
        $this->authorize(Permissions::DOCUMENTS_VIEW);

        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->filename,
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
            ],
        );
    }

    public function revokeLink(Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize(Permissions::DOCUMENTS_REVOKE_LINK);

        $documents->revokeSecureLink($document, auth()->user());

        return back()->with('success', 'Secure share link revoked.');
    }

    public function createLink(Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize(Permissions::DOCUMENTS_GENERATE);

        $result = $documents->createSecureLink($document, auth()->user());
        $plain = $result['plain_token'];

        return back()
            ->with('success', 'Secure share link created. Copy it now — it will not be shown again.')
            ->with('secure_url', url('/d/'.$plain));
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Document $document): array
    {
        $source = $document->documentable;
        $sourceLabel = null;
        $sourceUrl = null;

        if ($source instanceof Quote) {
            $sourceLabel = 'Quote '.$source->number;
            $sourceUrl = route('quotes.show', $source);
        } elseif ($source instanceof Invoice) {
            $sourceLabel = 'Invoice '.$source->number;
            $sourceUrl = route('invoices.show', $source);
        }

        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'filename' => $document->filename,
            'byte_size' => $document->byte_size,
            'generated_at' => $document->generated_at?->toIso8601String(),
            'generator' => $document->generator?->only(['id', 'name']),
            'has_active_link' => $document->isAccessActive(),
            'access_expires_at' => $document->access_expires_at?->toIso8601String(),
            'access_revoked_at' => $document->access_revoked_at?->toIso8601String(),
            'source_label' => $sourceLabel ?? ($document->meta['number'] ?? 'Document'),
            'source_url' => $sourceUrl,
            'meta_number' => $document->meta['number'] ?? null,
        ];
    }
}
