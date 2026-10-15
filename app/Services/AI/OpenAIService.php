<?php

namespace App\Services\AI;

use App\Contracts\AiServiceInterface;
use App\Contracts\ImageReader;
use Illuminate\Support\Facades\Http;

class OpenAIService implements AIServiceInterface, ImageReader
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Reads an image with the store's own model and key (adapted from
     * WpBotTrainer, which used a fixed vision model).
     */
    public function analyzeImage(string $base64Image, string $mimeType, string $prompt): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64Image}"]],
                    ],
                ]],
                'max_tokens' => 500,
            ]);

        if ($response->failed()) {
            throw new \Exception('OpenAI vision error (HTTP '.$response->status().')');
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    /**
     * Get a response from OpenAI API.
     *
     * @param string $userMessage The user's message
     * @param string $systemPrompt The system prompt/instructions
     * @param array $history Chat history array of ['role' => 'user|assistant', 'content' => 'message']
     * @return string The AI response message content
     */
    public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
    {
        try {
            // Build messages array: system prompt + history + current message
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ];

            // Add chat history
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }

            // Add current user message
            $messages[] = [
                'role' => 'user',
                'content' => $userMessage,
            ];

            $response = Http::withToken($this->apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                ]);

            if ($response->failed()) {
                throw new \Exception('OpenAI API error: ' . $response->body());
            }

            $data = $response->json();

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            throw new \Exception('OpenAI service error: ' . $e->getMessage());
        }
    }

    /**
     * Transcribe an audio file using OpenAI Whisper.
     *
     * @param string $filePath Local path to the audio file
     * @return string Transcribed text
     */
    public function transcribeAudio(string $filePath): string
    {
        try {
            $fileResource = fopen($filePath, 'r');
            if (!$fileResource) {
                throw new \Exception('Unable to open audio file for transcription');
            }

            $response = Http::withToken($this->apiKey)
                ->attach('file', $fileResource, basename($filePath))
                ->asMultipart()
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                ]);

            if (is_resource($fileResource)) {
                fclose($fileResource);
            }

            if ($response->failed()) {
                throw new \Exception('OpenAI transcription error: ' . $response->body());
            }

            $data = $response->json();
            return trim($data['text'] ?? '');
        } catch (\Exception $e) {
            throw new \Exception('OpenAI transcription failed: ' . $e->getMessage());
        }
    }

    /**
     * Test OpenAI API connectivity.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        if (!$this->apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get('https://api.openai.com/v1/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
