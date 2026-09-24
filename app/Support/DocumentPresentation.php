<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Shared helpers for commercial PDF presentation (customer labels, logos).
 * Not a document-design framework — keep templates thin and DomPDF-safe.
 */
final class DocumentPresentation
{
    public const DEFAULT_LOGO_FILENAME = 'ADMAN-LOGO.png';

    /**
     * Resolve a display name from a customer snapshot (or contact-shaped array).
     *
     * @param  array<string, mixed>  $customer
     */
    public static function customerName(array $customer): string
    {
        $display = trim((string) ($customer['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }

        $organization = trim((string) ($customer['organization_name'] ?? ''));
        if ($organization !== '') {
            return $organization;
        }

        $combined = trim(
            trim((string) ($customer['first_name'] ?? '')).' '.trim((string) ($customer['last_name'] ?? ''))
        );
        if ($combined !== '') {
            return $combined;
        }

        return 'Customer';
    }

    /**
     * Absolute filesystem path for a logo: business file when present, else public default.
     */
    public static function resolveLogoAbsolutePath(?string $logoPath): string
    {
        if (is_string($logoPath) && $logoPath !== '') {
            $absolute = Storage::disk('local')->path($logoPath);
            if (is_file($absolute)) {
                return $absolute;
            }
        }

        return public_path(self::DEFAULT_LOGO_FILENAME);
    }

    /**
     * DomPDF-friendly data URI for the resolved logo, or null if no file exists.
     */
    public static function logoDataUri(?string $logoPath): ?string
    {
        $absolute = self::resolveLogoAbsolutePath($logoPath);
        if (! is_file($absolute) || ! is_readable($absolute)) {
            return null;
        }

        $binary = file_get_contents($absolute);
        if ($binary === false || $binary === '') {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    public static function defaultLogoPublicUrl(): string
    {
        return asset(self::DEFAULT_LOGO_FILENAME);
    }

    public static function hasCustomLogo(?string $logoPath): bool
    {
        if (! is_string($logoPath) || $logoPath === '') {
            return false;
        }

        return is_file(Storage::disk('local')->path($logoPath));
    }
}
