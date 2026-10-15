<?php

namespace App\Factories;

use App\Contracts\AiServiceInterface;
use App\Contracts\ImageReader;
use App\Models\Store;
use App\Services\AI\GeminiService;
use App\Services\AI\GrokService;
use App\Services\AI\OpenAIService;
use InvalidArgumentException;

class AIServiceFactory
{
    /**
     * Models enabled for a provider: config('ai.models'), the same list the
     * panel offers. The single source of truth for both.
     *
     * @return list<string>
     */
    public static function enabledModels(?string $provider): array
    {
        return array_values(config("ai.models.{$provider}", []));
    }

    /**
     * Create an AI service instance based on the store's configuration.
     *
     * @param Store $store
     * @return AiServiceInterface
     * @throws \Exception
     */
    public static function make(Store $store): AiServiceInterface
    {
        $provider = $store->ai_provider;
        $model = $store->ai_model;
        $apiKey = $store->ai_api_key;

        // Check if API key is missing
        if (!$apiKey) {
            throw new \Exception("Missing API Key for store: {$store->name}");
        }

        // A model the configuration does not enable is an error, never
        // silently replaced by another one.
        if (! in_array($model, self::enabledModels($provider), true)) {
            throw new InvalidArgumentException("AI model [{$model}] is not enabled for provider [{$provider}] (store #{$store->id}). Choose one of config('ai.models.{$provider}').");
        }

        return match ($provider) {
            'openai' => new OpenAIService($apiKey, $model),
            'grok' => new GrokService($apiKey, $model),
            'gemini' => new GeminiService($apiKey, $model),
            default => throw new \Exception("Unsupported AI provider: {$provider}"),
        };
    }

    /**
     * Image reading (receipts) for the store, on its own provider, key and
     * enabled model; null when that provider cannot read images here
     * (Grok), so the image goes nowhere else.
     *
     * @throws InvalidArgumentException when the store's model is not enabled
     */
    public static function makeImageReader(Store $store): ?ImageReader
    {
        $service = self::make($store);

        return $service instanceof ImageReader ? $service : null;
    }

    /**
     * Speech-to-text for the store, on its OWN provider with its own key, or
     * null when that provider has no transcription here — then the key is
     * sent nowhere. Today only OpenAI (Whisper) transcribes; Gemini and Grok
     * stores get null, so a Gemini or xAI key never reaches OpenAI.
     */
    public static function makeTranscriber(Store $store): ?OpenAIService
    {
        if ($store->ai_provider !== 'openai' || ! $store->ai_api_key) {
            return null;
        }

        return new OpenAIService($store->ai_api_key, 'whisper-1');
    }
}
