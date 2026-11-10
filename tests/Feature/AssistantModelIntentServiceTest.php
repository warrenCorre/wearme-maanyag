<?php

namespace Tests\Feature;

use App\Services\AssistantModelIntentService;
use App\Services\AssistantStoreTurnScreen;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AssistantModelIntentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.gemini.allow_conversation' => false]);
    }

    public function test_disabled_conversation_never_forwards_a_store_turn_even_if_store_sharing_is_on(): void
    {
        config(['services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $this->assertNull($this->service($client)->suggest('Could we talk about how our sales are doing?'));
    }

    public function test_only_the_screened_current_turn_reaches_the_mocked_classifier(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(function (string $prompt, string $instruction, array $schema): bool {
                return $prompt === 'Current screened store turn: could we talk about how our sales are doing'
                    && str_contains($instruction, 'Never provide an answer')
                    && $schema['properties']['intent']['enum'][0] === 'none'
                    && ! str_contains($prompt, 'customer');
            })
            ->andReturn('{"intent":"sales_goal","period":"none"}');

        $this->assertSame('Sales?', $this->service($client)->suggest('Could we talk about how our sales are doing?'));
    }

    public function test_a_screened_time_period_must_match_the_model_suggestion(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andReturn('{"intent":"sales_summary","period":"this week"}');

        $this->assertSame('Show me sales for this week', $this->service($client)->suggest('Could we talk about how our sales are doing this week?'));
    }

    public function test_suggested_skills_are_limited_by_the_screened_topic_and_request_goal(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            ['Could we talk about our unpaid balance?', 'unpaid_summary', 'How many unpaid transactions?'],
            ['Could we talk about unpaid?', 'unpaid_goal', 'Unpaid?'],
            ['Could we talk about our low stock?', 'low_stock', 'List low-stock products'],
            ['Could we talk about our inventory?', 'inventory_summary', 'Summarize inventory status'],
            ['Could we talk about best sellers?', 'best_sellers', 'List best-selling products'],
            ['Could we talk about collections?', 'collection_dates', 'List recently collected products'],
        ] as [$question, $intent, $expected]) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()->andReturn(json_encode([
                'intent' => $intent,
                'period' => 'none',
            ], JSON_THROW_ON_ERROR));

            $this->assertSame($expected, $this->service($client)->suggest($question), $question);
        }
    }

    public function test_unsafe_or_identifying_turns_never_enter_the_model_even_with_both_gates_on(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        foreach (['Sales for customer Ana', 'Latest unpaid sale for Ana', 'List all sales', 'Sales and inventory', 'Wahat about transactions for 09171234567'] as $question) {
            $this->assertNull($this->service($client)->suggest($question), $question);
        }
    }

    public function test_malformed_mismatched_or_new_skill_suggestions_are_rejected(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            '{"intent":"latest_unpaid_sale","period":"none"}',
            '{"intent":"unpaid_summary","period":"none"}',
            '{"intent":"sales_goal","period":"this month"}',
            '{"intent":"sales_summary","period":"this week"}',
            '{"intent":"sales_goal","period":"none","customer":"Ana"}',
            '{"intent":"none","period":"none"}',
            'not json',
        ] as $output) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()->andReturn($output);

            $this->assertNull($this->service($client)->suggest('Could we talk about how our sales are doing?'), $output);
        }
    }

    public function test_provider_failure_keeps_the_decision_local(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andThrow(new ConnectionException('Synthetic timeout'));

        $this->assertNull($this->service($client)->suggest('Could we talk about how our sales are doing?'));
    }

    private function service(GeminiClient $client): AssistantModelIntentService
    {
        return new AssistantModelIntentService($client, new AssistantStoreTurnScreen);
    }
}
