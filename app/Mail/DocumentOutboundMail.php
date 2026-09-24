<?php

namespace App\Mail;

use App\Enums\EmailTemplateKey;
use App\Support\EmailDeliveryPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Document outbound email rendered through Laravel Mail.
 * Queuing is handled by SendOutboundEmailJob — this mailable is sent synchronously inside the job.
 */
class DocumentOutboundMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly EmailDeliveryPayload $payload,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = [];
        if (is_string($this->payload->replyTo) && $this->payload->replyTo !== '') {
            $replyTo[] = new Address($this->payload->replyTo);
        }

        return new Envelope(
            from: new Address($this->payload->fromAddress, $this->payload->fromName),
            replyTo: $replyTo,
            subject: $this->payload->subject,
        );
    }

    public function content(): Content
    {
        $view = match ($this->payload->templateKey) {
            EmailTemplateKey::Quote => 'mail.documents.quote',
            EmailTemplateKey::Invoice => 'mail.documents.invoice',
            EmailTemplateKey::InvoiceReminder => 'mail.documents.invoice-reminder',
            EmailTemplateKey::PaymentAcknowledgement => 'mail.documents.payment-acknowledgement',
        };

        return new Content(
            markdown: $view,
            with: $this->payload->viewData,
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->payload->hasAttachment()) {
            return [];
        }

        /** @var string $disk */
        $disk = $this->payload->attachmentDisk;
        /** @var string $path */
        $path = $this->payload->attachmentPath;
        /** @var string $filename */
        $filename = $this->payload->attachmentFilename;

        if (! Storage::disk($disk)->exists($path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($disk, $path)
                ->as($filename)
                ->withMime($this->payload->attachmentMime ?: 'application/pdf'),
        ];
    }
}
