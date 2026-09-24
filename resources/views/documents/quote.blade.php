<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quote {{ $quote->number }}</title>
    @include('documents.partials.styles')
</head>
<body>
    @php
        $metaHtml = '<div><strong>'.e($quote->number).'</strong></div>'
            .'<div class="muted">Issue date: '.e($quote->issue_date?->toDateString() ?? '—').'</div>'
            .'<div class="muted">Valid until: '.e($quote->expiry_date?->toDateString() ?? '—').'</div>';
    @endphp
    @include('documents.partials.header', [
        'business' => $business,
        'logoSrc' => $logoSrc ?? null,
        'documentTitle' => 'QUOTE',
        'metaHtml' => $metaHtml,
    ])

    @include('documents.partials.bill-to', [
        'customer' => $customer,
        'label' => 'Bill to',
    ])

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th>Unit</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td class="word-break">{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td>{{ $item->unit ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->line_subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-wrap">
        <tr>
            <td></td>
            <td style="width: 280px;">
                <table class="totals">
                    <tr>
                        <td>Subtotal</td>
                        <td class="num">{{ number_format((float) $quote->subtotal, 2) }} {{ $quote->currency_code }}</td>
                    </tr>
                    @if((float) $quote->discount_amount > 0)
                        <tr>
                            <td>Discount</td>
                            <td class="num">-{{ number_format((float) $quote->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>Taxable</td>
                        <td class="num">{{ number_format((float) $quote->taxable_subtotal, 2) }}</td>
                    </tr>
                    @if($quote->tax_enabled)
                        <tr>
                            <td>{{ $quote->tax_name ?: 'Tax' }} ({{ rtrim(rtrim(number_format((float) $quote->tax_rate, 4), '0'), '.') }}%)</td>
                            <td class="num">{{ number_format((float) $quote->tax_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td>Total</td>
                        <td class="num">{{ number_format((float) $quote->total, 2) }} {{ $quote->currency_code }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($quote->notes)
        <div class="section word-break">
            <strong>Notes</strong>
            <div>{{ $quote->notes }}</div>
        </div>
    @endif

    @if($quote->terms)
        <div class="section word-break">
            <strong>Terms</strong>
            <div>{{ $quote->terms }}</div>
        </div>
    @endif
</body>
</html>
