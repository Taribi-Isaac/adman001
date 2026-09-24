<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BusinessKnowledgeCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBusinessKnowledgeArticleRequest;
use App\Http\Requests\Settings\StoreBusinessOfferingRequest;
use App\Http\Requests\Settings\UpdateBusinessKnowledgeArticleRequest;
use App\Http\Requests\Settings\UpdateBusinessOfferingRequest;
use App\Models\Business;
use App\Models\BusinessKnowledgeArticle;
use App\Models\BusinessOffering;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessKnowledgeController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_VIEW);

        $business = Business::current();

        return Inertia::render('settings/knowledge/Index', [
            'business' => [
                'description' => $business->description,
                'ai_support_instructions' => $business->ai_support_instructions,
            ],
            'offerings' => BusinessOffering::query()->orderBy('sort_order')->orderBy('id')->get(),
            'articles' => BusinessKnowledgeArticle::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (BusinessKnowledgeArticle $article) => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'content' => $article->content,
                    'category' => $article->category->value,
                    'category_label' => $article->category->label(),
                    'is_active' => $article->is_active,
                    'sort_order' => $article->sort_order,
                ]),
            'categoryOptions' => collect(BusinessKnowledgeCategory::cases())->map(fn (BusinessKnowledgeCategory $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
            'canManage' => auth()->user()?->can(Permissions::BUSINESS_KNOWLEDGE_MANAGE) ?? false,
            'canUpdateBusiness' => auth()->user()?->can(Permissions::BUSINESS_UPDATE) ?? false,
            'businessSettingsHref' => route('settings.business.edit', absolute: false),
        ]);
    }

    public function storeOffering(StoreBusinessOfferingRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $data = $request->validated();
        $offering = BusinessOffering::query()->create($data);

        $auditLogger->record(
            event: 'business.offering_created',
            description: 'Business offering created',
            auditable: $offering,
            newValues: $offering->only(['name', 'is_active', 'sort_order']),
        );

        return back()->with('success', 'Service/product saved.');
    }

    public function updateOffering(
        UpdateBusinessOfferingRequest $request,
        BusinessOffering $offering,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $old = $offering->only(['name', 'description', 'is_active', 'sort_order']);
        $offering->fill($request->validated())->save();

        $auditLogger->record(
            event: 'business.offering_updated',
            description: 'Business offering updated',
            auditable: $offering,
            oldValues: $old,
            newValues: $offering->only(['name', 'description', 'is_active', 'sort_order']),
        );

        return back()->with('success', 'Service/product updated.');
    }

    public function destroyOffering(BusinessOffering $offering, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $auditLogger->record(
            event: 'business.offering_deleted',
            description: 'Business offering deleted',
            auditable: $offering,
            oldValues: $offering->only(['name']),
        );
        $offering->delete();

        return back()->with('success', 'Service/product removed.');
    }

    public function storeArticle(StoreBusinessKnowledgeArticleRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $article = BusinessKnowledgeArticle::query()->create($request->validated());

        $auditLogger->record(
            event: 'business.knowledge_created',
            description: 'Business knowledge article created',
            auditable: $article,
            newValues: $article->only(['title', 'category', 'is_active']),
        );

        return back()->with('success', 'Knowledge article saved.');
    }

    public function updateArticle(
        UpdateBusinessKnowledgeArticleRequest $request,
        BusinessKnowledgeArticle $article,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $old = $article->only(['title', 'content', 'category', 'is_active', 'sort_order']);
        $article->fill($request->validated())->save();

        $auditLogger->record(
            event: 'business.knowledge_updated',
            description: 'Business knowledge article updated',
            auditable: $article,
            oldValues: $old,
            newValues: $article->only(['title', 'content', 'category', 'is_active', 'sort_order']),
        );

        return back()->with('success', 'Knowledge article updated.');
    }

    public function destroyArticle(BusinessKnowledgeArticle $article, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::BUSINESS_KNOWLEDGE_MANAGE);
        $auditLogger->record(
            event: 'business.knowledge_deleted',
            description: 'Business knowledge article deleted',
            auditable: $article,
            oldValues: $article->only(['title', 'category']),
        );
        $article->delete();

        return back()->with('success', 'Knowledge article removed.');
    }
}
