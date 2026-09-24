<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment acknowledgement {{ $payment->number }}</title>
    @include('documents.partials.styles')
</head>
<body>
    @php
        $metaHtml = '<div><strong>'.e($payment->number).'</strong></div>'
            .'<div class="muted">Payment date: '.e($payment->payment_date?->toDateString() ?? '—').'</div>'
            .'<div class="muted">Method: '.e($payment->payment_method->label()).'</div>';
        if ($payment->reference) {
            $metaHtml .= '<div class="muted">Reference: '.e($payment->reference).'</div>';
        }
    @endphp
    @include('documents.partials.header', [
        'business' => $business,
        'logoSrc' => $logoSrc ?? null,
        'documentTitle' => 'PAYMENT ACKNOWLEDGEMENT',
        'metaHtml' => $metaHtml,
    ])

    @include('documents.partials.bill-to', [
        'customer' => $customer,
        'label' => 'Received from',
    ])

    @if($is_partial)
        <div class="banner">
            <strong>Partial payment</strong> — This acknowledgement confirms receipt of a partial payment.
            The invoice is <strong>not fully settled</strong>. Remaining balance:
            {{ number_format((float) $balance_due_after, 2) }} {{ $payment->currency_code }}.
        </div>
    @else
        <div class="banner">
            <strong>Full settlement</strong> — This payment settles the invoice balance in full.
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Amount received (this payment)</td>
                <td class="num">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency_code }}</td>
            </tr>
            <tr>
                <td>Invoice {{ $invoice->number }} total</td>
                <td class="num">{{ number_format((float) $invoice_total, 2) }} {{ $payment->currency_code }}</td>
            </tr>
            <tr>
                <td>Total confirmed paid (after this payment)</td>
                <td class="num">{{ number_format((float) $amount_paid_after, 2) }} {{ $payment->currency_code }}</td>
            </tr>
            <tr>
                <td>Remaining balance</td>
                <td class="num">{{ number_format((float) $balance_due_after, 2) }} {{ $payment->currency_code }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section muted word-break">
        This is a payment acknowledgement for a manually verified offline payment.
        It is not a tax invoice. Invoice reference: {{ $invoice->number }}.
    </div>
</body>
</html>
