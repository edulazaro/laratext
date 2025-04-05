<?php

namespace EduLazaro\Laratext\Translators;

use Illuminate\Support\Facades\Http;
use EduLazaro\Laratext\Contracts\TranslatorInterface;
use RuntimeException;

class OpenAITranslator implements TranslatorInterface
{
    public function translate(string $text, string $from, array $to): array
    {
        $apiKey = config('texts.openai.api_key');
        $model = config('texts.openai.model', 'gpt-3.5-turbo');
        $timeout = config('texts.openai.timeout', 10);
        $maxRetries = config('texts.openai.retries', 3);
    
        // Build instructions for multiple languages
        $languagesList = implode(', ', $to);
    
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])
        ->timeout($timeout)
        ->retry($maxRetries, 1000)
        ->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a helpful assistant that translates from {$from} into multiple languages: {$languagesList}. Reply with a JSON object, where each property is the language code and the value is the translated text. Preserve placeholders like :name, :count, or any text wrapped in colons (:) exactly as they are.",
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
            'temperature' => 0,
        ]);
    
        $rawContent = trim($response->json('choices.0.message.content', '{}'));
    
        // Attempt to decode JSON
        $translations = json_decode($rawContent, true);
    
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) {
            throw new RuntimeException("Failed to decode translation response: " . $rawContent);
        }
    
        return $translations;
    }    
}
