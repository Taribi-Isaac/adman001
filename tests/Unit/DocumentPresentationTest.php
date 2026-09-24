<?php

namespace Tests\Unit;

use App\Support\DocumentPresentation;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPresentationTest extends TestCase
{
    public function test_customer_name_falls_back_when_display_name_is_empty(): void
    {
        $this->assertSame('Acme Corp', DocumentPresentation::customerName([
            'display_name' => '',
            'organization_name' => 'Acme Corp',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]));

        $this->assertSame('Ada Lovelace', DocumentPresentation::customerName([
            'display_name' => '   ',
            'organization_name' => '',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]));

        $this->assertSame('Customer', DocumentPresentation::customerName([
            'display_name' => '',
            'organization_name' => null,
            'first_name' => '',
            'last_name' => '',
        ]));

        $this->assertSame('Preferred Name', DocumentPresentation::customerName([
            'display_name' => 'Preferred Name',
            'organization_name' => 'Ignored Org',
        ]));
    }

    public function test_default_logo_is_used_when_no_custom_logo_exists(): void
    {
        Storage::fake('local');

        $default = public_path(DocumentPresentation::DEFAULT_LOGO_FILENAME);
        $this->assertFileExists($default);

        $this->assertSame($default, DocumentPresentation::resolveLogoAbsolutePath(null));
        $this->assertSame($default, DocumentPresentation::resolveLogoAbsolutePath(''));
        $this->assertSame($default, DocumentPresentation::resolveLogoAbsolutePath('branding/missing.png'));

        $uri = DocumentPresentation::logoDataUri(null);
        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/', $uri);
    }

    public function test_custom_logo_is_used_when_file_exists(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('branding/logo.png', file_get_contents(
            public_path(DocumentPresentation::DEFAULT_LOGO_FILENAME)
        ));

        $resolved = DocumentPresentation::resolveLogoAbsolutePath('branding/logo.png');
        $this->assertTrue(is_file($resolved));
        $this->assertTrue(DocumentPresentation::hasCustomLogo('branding/logo.png'));
        $this->assertNotNull(DocumentPresentation::logoDataUri('branding/logo.png'));
    }
}
