@component('mail::message')
Hello{{ $customer_name ? ' '.$customer_name : '' }},

{!! nl2br(e($body)) !!}

Thanks,<br>
{{ $business_name }}

@component('mail::subcopy')
You are receiving this email because you agreed to receive updates from {{ $business_name }}.
[Unsubscribe from these emails]({{ $unsubscribe_url }}). This does not affect quotes, invoices or receipts we send you.
@endcomponent
@endcomponent
