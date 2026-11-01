<?php

namespace Tests\Feature;

use App\Services\AssistantAnswerService;
use App\Services\AssistantContextService;
use App\Services\AssistantPromptBuilder;
use App\Services\AssistantQuestionPolicy;
use App\Services\AssistantResponseValidator;
use App\Services\GeminiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
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
            ->andReturn($this->salesContext());

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt, string $systemInstruction, array $schema) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return str_contains($prompt, 'Summarize monthly sales for 2026-11-01')
                    && str_contains($prompt, '125')
                    && str_contains($prompt, 'All recorded sales transactions, including paid, unpaid, and partially paid.')
                    && ! str_contains($prompt, 'outstanding_balance')
                    && ! str_contains($prompt, 'Paid sales transactions only')
                    && ! str_contains($prompt, 'Pending unpaid payment records')
                    && str_contains($systemInstruction, 'calculated figures are authoritative')
                    && $schema === AssistantResponseValidator::RESPONSE_SCHEMA;
            })
            ->andReturn($this->structuredResponse(
                'Monthly sales for 2026-11-01 totaled PHP 125.00 across 4 transactions.',
                'calculation',
                [['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-01']],
            ));

        $service = $this->makeAnswerService($contextService, $geminiClient);
        $result = $service->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('Monthly sales for 2026-11-01 totaled PHP 125.00 across 4 transactions.', $result['message']);
        $this->assertSame([
            ['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-01'],
        ], $result['sources']);
        $this->assertSame('calculation', $result['answer_type']);
        $this->assertSame('high', $result['confidence']);
        $this->assertStringNotContainsString('Show me sales', $capturedPrompt);
    }

    public function test_it_distinguishes_linked_transactions_from_payment_records_in_the_unpaid_prompt(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'unpaid_summary' => [
                    'pending_transaction_count' => 1,
                    'pending_record_count' => 2,
                    'outstanding_balance' => 25.0,
                ],
                'scopes' => [
                    'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                    'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                    'unpaid_summary' => 'Pending unpaid payment records; linked transaction count is distinct from payment-record count.',
                ],
                'as_of' => $this->asOf(),
                'inventory' => ['low_stock_products' => [['product_name' => 'Synthetic Product']]],
            ]);

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt, string $systemInstruction, array $schema) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return str_contains($prompt, 'linked pending unpaid transaction counts')
                    && str_contains($prompt, 'pending unpaid payment record counts')
                    && str_contains($prompt, 'Pending unpaid payment records; linked transaction count is distinct from payment-record count.')
                    && ! str_contains($prompt, 'All recorded sales transactions')
                    && ! str_contains($prompt, 'Paid sales transactions only')
                    && str_contains($systemInstruction, 'linked transactions')
                    && $schema === AssistantResponseValidator::RESPONSE_SCHEMA;
            })
            ->andReturn($this->structuredResponse(
                'There is 1 linked pending unpaid transaction and 2 pending payment records, with an outstanding balance of PHP 25.00.',
                'calculation',
                [['type' => 'unpaid_summary', 'label' => 'Unpaid summary', 'as_of' => '2026-11-02']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'How many unpaid transactions?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('There is 1 linked pending unpaid transaction and 2 pending payment records, with an outstanding balance of PHP 25.00.', $result['message']);
        $this->assertStringContainsString('"pending_transaction_count": 1', $capturedPrompt);
        $this->assertStringContainsString('"pending_record_count": 2', $capturedPrompt);
        $this->assertStringNotContainsString('Synthetic Product', $capturedPrompt);
        $this->assertStringNotContainsString('How many unpaid transactions?', $capturedPrompt);
    }

    public function test_it_passes_only_the_best_sellers_scope_to_the_best_sellers_prompt(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'best_sellers' => [
                    ['product_name' => 'Synthetic Jacket', 'units_sold' => 3, 'sales_total' => 30.0],
                ],
                'scopes' => [
                    'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                    'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                    'unpaid_summary' => 'Pending unpaid payment records; linked transaction count is distinct from payment-record count.',
                ],
                'as_of' => $this->asOf(),
            ]);

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt, string $systemInstruction, array $schema) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return str_contains($prompt, 'Paid sales transactions only, all-time; not limited to the selected sales period.')
                    && str_contains($prompt, 'Synthetic Jacket')
                    && ! str_contains($prompt, 'All recorded sales transactions')
                    && ! str_contains($prompt, 'Pending unpaid payment records')
                    && str_contains($systemInstruction, 'best sellers use paid transactions only and are all-time')
                    && $schema === AssistantResponseValidator::RESPONSE_SCHEMA;
            })
            ->andReturn($this->structuredResponse(
                'Synthetic Jacket leads with 3 paid units and PHP 30.00 in paid sales.',
                'summary',
                [['type' => 'best_sellers', 'label' => 'Best sellers', 'as_of' => '2026-11-02']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'List best-selling products',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('Synthetic Jacket leads with 3 paid units and PHP 30.00 in paid sales.', $result['message']);
        $this->assertStringContainsString('Paid sales transactions only', $capturedPrompt);
        $this->assertStringNotContainsString('All recorded sales transactions', $capturedPrompt);
        $this->assertStringNotContainsString('Pending unpaid payment records', $capturedPrompt);
    }

    public function test_it_rejects_invalid_structured_output_without_returning_raw_text(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn($this->salesContext());

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->andReturn('not-json');

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('The assistant could not produce a verified answer. Please try again.', $result['message']);
        $this->assertStringNotContainsString('not-json', $result['message']);
    }

    public function test_it_returns_a_validated_refusal_when_the_model_refuses(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn($this->salesContext());

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->andReturn($this->structuredResponse(
                'The verified context does not contain that information.',
                'refusal',
                [],
                refusal: true,
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('refused', $result['status']);
        $this->assertSame('The verified context does not contain that information.', $result['message']);
        $this->assertSame([], $result['sources']);
    }

    public function test_it_returns_unavailable_when_gemini_fails(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn($this->salesContext());

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->andThrow(new RuntimeException('Synthetic transport failure.'));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'Show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('The assistant is temporarily unavailable. Please try again.', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesContext(): array
    {
        return [
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
            'unpaid_summary' => ['pending_transaction_count' => 2, 'pending_record_count' => 3, 'outstanding_balance' => 90.0],
            'scopes' => [
                'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                'unpaid_summary' => 'Pending unpaid payment records; linked transaction count is distinct from payment-record count.',
            ],
            'as_of' => $this->asOf(),
        ];
    }

    /**
     * @return array{sales: string, inventory: string, best_sellers: string, unpaid_summary: string}
     */
    private function asOf(): array
    {
        return [
            'sales' => '2026-11-01',
            'inventory' => '2026-11-02',
            'best_sellers' => '2026-11-02',
            'unpaid_summary' => '2026-11-02',
        ];
    }

    /**
     * @param  array<int, array{type: string, label: string, as_of: string}>  $sources
     */
    private function structuredResponse(
        string $answer,
        string $answerType,
        array $sources,
        string $confidence = 'high',
        bool $refusal = false,
    ): string {
        return json_encode([
            'answer' => $answer,
            'answer_type' => $answerType,
            'sources' => $sources,
            'confidence' => $confidence,
            'refusal' => $refusal,
        ], JSON_THROW_ON_ERROR);
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
            new AssistantResponseValidator,
        );
    }
}
