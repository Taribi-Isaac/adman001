<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

final class DocumentNumbering
{
    public function nextQuoteNumber(?Business $business = null): string
    {
        return $this->allocate($business, 'quote');
    }

    public function nextInvoiceNumber(?Business $business = null): string
    {
        return $this->allocate($business, 'invoice');
    }

    public function nextReceiptNumber(?Business $business = null): string
    {
        return $this->allocate($business, 'receipt');
    }

    private function allocate(?Business $business, string $type): string
    {
        return DB::transaction(function () use ($business, $type) {
            $businessId = ($business ?? Business::current())->id;

            /** @var Business $locked */
            $locked = Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();

            if ($type === 'quote') {
                $sequence = (int) $locked->quote_next_sequence;
                $locked->quote_next_sequence = $sequence + 1;
                $prefix = $locked->quote_number_prefix ?: 'QT-';
            } elseif ($type === 'invoice') {
                $sequence = (int) $locked->invoice_next_sequence;
                $locked->invoice_next_sequence = $sequence + 1;
                $prefix = $locked->invoice_number_prefix ?: 'INV-';
            } else {
                $sequence = (int) ($locked->receipt_next_sequence ?? 1);
                $locked->receipt_next_sequence = $sequence + 1;
                $prefix = $locked->receipt_number_prefix ?: 'RCPT-';
            }

            $locked->save();

            return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }
}
