<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    @include('documents.partials.styles')
</head>
<body>
    @php
        $metaHtml = '<div><strong>'.e($invoice->number).'</strong></div>'
            .'<div class="muted">Issue date: '.e($invoice->issue_date?->toDateString() ?? '—').'</div>'
            .'<div class="muted">Due date: '.e($invoice->due_date?->toDateString() ?? '—').'</div>';
    @endphp
    @include('documents.partials.header', [
        'business' => $business,
        'logoSrc' => $logoSrc ?? null,
        'documentTitle' => 'INVOICE',
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
                        <td class="num">{{ number_format((float) $invoice->subtotal, 2) }} {{ $invoice->currency_code }}</td>
                    </tr>
                    @if((float) $invoice->discount_amount > 0)
                        <tr>
                            <td>Discount</td>
                            <td class="num">-{{ number_format((float) $invoice->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>Taxable</td>
                        <td class="num">{{ number_format((float) $invoice->taxable_subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->tax_enabled)
                        <tr>
                            <td>{{ $invoice->tax_name ?: 'Tax' }} ({{ rtrim(rtrim(number_format((float) $invoice->tax_rate, 4), '0'), '.') }}%)</td>
                            <td class="num">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td>Total</td>
                        <td class="num">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency_code }}</td>
                    </tr>
                    <tr>
                        <td>Amount paid</td>
                        <td class="num">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Balance due</td>
                        <td class="num">{{ number_format((float) $invoice->balance_due, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if(!empty($business['bank_name']) || !empty($business['payment_instructions']))
        <div class="section word-break">
            <strong>Payment instructions</strong>
            @if(!empty($business['bank_name']))
                <div>{{ $business['bank_name'] }}</div>
            @endif
            @if(!empty($business['bank_account_name']))
                <div>Account name: {{ $business['bank_account_name'] }}</div>
            @endif
            @if(!empty($business['bank_account_number']))
                <div>Account number: {{ $business['bank_account_number'] }}</div>
            @endif
            @if(!empty($business['payment_instructions']))
                <div>{{ $business['payment_instructions'] }}</div>
            @endif
        </div>
    @endif

    @if($invoice->notes)
        <div class="section word-break">
            <strong>Notes</strong>
            <div>{{ $invoice->notes }}</div>
        </div>
    @endif

    @if($invoice->terms)
        <div class="section word-break">
            <strong>Terms</strong>
            <div>{{ $invoice->terms }}</div>
        </div>
    @endif
</body>
</html>
