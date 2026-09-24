<?php

namespace App\Http\Controllers\Communications;

use App\Enums\CommunicationChannel;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Services\EmailOutboundService;
use App\Services\WhatsAppOutboundService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class DocumentEmailController extends Controller
{
    public function sendQuote(Quote $quote, EmailOutboundService $emails): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $emails->queueQuoteEmail($quote, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Quote email queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    public function sendInvoice(Invoice $invoice, EmailOutboundService $emails): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $emails->queueInvoiceEmail($invoice, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Invoice email queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    public function sendPaymentAcknowledgement(Payment $payment, EmailOutboundService $emails): RedirectResponse
    {
        $this->authorize(Permissions::MESSAGES_SEND);

        try {
            $message = $emails->queuePaymentAcknowledgementEmail($payment, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with(
            'success',
            'Payment acknowledgement email queued for delivery to '.$this->recipientHint($message).'.',
        );
    }

    public function retry(
        Message $message,
        EmailOutboundService $emails,
        WhatsAppOutboundService $whatsapp,
    ): RedirectResponse {
        $this->authorize(Permissions::MESSAGES_RETRY);

        try {
            if ($message->channel === CommunicationChannel::WhatsApp) {
                $whatsapp->retry($message, auth()->user());
            } elseif ($message->channel === CommunicationChannel::Email) {
                $emails->retry($message, auth()->user());
            } else {
                throw ValidationException::withMessages([
                    'message' => 'This message channel does not support external retry.',
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Message retry queued for delivery.');
    }

    private function recipientHint(Message $message): string
    {
        $to = is_array($message->meta) ? (string) ($message->meta['to'] ?? '') : '';

        return $to !== '' ? $to : 'the customer';
    }
}
