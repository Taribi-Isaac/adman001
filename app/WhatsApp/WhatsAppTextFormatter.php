<?php

namespace App\WhatsApp;

/**
 * Converts the common Markdown constructs produced by the AI into WhatsApp text formatting.
 *
 * WhatsApp uses *bold*, _italic_ and has no link syntax, so Markdown links, **bold**,
 * single-asterisk italics and # headings render incorrectly if sent unchanged.
 * Intentionally conservative: anything not matched below is left exactly as written.
 */
class WhatsAppTextFormatter
{
    /** Temporary marker for WhatsApp bold so later passes do not re-read it as Markdown italic. */
    private const BOLD = "\u{E000}";

    public static function format(string $text): string
    {
        $text = self::links($text);

        $lines = array_map(self::line(...), explode("\n", $text));

        return str_replace(self::BOLD, '*', implode("\n", $lines));
    }

    private static function links(string $text): string
    {
        // [label](https://url) → https://url
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]*)\]\((https?:\/\/[^\s()]+(?:\([^\s()]*\)[^\s()]*)*)\)/i',
            fn (array $m) => $m[2],
            $text,
        );

        // <https://url> autolinks → https://url
        return (string) preg_replace('/<(https?:\/\/[^\s>]+)>/i', '$1', $text);
    }

    private static function line(string $line): string
    {
        // # Heading → *Heading*
        if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
            $heading = trim(str_replace(['**', '__'], '', $m[1]));

            return $heading === '' ? '' : self::BOLD.$heading.self::BOLD;
        }

        // Markdown italic *text* (single asterisks) → WhatsApp italic _text_.
        // Runs before bold so the bold output is not re-interpreted.
        $line = (string) preg_replace(
            '/(?<![*\w])\*(?![\s*])([^*\n]+?)(?<![\s*])\*(?![*\w])/u',
            '_$1_',
            $line,
        );

        // **text** / __text__ → WhatsApp bold *text*
        return (string) preg_replace(
            ['/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/u'],
            self::BOLD.'$1'.self::BOLD,
            $line,
        );
    }
}
