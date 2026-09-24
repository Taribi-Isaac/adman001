<?php

namespace App\Mail\Adapters;

use App\Contracts\EmailDeliveryAdapter;
use App\Mail\DocumentOutboundMail;
use App\Support\EmailDeliveryPayload;
use App\Support\EmailDeliveryResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * First email provider adapter: Laravel's mail abstraction (SMTP / SES / Postmark / log / array).
 * Swap this binding to introduce a direct provider SDK without changing EmailOutboundService.
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
