<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'documents/quote_pdf/quotes/'.Str::uuid().'.pdf';

        return [
            'type' => DocumentType::QuotePdf,
            'documentable_type' => (new Quote)->getMorphClass(),
            'documentable_id' => Quote::factory(),
            'disk' => 'local',
            'path' => $path,
            'filename' => 'QT-0001.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1024,
            'access_token_hash' => null,
            'access_expires_at' => null,
            'access_revoked_at' => null,
            'generated_by' => null,
            'generated_at' => now(),
            'meta' => ['number' => 'QT-0001'],
        ];
    }

    public function withSecureLink(): static
    {
        return $this->state(fn () => [
            'access_token_hash' => hash('sha256', Str::random(64)),
            'access_expires_at' => now()->addDays(90),
            'access_revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->withSecureLink()->state(fn () => [
            'access_revoked_at' => now(),
        ]);
    }
}
