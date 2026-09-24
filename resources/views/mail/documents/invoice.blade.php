@component('mail::message')
# {{ $headline }}

Hello{{ $customer_name ? ' '.$customer_name : '' }},

{{ $intro }}

**{{ $document_label }}:** {{ $document_number }}
@if (! empty($amount_label) && ! empty($amount))
**{{ $amount_label }}:** {{ $amount }}
@endif
@if (! empty($due_date))
**Due date:** {{ $due_date }}
@endif

@if (! empty($secure_url))
@component('mail::button', ['url' => $secure_url])
{{ $cta_label ?? 'View in browser' }}
@endcomponent

The PDF is attached to this email. You can also open a secure browser copy; the link may expire or be revoked by staff.
@else
The PDF is attached to this email.
@endif

Thanks,<br>
{{ $business_name }}
@endcomponent
