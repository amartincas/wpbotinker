<?php

namespace App\Contracts;

interface AiServiceInterface
{
    /**
     * Get a response from the AI service.
     *
     * @param string $userMessage The user's message
     * @param string $systemPrompt The system prompt/instructions
     * @param array $history Chat history array of ['role' => 'user|assistant', 'content' => 'message']
     * @param array{name: string, schema: array<string, mixed>}|null $schema The JSON schema the answer must follow, for a
     *        call that expects a structured answer; a provider without Structured Outputs relies on the prompt alone
     * @return string The AI response message content
     */
    public function getResponse(string $userMessage, string $systemPrompt, array $history = [], ?array $schema = null): string;
}
