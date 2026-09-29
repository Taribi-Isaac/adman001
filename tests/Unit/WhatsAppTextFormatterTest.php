<?php

namespace Tests\Unit;

use App\WhatsApp\WhatsAppTextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsAppTextFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plain url unchanged' => ['https://example.com', 'https://example.com'],
            'markdown link with label' => ['Visit [Example](https://example.com) today.', 'Visit https://example.com today.'],
            'markdown link where label is the url' => [
                'The website for Raslordeck Limited is [https://raslordeckltd.com/](https://raslordeckltd.com/). You can find more there.',
                'The website for Raslordeck Limited is https://raslordeckltd.com/. You can find more there.',
            ],
            'autolink angle brackets' => ['See <https://example.com/a>', 'See https://example.com/a'],
            'bold' => ['This is **important** now', 'This is *important* now'],
            'underscore bold' => ['This is __important__ now', 'This is *important* now'],
            'markdown italic' => ['This is *important* now', 'This is _important_ now'],
            'whatsapp italic unchanged' => ['This is _important_ now', 'This is _important_ now'],
            'h1 heading' => ['# Heading', '*Heading*'],
            'h2 heading' => ['## Heading', '*Heading*'],
            'heading with bold inside' => ['### **Our services**', '*Our services*'],
            'hash without space unchanged' => ['#1 priority for us', '#1 priority for us'],
            'multiple urls' => [
                'Site: https://a.example.com and [docs](https://b.example.com/docs) or https://c.example.com',
                'Site: https://a.example.com and https://b.example.com/docs or https://c.example.com',
            ],
            'url with query string' => [
                'Pay here: [link](https://pay.example.com/i?ref=INV-1&amount=100.00#top)',
                'Pay here: https://pay.example.com/i?ref=INV-1&amount=100.00#top',
            ],
            'bare url with query string unchanged' => [
                'https://pay.example.com/i?ref=INV-1&amount=100.00',
                'https://pay.example.com/i?ref=INV-1&amount=100.00',
            ],
            'bullet list' => [
                "Team:\n- **Franklin Ogan** - Managing Director\n- Azubuike - Accountant",
                "Team:\n- *Franklin Ogan* - Managing Director\n- Azubuike - Accountant",
            ],
            'asterisk bullets preserved' => ["* First\n* Second", "* First\n* Second"],
            'numbered list' => [
                "1. **Logistics**: Delivery.\n\n2. **Solar**: Installation.",
                "1. *Logistics*: Delivery.\n\n2. *Solar*: Installation.",
            ],
            'normal punctuation unchanged' => [
                'Hello! How can I help (today)? Costs are 5 * 3 = 15; ok... "quoted" & done.',
                'Hello! How can I help (today)? Costs are 5 * 3 = 15; ok... "quoted" & done.',
            ],
            'multiplication without spaces unchanged' => ['2*3 and 4*5', '2*3 and 4*5'],
            'email address preserved' => ['Email hello_team@raslordeckltd.com for help.', 'Email hello_team@raslordeckltd.com for help.'],
            'snake case preserved' => ['field first__name__x stays', 'field first__name__x stays'],
            'line breaks preserved' => ["Line one\nLine two\n\nLine four", "Line one\nLine two\n\nLine four"],
            'empty string' => ['', ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_formats_for_whatsapp(string $input, string $expected): void
    {
        $this->assertSame($expected, WhatsAppTextFormatter::format($input));
    }

    public function test_output_never_contains_markdown_link_syntax(): void
    {
        $output = WhatsAppTextFormatter::format('A [one](https://one.test) B [https://two.test](https://two.test)');

        $this->assertDoesNotMatchRegularExpression('/\[[^\]]*\]\(/', $output);
        $this->assertSame('A https://one.test B https://two.test', $output);
    }
}
