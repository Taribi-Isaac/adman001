@php
    use App\Support\DocumentPresentation;
    $customerName = DocumentPresentation::customerName($customer ?? []);
    $label = $label ?? 'Bill to';
    $addressLine = collect([
        $customer['address_line_1'] ?? null,
        $customer['address_line_2'] ?? null,
    ])->filter()->implode(', ');
    $locality = collect([
        $customer['city'] ?? null,
        $customer['state'] ?? null,
        $customer['postal_code'] ?? null,
    ])->filter()->implode(', ');
@endphp
<table class="layout">
    <tr>
        <td class="word-break">
            <h2>{{ $label }}</h2>
            <div><strong>{{ $customerName }}</strong></div>
            @if(!empty($customer['organization_name']) && $customerName !== trim((string) $customer['organization_name']))
                <div class="muted">{{ $customer['organization_name'] }}</div>
            @endif
            @if($addressLine !== '')
                <div class="muted">{{ $addressLine }}</div>
            @endif
            @if($locality !== '')
                <div class="muted">{{ $locality }}</div>
            @endif
            @if(!empty($customer['country']))
                <div class="muted">{{ $customer['country'] }}</div>
            @endif
            @if(!empty($customer['email']))
                <div class="muted">{{ $customer['email'] }}</div>
            @endif
            @if(!empty($customer['phone']))
                <div class="muted">{{ $customer['phone'] }}</div>
            @endif
        </td>
    </tr>
</table>
