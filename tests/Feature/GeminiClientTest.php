<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Support\FakeGeminiClient;
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

    public function test_it_sends_a_structured_response_format_when_a_schema_is_supplied(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'status' => 'completed',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            ['type' => 'text', 'text' => '{"answer":"Synthetic answer."}'],
                        ],
                    ],
                ],
            ]),
        ]);

        $schema = [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
            ],
            'required' => ['answer'],
        ];

        $answer = app(GeminiClient::class)->generateText(
            'Summarize synthetic inventory.',
            'Use only supplied facts.',
            $schema,
        );

        $this->assertSame('{"answer":"Synthetic answer."}', $answer);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request['response_format']['type'] === 'text'
            && $request['response_format']['mime_type'] === 'application/json'
            && $request['response_format']['schema'] === $schema
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

    public function test_local_opt_in_uses_only_a_verified_sales_context_without_http_requests(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['services.gemini.demo_fake' => true]);
        (new AppServiceProvider($this->app))->register();
        $context = [
            'sales' => ['period' => 'daily', 'date' => '2026-11-14', 'summary' => [
                'transaction_count' => 2, 'total_sales' => 350.0,
            ]],
            'allowed_sources' => [['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-14']],
        ];

        $client = app(GeminiClient::class);
        $reply = $client->generateText("Verified system context (JSON):\n".json_encode($context), null, [
            'properties' => ['answer' => ['type' => 'string']],
        ]);
        $style = $client->generateText('Current safe topic: clarification / sales_goal.', null, [
            'properties' => ['style' => ['type' => 'string']],
        ]);

        $this->assertInstanceOf(FakeGeminiClient::class, $client);
        $this->assertSame([
            'answer' => 'Recorded sales for daily on 2026-11-14: 2 transactions totaling PHP 350.',
            'answer_type' => 'fact',
            'sources' => $context['allowed_sources'],
            'confidence' => 'high',
            'refusal' => false,
        ], json_decode($reply, true));
        $this->assertSame('{"style":"warm","suggested_skill":"none"}', $style);
        Http::assertNothingSent();
    }

    public function test_demo_fake_is_not_bound_without_local_opt_in(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['services.gemini.demo_fake' => false]);
        (new AppServiceProvider($this->app))->register();

        $this->assertSame(GeminiClient::class, get_class(app(GeminiClient::class)));
        Http::assertNothingSent();
    }

    public function test_demo_fake_fails_closed_outside_local_instead_of_using_live_client(): void
    {
        config(['services.gemini.demo_fake' => true]);

        try {
            (new AppServiceProvider($this->app))->register();
            $this->fail('Expected DEMO fake startup to be blocked outside local.');
        } catch (LogicException $exception) {
            $this->assertSame('The local Assistant demo fake cannot be started. No provider request was made.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_local_demo_fake_rejects_unsupported_provider_requests_without_http(): void
    {
        $fake = new FakeGeminiClient;

        try {
            $fake->generateText('Unsupported DEMO request.', null, ['properties' => ['intent' => ['type' => 'string']]]);
            $this->fail('Expected unsupported request to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The DEMO fake supports only sales answers and clarification styles.', $exception->getMessage());
        }

        try {
            $fake->embedContent('Fictional embedding.');
            $this->fail('Expected embedding request to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The DEMO fake never generates embeddings.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
