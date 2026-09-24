<?php

namespace App\Http\Controllers\Documents;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Services\DocumentService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SecureDocumentController extends Controller
{
    public function show(string $token, DocumentService $documents): Response|SymfonyResponse
    {
        $document = $documents->findByPlainToken($token);

        if ($document === null || ! $document->isAccessActive()) {
            abort(404);
        }

        $source = $document->documentable;

        if ($source instanceof Quote) {
            if ($source->status === QuoteStatus::Draft) {
                abort(404);
            }
        } elseif ($source instanceof Invoice) {
            if ($source->lifecycle_status === InvoiceLifecycleStatus::Draft) {
                abort(404);
            }
        } elseif ($source instanceof Payment) {
            if ($source->status !== PaymentStatus::Confirmed) {
                abort(404);
            }
        } else {
            abort(404);
        }

        try {
            $binary = $document->contents();
        } catch (\Throwable) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => $document->mime_type ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
