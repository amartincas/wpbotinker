<?php

namespace App\Contracts;

/**
 * An AI provider that can read an image (vision). Implemented by the
 * providers that support it with the store's own key and model; see
 * App\Factories\AIServiceFactory::makeImageReader().
 */
interface ImageReader
{
    /**
     * @return string the model's raw text answer
     *
     * @throws \Exception on any provider error
     */
    public function analyzeImage(string $base64Image, string $mimeType, string $prompt): string;
}
