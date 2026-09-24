<?php

namespace App\Services\Ai;

use App\Models\Business;
use App\Models\BusinessKnowledgeArticle;
use App\Models\BusinessOffering;
use App\Models\Conversation;

/**
 * Assembles a bounded, editable business-knowledge block for the AI system prompt.
 * Does not replace controlled tools for customer-specific or financial truth.
 */
final class AiBusinessContextAssembler
{
    private const MAX_CHARS = 6000;

    public function assemble(Business $business, Conversation $conversation): string
    {
        $linked = $conversation->contact !== null || $conversation->identity?->contact !== null;

        $sections = [];

        $sections[] = 'Business profile:';
        $sections[] = '- Name: '.$business->name;
        if (filled($business->legal_name)) {
            $sections[] = '- Legal name: '.$business->legal_name;
        }
        if (filled($business->description)) {
            $sections[] = '- Description: '.trim((string) $business->description);
        }
        if (filled($business->email)) {
            $sections[] = '- Email: '.$business->email;
        }
        if (filled($business->phone)) {
            $sections[] = '- Phone: '.$business->phone;
        }
        if (filled($business->website)) {
            $sections[] = '- Website: '.$business->website;
        }
        $address = collect([
            $business->address_line_1,
            $business->address_line_2,
            $business->city,
            $business->state,
            $business->postal_code,
            $business->country,
        ])->filter()->implode(', ');
        if ($address !== '') {
            $sections[] = '- Address: '.$address;
        }
        if (filled($business->payment_instructions)) {
            $sections[] = '- General payment instructions: '.trim((string) $business->payment_instructions);
        }
        if (filled($business->ai_support_instructions)) {
            $sections[] = '- Support instructions: '.trim((string) $business->ai_support_instructions);
        }

        $offerings = BusinessOffering::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(20)
            ->get(['name', 'description']);

        if ($offerings->isNotEmpty()) {
            $sections[] = '';
            $sections[] = 'Services / products (customer-facing):';
            foreach ($offerings as $offering) {
                $line = '- '.$offering->name;
                if (filled($offering->description)) {
                    $line .= ': '.trim((string) $offering->description);
                }
                $sections[] = $line;
            }
        }

        $articles = BusinessKnowledgeArticle::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(30)
            ->get(['title', 'content', 'category']);

        if ($articles->isNotEmpty()) {
            $sections[] = '';
            $sections[] = 'Approved FAQs / policies / support knowledge:';
            foreach ($articles as $article) {
                $sections[] = '- ['.$article->category->value.'] '.$article->title.': '
                    .mb_substr(trim((string) $article->content), 0, 500);
            }
        }

        $sections[] = '';
        $sections[] = $linked
            ? 'A contact may be linked. Use tools for customer-specific invoices, balances, quotes, and payment claims. Never invent financial facts.'
            : 'No authorized customer is linked. Share only general business information above. Refuse account-specific data and offer human help when needed.';

        $sections[] = 'If an inbound message includes a file/media attachment that you cannot interpret, acknowledge receipt and say the team will review it. Never claim you reviewed file contents you cannot see.';
        $sections[] = 'Never invent services, policies, payment confirmations, or invoice details that are not in this context or tool results.';

        $text = implode("\n", $sections);
        if (mb_strlen($text) > self::MAX_CHARS) {
            return mb_substr($text, 0, self::MAX_CHARS - 3).'...';
        }

        return $text;
    }
}
