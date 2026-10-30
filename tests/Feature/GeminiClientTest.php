<?php

namespace Tests\Feature;

use App\Services\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.embedding_model' => 'gemini-embedding-001',
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_sends_text_generation_request_and_returns_model_output(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'status' => 'completed',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            ['type' => 'text', 'text' => 'Synthetic answer.'],
                        ],
                    ],
                ],
            ]),
        ]);

        $answer = app(GeminiClient::class)->generateText(
            'Summarize synthetic inventory.',
            'Use only supplied facts.',
        );

        $this->assertSame('Synthetic answer.', $answer);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && $request['model'] === 'gemini-3.5-flash-lite'
            && $request['input'] === 'Summarize synthetic inventory.'
            && $request['system_instruction'] === 'Use only supplied facts.'
            && $request['store'] === false
        );
    }

    public function test_it_sends_embedding_request_and_returns_vector_values(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response([
                'embedding' => ['values' => [0.125, -0.25, 0.5]],
            ]),
        ]);

        $embedding = app(GeminiClient::class)->embedContent('Synthetic product description.');

        $this->assertSame([0.125, -0.25, 0.5], $embedding);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && $request['content']['parts'][0]['text'] === 'Synthetic product description.'
            && $request['embedContentConfig']['taskType'] === 'RETRIEVAL_DOCUMENT'
        );
    }

    public function test_it_rejects_requests_when_the_api_key_is_not_configured(): void
    {
        config(['services.gemini.api_key' => '']);

        try {
            app(GeminiClient::class)->generateText('Synthetic prompt.');
            $this->fail('Expected missing API key to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('The Gemini API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
