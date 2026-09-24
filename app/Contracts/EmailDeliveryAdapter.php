<?php

namespace App\Contracts;

use App\Support\EmailDeliveryPayload;
use App\Support\EmailDeliveryResult;

/**
 * Isolates provider-specific email submission from the Communication domain.
 */
interface EmailDeliveryAdapter
{
    public function send(EmailDeliveryPayload $payload): EmailDeliveryResult;
}
