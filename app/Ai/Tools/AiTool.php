<?php

namespace App\Ai\Tools;

use App\Models\Conversation;
use App\Models\Message;

interface AiTool
{
    public function name(): string;

    public function description(): string;

    /**
     * OpenAI-compatible JSON schema for parameters.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(Conversation $conversation, Message $inbound, array $arguments): array;
}
