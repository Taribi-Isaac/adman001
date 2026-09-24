<?php

namespace App\Models;

use App\Enums\BusinessKnowledgeCategory;
use Database\Factories\BusinessKnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $content
 * @property BusinessKnowledgeCategory $category
 * @property bool $is_active
 * @property int $sort_order
 */
class BusinessKnowledgeArticle extends Model
{
    /** @use HasFactory<BusinessKnowledgeArticleFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'category',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BusinessKnowledgeCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
