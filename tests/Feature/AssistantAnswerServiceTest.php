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

    public function test_it_uses_only_aggregate_sale_balances_in_the_unpaid_prompt(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'unpaid_summary' => [
                    'pending_transaction_count' => 1,
                    'outstanding_balance' => 25.0,
                ],
                'scopes' => [
                    'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                    'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                    'unpaid_summary' => 'One pending balance per sale; excludes settled balances.',
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

                return str_contains($prompt, 'aggregate pending sale counts')
                    && str_contains($prompt, 'One pending balance per sale; excludes settled balances.')
                    && ! str_contains($prompt, 'All recorded sales transactions')
                    && ! str_contains($prompt, 'Paid sales transactions only')
                    && str_contains($systemInstruction, 'Each pending balance belongs to exactly one sale')
                    && $schema === AssistantResponseValidator::RESPONSE_SCHEMA;
            })
            ->andReturn($this->structuredResponse(
                'There is 1 pending sale with an outstanding balance of PHP 25.00.',
                'calculation',
                [['type' => 'unpaid_summary', 'label' => 'Unpaid summary', 'as_of' => '2026-11-02']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'How many unpaid transactions?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('There is 1 pending sale with an outstanding balance of PHP 25.00.', $result['message']);
        $this->assertStringContainsString('"pending_transaction_count": 1', $capturedPrompt);
        $this->assertStringNotContainsString('pending_record_count', $capturedPrompt);
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
                    'unpaid_summary' => 'One pending balance per sale; excludes settled balances.',
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
                    && ! str_contains($prompt, 'One pending balance per sale')
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
        $this->assertStringNotContainsString('One pending balance per sale', $capturedPrompt);
    }

    public function test_it_keeps_collection_dates_behind_the_disabled_store_data_gate(): void
    {
        config(['services.gemini.allow_store_data' => false]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldNotReceive('build');

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldNotReceive('generateText');

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'List recently collected products',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('disabled', $result['status']);
    }

    public function test_local_social_replies_need_no_store_context_or_gemini_with_sharing_off_or_on(): void
    {
        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldNotReceive('build');

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldNotReceive('generateText');

        $service = $this->makeAnswerService($contextService, $geminiClient);

        foreach ([false, true] as $sharingEnabled) {
            config(['services.gemini.allow_store_data' => $sharingEnabled]);

            foreach ([
                'Hi!' => 'Hi! I am Maanyag.',
                'Hello Maanyag' => 'Hello! Nice to hear from you.',
                'Hey there' => 'Hey! I am here',
                'Good evening Maanyag' => 'Good evening!',
                'Thank you!' => 'You are welcome!',
                'How are you?' => 'Thanks for checking in!',
                'Tell me a joke' => 'A little store-themed joke:',
            ] as $question => $expectedOpening) {
                $result = $service->answer($question, CarbonImmutable::parse('2026-11-07'));

                $this->assertSame('social', $result['status'], $question);
                $this->assertStringStartsWith($expectedOpening, $result['message'], $question);
                $this->assertArrayNotHasKey('sources', $result, $question);
                $this->assertArrayNotHasKey('visit_context', $result, $question);
            }
        }
    }

    public function test_mixed_greeting_with_a_store_question_remains_behind_the_sharing_gate(): void
    {
        config(['services.gemini.allow_store_data' => false]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldNotReceive('build');

        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldNotReceive('generateText');

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'Hi, show me sales for this month?',
            CarbonImmutable::parse('2026-11-07'),
        );

        $this->assertSame('disabled', $result['status']);
    }

    public function test_mixed_greeting_never_enters_a_mocked_provider_prompt_when_sharing_is_enabled(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')->once()->andReturn($this->salesContext());

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return true;
            })
            ->andReturn($this->structuredResponse(
                'Monthly sales totaled PHP 125.00 across 4 transactions.',
                'calculation',
                [['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-01']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'Hi Maanyag, show me sales for this month?',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertStringContainsString('Summarize monthly sales', $capturedPrompt);
        $this->assertStringNotContainsString('Hi Maanyag', $capturedPrompt);
    }

    public function test_it_sends_only_collection_dates_and_their_scope_to_the_mocked_client(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'collection_dates' => [
                    ['product_name' => 'Synthetic Scarf', 'collection_date' => '2026-10-05'],
                ],
                'as_of' => ['collection_dates' => '2026-11-02', 'sales' => '2026-11-01'],
                'scopes' => [
                    'collection_dates' => 'Up to 10 active products with recorded collection dates, newest first; excludes archived products and unknown dates.',
                    'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                ],
                'sales' => ['summary' => ['total_sales' => 999.0]],
                'unpaid_summary' => ['customer_name' => 'Synthetic Customer'],
            ]);

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt, string $systemInstruction, array $schema) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return str_contains($prompt, 'Synthetic Scarf')
                    && str_contains($prompt, '2026-10-05')
                    && str_contains($prompt, 'excludes archived products and unknown dates')
                    && ! str_contains($prompt, '999')
                    && ! str_contains($prompt, 'Synthetic Customer')
                    && str_contains($systemInstruction, 'Collection dates belong to active products')
                    && $schema === AssistantResponseValidator::RESPONSE_SCHEMA;
            })
            ->andReturn($this->structuredResponse(
                'Synthetic Scarf was collected on 2026-10-05.',
                'fact',
                [['type' => 'collection_dates', 'label' => 'Collection dates', 'as_of' => '2026-11-02']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'List recently collected products',
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame('Synthetic Scarf was collected on 2026-10-05.', $result['message']);
        $this->assertSame([
            ['type' => 'collection_dates', 'label' => 'Collection dates', 'as_of' => '2026-11-02'],
        ], $result['sources']);
        $this->assertStringNotContainsString('List recently collected products', $capturedPrompt);
        $this->assertStringNotContainsString('total_sales', $capturedPrompt);
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

    public function test_it_uses_current_inventory_facts_for_a_cross_skill_follow_up_without_sending_history(): void
    {
        config(['services.gemini.allow_store_data' => true]);

        $contextService = Mockery::mock(AssistantContextService::class);
        $contextService->shouldReceive('build')->once()->andReturn($this->salesContext());

        $capturedPrompt = '';
        $geminiClient = Mockery::mock(GeminiClient::class);
        $geminiClient->shouldReceive('generateText')
            ->once()
            ->withArgs(function (string $prompt) use (&$capturedPrompt): bool {
                $capturedPrompt = $prompt;

                return true;
            })
            ->andReturn($this->structuredResponse(
                'There are 2 units in current stock.',
                'fact',
                [['type' => 'inventory', 'label' => 'Inventory summary', 'as_of' => '2026-11-02']],
            ));

        $result = $this->makeAnswerService($contextService, $geminiClient)->answer(
            'What about inventory?',
            CarbonImmutable::parse('2026-11-07'),
            ['intent' => 'sales_summary', 'history' => 'Synthetic customer private conversation'],
        );

        $this->assertSame('answered', $result['status']);
        $this->assertSame(['intent' => 'inventory_summary'], $result['visit_context']);
        $this->assertStringContainsString('"total_units": 2', $capturedPrompt);
        $this->assertStringNotContainsString('Synthetic customer', $capturedPrompt);
        $this->assertStringNotContainsString('What about inventory?', $capturedPrompt);
        $this->assertStringNotContainsString('transaction_count', $capturedPrompt);
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
            'unpaid_summary' => ['pending_transaction_count' => 2, 'outstanding_balance' => 90.0],
            'scopes' => [
                'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                'unpaid_summary' => 'One pending balance per sale; excludes settled balances.',
            ],
            'as_of' => $this->asOf(),
        ];
    }

    /**
     * @return array{sales: string, inventory: string, best_sellers: string, collection_dates: string, unpaid_summary: string}
     */
    private function asOf(): array
    {
        return [
            'sales' => '2026-11-01',
            'inventory' => '2026-11-02',
            'best_sellers' => '2026-11-02',
            'collection_dates' => '2026-11-02',
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
