<?php

namespace Tests\Feature;

use App\Services\AssistantAnswerService;
use App\Services\AssistantContextService;
use App\Services\AssistantPromptBuilder;
use App\Services\AssistantQuestionPolicy;
use App\Services\GeminiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AssistantAnswerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_does_not_read_store_context_or_call_gemini_when_data_sharing_is_disabled(): void
    {
        config(['services.gemini.allow_store_data' => false]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldNotReceive('build');

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldNotReceive('generateText');

        $service = $this->makeAnswerService($contextService, $geminiClient);
        $result = $service->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('disabled', $result['status']);
        $this->assertStringContainsString('explicitly approved', $result['message']);
    }

    public function test_it_rejects_unsupported_questions_before_store_queries_or_gemini_calls(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldNotReceive('build');

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldNotReceive('generateText');

        $service = $this->makeAnswerService($contextService, $geminiClient);
        $result = $service->answer(
            'What is the unpaid balance for Customer Ana?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('Customer-specific', $result['message']);
    }

    public function test_it_sends_only_normalized_question_and_intent_relevant_synthetic_context_to_the_mocked_client(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->with('monthly', Mockery::on(
                fn (CarbonImmutable $date): bool => $date->toDateString() === '2026-11-01',
            ))
            ->andReturn([
                'sales' => [
                    'period' => 'monthly',
                    'date' => '2026-11-01',
                    'summary' => [
                        'transaction_count' => 4,
                        'total_sales' => 125.0,
                        'average_sale' => 31.25,
                    ],
                ],
                'inventory' => [
                    'stats' => ['product_count' => 1, 'total_units' => 2, 'stock_value' => 30.0],
                    'low_stock_products' => [],
                ],
                'best_sellers' => [],
                'unpaid_summary' => ['pending_count' => 3, 'outstanding_balance' => 90.0],
            ]);

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt, string $systemInstruction) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return str_contains($prompt, 'Summarize monthly sales for 2026-11-01')
                    && str_contains($prompt, '125')
                    && ! str_contains($prompt, 'outstanding_balance')
                    && str_contains($systemInstruction, 'calculated figures are authoritative');
            })
            ->andReturn('Synthetic sales summary.');

        $service = $this->makeAnswerService($contextService, $geminiClient);
        $result = $service->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('Synthetic sales summary.', $result['message']);
        $this->assertStringNotContainsString('Show me sales', $capturedPrompt);
    }

    private function makeAnswerService(
        AssistantContextService $contextService,
        GeminiClient $geminiClient,
    ): AssistantAnswerService {
        return new AssistantAnswerService(
            new AssistantQuestionPolicy,
            $contextService,
            new AssistantPromptBuilder,
            $geminiClient,
        );
    }
}
