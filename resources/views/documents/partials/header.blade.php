{{--
    Expects: $business (array), $logoSrc (?string), $documentTitle (string), $metaHtml (rendered HTML string or use slot via @include with $meta)
    Pass $meta as a pre-rendered HTML string for DomPDF simplicity.
--}}
<table class="layout">
    <tr>
        <td class="logo-cell">
            @if(!empty($logoSrc))
                <img class="logo" src="{{ $logoSrc }}" alt="Logo">
            @endif
        </td>
        <td class="word-break">
            <h1>{{ $business['name'] ?? 'Business' }}</h1>
            @php
                $legalName = trim((string) ($business['legal_name'] ?? ''));
                $displayName = trim((string) ($business['name'] ?? ''));
            @endphp
            @if($legalName !== '' && strcasecmp($legalName, $displayName) !== 0)
                <div class="muted">{{ $legalName }}</div>
            @endif
            @php
                $addressLine = collect([
                    $business['address_line_1'] ?? null,
                    $business['address_line_2'] ?? null,
                ])->filter()->implode(', ');
                $locality = collect([
                    $business['city'] ?? null,
                    $business['state'] ?? null,
                    $business['postal_code'] ?? null,
                ])->filter()->implode(', ');
            @endphp
            @if($addressLine !== '')
                <div class="muted">{{ $addressLine }}</div>
            @endif
            @if($locality !== '')
                <div class="muted">{{ $locality }}</div>
            @endif
            @if(!empty($business['country']))
                <div class="muted">{{ $business['country'] }}</div>
            @endif
            @if(!empty($business['email']))
                <div class="muted">{{ $business['email'] }}</div>
            @endif
            @if(!empty($business['phone']))
                <div class="muted">{{ $business['phone'] }}</div>
            @endif
            @if(!empty($business['tax_identification']))
                <div class="muted">Tax ID: {{ $business['tax_identification'] }}</div>
            @endif
        </td>
        <td class="meta word-break">
            <h1>{{ $documentTitle }}</h1>
            {!! $metaHtml !!}
        </td>
    </tr>
</table>
