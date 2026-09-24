<?php

namespace App\Contracts;

use App\Support\AiProviderResponse;

interface AiProvider
{
    /**
     * @param  list<array{role: string, content: ?string, tool_call_id?: string, name?: string, tool_calls?: list<array<string, mixed>>}>  $messages
     * @param  list<array<string, mixed>>  $tools  OpenAI-compatible tool schemas
     */
    public function complete(array $messages, array $tools = [], array $options = []): AiProviderResponse;
}
