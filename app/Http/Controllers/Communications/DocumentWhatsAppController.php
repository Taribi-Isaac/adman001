<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Services\WhatsAppOutboundService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class DocumentWhatsAppController extends Controller
{
    public function sendQuote(Quote $quote, WhatsAppOutboundService $whatsapp): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $whatsapp->queueQuoteWhatsApp($quote, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Quote WhatsApp message queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    public function sendInvoice(Invoice $invoice, WhatsAppOutboundService $whatsapp): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $whatsapp->queueInvoiceWhatsApp($invoice, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Invoice WhatsApp message queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    public function sendPaymentAcknowledgement(Payment $payment, WhatsAppOutboundService $whatsapp): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $whatsapp->queuePaymentAcknowledgementWhatsApp($payment, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Payment acknowledgement WhatsApp message queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    private function recipientHint(Message $message): string
    {
        $to = is_array($message->meta) ? (string) ($message->meta['to'] ?? '') : '';

        return $to !== '' ? $to : 'the customer';
    }
}
