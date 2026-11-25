<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use RuntimeException;

class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    /**
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function generateText(
        string $prompt,
        ?string $systemInstruction = null,
        ?array $responseSchema = null,
        ?int $timeoutSeconds = null,
        bool $retryTransient = false,
    ): string {
        $payload = [
            'model' => config('services.gemini.model'),
            'input' => $prompt,
            'store' => false,
        ];

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = $systemInstruction;
        }

        if ($responseSchema !== null) {
            $payload['response_format'] = [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $responseSchema,
            ];
        }

        $response = $this->postInteraction($payload, $timeoutSeconds, $retryTransient);

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
     * @param  list<array<string, mixed>>  $input
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    public function interactWithTools(array $input, array $tools, string $systemInstruction, bool $allowTools = true, ?int $timeoutSeconds = null, bool $retryTransient = false): array
    {
        $payload = [
            'model' => config('services.gemini.model'),
            'input' => $input,
            'store' => false,
            'system_instruction' => $systemInstruction,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['generation_config'] = ['tool_choice' => $allowTools ? 'auto' : 'none'];
        }

        $response = $this->postInteraction($payload, $timeoutSeconds, $retryTransient);

        $steps = $response->json('steps');

        $status = $response->json('status');

        if (! in_array($status, ['completed', 'requires_action'], true)
            || ! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            throw new RuntimeException('Gemini did not complete the tool interaction.');
        }

        if ($status === 'requires_action' && ! collect($steps)->contains(fn (mixed $step): bool => is_array($step) && ($step['type'] ?? null) === 'function_call')) {
            throw new RuntimeException('Gemini requested an unknown action.');
        }

        foreach ($steps as $step) {
            if (! is_array($step) || ! is_string($step['type'] ?? null)) {
                throw new RuntimeException('Gemini returned an invalid tool step.');
            }
        }

        return $steps;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postInteraction(array $payload, ?int $timeoutSeconds, bool $retryTransient): Response
    {
        $deadline = $timeoutSeconds !== null ? hrtime(true) + $timeoutSeconds * 1_000_000_000 : null;

        try {
            return $this->request($timeoutSeconds)->post('interactions', $payload)->throw();
        } catch (RequestException|ConnectionException $exception) {
            $delayMilliseconds = $retryTransient
                ? ($exception instanceof ConnectionException ? 250 : $this->retryDelayMilliseconds($exception))
                : null;

            if ($delayMilliseconds === null || $deadline === null
                || $deadline - hrtime(true) < ($delayMilliseconds + 2000) * 1_000_000) {
                throw $exception;
            }

            Sleep::for($delayMilliseconds)->milliseconds();
            $remainingSeconds = intdiv($deadline - hrtime(true), 1_000_000_000);

            if ($remainingSeconds < 2) {
                throw $exception;
            }

            return $this->request($remainingSeconds)->post('interactions', $payload)->throw();
        }
    }

    private function retryDelayMilliseconds(RequestException $exception): ?int
    {
        $status = $exception->response->status();

        if (in_array($status, [500, 502, 503, 504], true)) {
            return 250;
        }

        if ($status !== 429) {
            return null;
        }

        $retryAfter = $exception->response->header('Retry-After');

        if (! is_string($retryAfter) || ! ctype_digit($retryAfter) || (int) $retryAfter > 1) {
            return null;
        }

        return max(250, (int) $retryAfter * 1000);
    }

    private function request(?int $timeoutSeconds = null): PendingRequest
    {
        $apiKey = config('services.gemini.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new LogicException('The Gemini API key is not configured.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->withHeader('x-goog-api-key', $apiKey)
            ->timeout($timeoutSeconds ?? 30);
    }
}
