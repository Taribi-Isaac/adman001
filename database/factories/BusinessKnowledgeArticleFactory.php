<?php

namespace Database\Factories;

use App\Enums\BusinessKnowledgeCategory;
use App\Models\BusinessKnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessKnowledgeArticle>
 */
class BusinessKnowledgeArticleFactory extends Factory
{
    protected $model = BusinessKnowledgeArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'content' => fake()->paragraph(),
            'category' => BusinessKnowledgeCategory::Faq,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function policy(): static
    {
        return $this->state(fn () => ['category' => BusinessKnowledgeCategory::Policy]);
    }
}
