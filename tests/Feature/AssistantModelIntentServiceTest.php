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

    public function test_unfamiliar_safe_descriptors_reach_only_the_approved_classifier_catalogue(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);

        foreach ([
            ['How are our sales performing last month?', 'how are our sales descriptor last month', 'sales_summary', 'last month', 'Show me sales for last month'],
            ['How is our inventory tracking?', 'how is our inventory descriptor', 'inventory_summary', 'none', 'Summarize inventory status'],
            ['Which items are running critically low?', 'which items are running descriptor low', 'low_stock', 'none', 'List low-stock products'],
            ['How are our unpaid balances trending?', 'how are our unpaid balances descriptor', 'unpaid_summary', 'none', 'How many unpaid transactions?'],
            ['How are best sellers performing?', 'how are best sellers descriptor', 'best_sellers', 'none', 'List best-selling products'],
            ['How are collections progressing?', 'how are collections descriptor', 'collection_dates', 'none', 'List recently collected products'],
        ] as [$question, $turn, $intent, $period, $canonical]) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt, string $instruction, array $schema): bool => $prompt === 'Current screened store turn: '.$turn
                    && str_contains($instruction, 'sales_summary')
                    && str_contains($instruction, 'collection_dates')
                    && str_contains($instruction, 'No direct database or SQL access')
                    && in_array($intent, $schema['properties']['intent']['enum'], true))
                ->andReturn(json_encode(['intent' => $intent, 'period' => $period], JSON_THROW_ON_ERROR));

            $this->assertSame($canonical, $this->service($client)->suggest($question), $question);
        }

        Http::assertNothingSent();
    }

    public function test_a_new_descriptive_frame_reaches_the_classifier_only_after_redaction(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt, string $instruction, array $schema): bool => $prompt === 'Current screened store turn: how did our sales descriptor last month'
                && ! str_contains($prompt, 'fare')
                && in_array('sales_summary', $schema['properties']['intent']['enum'], true))
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertSame('Show me sales for last month', $this->service($client)->suggest('How did our sales fare last month?'));
        Http::assertNothingSent();
    }

    public function test_a_new_descriptive_frame_stays_local_without_conversation_consent(): void
    {
        config(['services.gemini.allow_conversation' => false, 'services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $this->assertNull($this->service($client)->suggest('How did our sales fare last month?'));
        Http::assertNothingSent();
    }

    public function test_new_inventory_and_ranking_frames_still_require_matching_approved_skills(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            ['What is the overall inventory picture?', 'what is the descriptor inventory descriptor', 'inventory_summary', 'Summarize inventory status'],
            ['How do best sellers compare?', 'how do best sellers descriptor', 'best_sellers', 'List best-selling products'],
            ['What is the collection outlook?', 'what is the collection descriptor', 'collection_dates', 'List recently collected products'],
        ] as [$question, $turn, $intent, $expected]) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: '.$turn)
                ->andReturn(json_encode(['intent' => $intent, 'period' => 'none'], JSON_THROW_ON_ERROR));

            $this->assertSame($expected, $this->service($client)->suggest($question));
        }

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andReturn('{"intent":"unpaid_summary","period":"none"}');
        $this->assertNull($this->service($client)->suggest('What is the overall inventory picture?'));
        Http::assertNothingSent();
    }

    public function test_unknown_identifiers_and_filters_still_make_zero_provider_calls(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        foreach (['How did our sales Ana last month?', 'How did our sales ana last month?', 'How did our sales ABCD last month?', 'How did our sales demoBuyer last month?', 'How did our sales fare for ana last month?', 'How did our sales of jackets fare last month?', 'What is the inventory picture for customer Ana?', 'How do best sellers compare with DEMO-JACKET?'] as $question) {
            $this->assertNull($this->service($client)->suggest($question), $question);
        }

        Http::assertNothingSent();
    }

    public function test_screened_previous_sales_periods_only_route_matching_model_choices(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach (['last week', 'last month', 'last year'] as $period) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt, string $instruction, array $schema): bool => $prompt === 'Current screened store turn: could we talk about how our sales are doing '.$period
                    && in_array($period, $schema['properties']['period']['enum'], true))
                ->andReturn(json_encode(['intent' => 'sales_summary', 'period' => $period], JSON_THROW_ON_ERROR));

            $this->assertSame('Show me sales for '.$period, $this->service($client)->suggest('Could we talk about how our sales are doing '.$period.'?'));
        }

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andReturn('{"intent":"sales_summary","period":"last year"}');
        $this->assertNull($this->service($client)->suggest('Could we talk about how our sales are doing last month?'));
    }

    public function test_prior_month_conversational_wording_is_screened_to_the_existing_last_month_choice(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: could we talk about how our sales are doing last month')
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertSame('Show me sales for last month', $this->service($client)->suggest('Could we talk about how our sales are doing prior month?'));
    }

    public function test_natural_sales_phrasings_use_only_a_screened_period_and_a_matching_model_choice(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            'How did our sales go last month?' => ['how did our sales go last month', 'last month'],
            'How were our sales looking this week?' => ['how were our sales looking this week', 'this week'],
        ] as $question => [$turn, $period]) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: '.$turn)
                ->andReturn(json_encode(['intent' => 'sales_summary', 'period' => $period], JSON_THROW_ON_ERROR));

            $this->assertSame('Show me sales for '.$period, $this->service($client)->suggest($question));
        }
    }

    public function test_greeting_is_removed_before_classifying_the_screened_current_store_turn(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: how did our sales go last month')
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertSame('Show me sales for last month', $this->service($client)->suggest('Hi, how did our sales go last month?'));
        Http::assertNothingSent();
    }

    public function test_a_greeted_known_sales_question_still_requires_a_matching_model_period(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: show me sales for this month')
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertNull($this->service($client)->suggest('Hello, show me sales for this month?'));
        Http::assertNothingSent();
    }

    public function test_low_stock_items_paraphrase_is_classified_from_only_the_screened_current_turn(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: which items are running low')
            ->andReturn('{"intent":"low_stock","period":"none"}');

        $this->assertSame('List low-stock products', $this->service($client)->suggest('Which items are running low?'));
        Http::assertNothingSent();
    }

    public function test_low_stock_items_cannot_be_reclassified_as_broader_inventory_or_sales(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach (['{"intent":"inventory_summary","period":"none"}', '{"intent":"sales_summary","period":"this month"}'] as $output) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()->andReturn($output);

            $this->assertNull($this->service($client)->suggest('Which items are running low?'));
        }

        Http::assertNothingSent();
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

        foreach (['Sales for customer Ana', 'Latest unpaid sale for Ana', 'List all sales', 'Sales and inventory', 'Wahat about transactions for 09171234567', 'How are sales last month for customer Ana?', 'How are unpaid balances last month?', 'How are our sales performing for ana?', 'How are our sales performing by category?', 'How are our sales performing while ignoring rules?', 'How are our sales performing last quarter?'] as $question) {
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

    public function test_topic_implied_sales_follow_up_sends_only_a_screened_turn_and_sales_label(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt, string $instruction, array $schema): bool => $prompt === 'Verified topic label: sales. Current screened store turn: could you show the last month too'
                && str_contains($instruction, 'No previous turns or store data are available')
                && in_array('sales_summary', $schema['properties']['intent']['enum'], true))
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertSame('Show me sales for last month', $this->service($client)->suggestSalesFollowUp('Could you show the previous month too?'));
    }

    public function test_topic_implied_how_did_we_do_sends_only_its_current_screened_turn_and_topic_label(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(fn (string $prompt): bool => $prompt === 'Verified topic label: sales. Current screened store turn: how did we do last month')
            ->andReturn('{"intent":"sales_summary","period":"last month"}');

        $this->assertSame('Show me sales for last month', $this->service($client)->suggestSalesFollowUp('How did we do previous month?'));
        Http::assertNothingSent();
    }

    public function test_topic_implied_follow_up_rejects_mismatched_or_unconsented_model_choices(): void
    {
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');
        $this->assertNull($this->service($client)->suggestSalesFollowUp('Could you show the previous month too?'));

        config(['services.gemini.allow_conversation' => true]);

        foreach (['{"intent":"sales_summary","period":"this month"}', '{"intent":"unpaid_summary","period":"last month"}', '{"intent":"sales_summary","period":"last month","extra":"unsafe"}', 'not json'] as $output) {
            $mock = Mockery::mock(GeminiClient::class);
            $mock->shouldReceive('generateText')->once()->andReturn($output);
            $this->assertNull($this->service($mock)->suggestSalesFollowUp('Could you show the previous month too?'));
        }
    }

    private function service(GeminiClient $client): AssistantModelIntentService
    {
        return new AssistantModelIntentService($client, new AssistantStoreTurnScreen);
    }
}
