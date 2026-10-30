<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    public function generateText(string $prompt, ?string $systemInstruction = null): string
    {
        $payload = [
            'model' => config('services.gemini.model'),
            'input' => $prompt,
            'store' => false,
        ];

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = $systemInstruction;
        }

        $response = $this->request()->post('interactions', $payload)->throw();

        if ($response->json('status') !== 'completed') {
            throw new RuntimeException('Gemini did not complete the text interaction.');
        }

        $text = collect($response->json('steps', []))
            ->where('type', 'model_output')
            ->flatMap(fn (array $step): array => $step['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        if ($text === '') {
            throw new RuntimeException('Gemini returned no text output.');
        }

        return $text;
    }

    /**
     * @return array<int, float>
     */
    public function embedContent(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $model = config('services.gemini.embedding_model');
        $response = $this->request()
            ->post("models/{$model}:embedContent", [
                'content' => [
                    'parts' => [
                        ['text' => $text],
                    ],
                ],
                'embedContentConfig' => [
                    'taskType' => $taskType,
                ],
            ])
            ->throw();

        $values = $response->json('embedding.values');

        if (! is_array($values) || $values === []) {
            throw new RuntimeException('Gemini returned no embedding values.');
        }

        return $values;
    }

    private function request(): PendingRequest
    {
        $apiKey = config('services.gemini.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new LogicException('The Gemini API key is not configured.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->withHeader('x-goog-api-key', $apiKey)
            ->timeout(30);
    }
}
