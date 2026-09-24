<?php

namespace App\Mail\Adapters;

use App\Contracts\EmailDeliveryAdapter;
use App\Mail\DocumentOutboundMail;
use App\Support\EmailDeliveryPayload;
use App\Support\EmailDeliveryResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email provider adapter via Laravel's mail abstraction (Resend / SMTP / log / array / …).
 * Production uses MAIL_MAILER=resend. Swap this binding only for a direct provider SDK.
 */
final class LaravelMailEmailDeliveryAdapter implements EmailDeliveryAdapter
{
    public function send(EmailDeliveryPayload $payload): EmailDeliveryResult
    {
        try {
            $providerMessageId = null;

            Mail::to($payload->toAddress, $payload->toName)
                ->send(
                    (new DocumentOutboundMail($payload))->withSymfonyMessage(function ($message) use (&$providerMessageId) {
                        $header = $message->getHeaders()->get('Message-ID');
                        if ($header !== null) {
                            $providerMessageId = trim($header->getBodyAsString(), '<>');
                        }
                    }),
                );

            return EmailDeliveryResult::ok(
                $providerMessageId ?: ('laravel-mail-'.$payload->messageId),
            );
        } catch (Throwable $e) {
            report($e);

            return EmailDeliveryResult::failed(
                'Email provider rejected or failed the send. Please retry later or check mail configuration.',
            );
        }
    }
}
