<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\AssistantAnswerService;
use App\Services\AssistantContextService;
use App\Services\AssistantResponseValidator;
use App\Services\GeminiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\FakeGeminiClient;
use Tests\TestCase;

class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_mode_defaults_to_agentic_chat_with_existing_sharing_consent(): void
    {
        config([
            'assistant.mode' => (require config_path('assistant.php'))['mode'],
            'services.gemini.allow_conversation' => true,
            'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Hello, glad you are here."}']]]],
        ])]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Hi Maanyag'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'Hello, glad you are here.');

        $this->assertSame('agentic', config('assistant.mode'));
        Http::assertSentCount(1);
    }

    public function test_flagged_agentic_owner_can_chat_then_get_a_locally_verified_store_answer(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.send_store_results_to_gemini' => false, 'assistant.agentic_scope_audit' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Hey, I am here."}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_inventory', 'name' => 'inventory_summary', 'arguments' => ['metric_evidence' => 'stock']]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Hey Maanyag'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'Hey, I am here.');
        $this->postJson(route('assistant.ask'), ['question' => 'How is our stock looking overall?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'inventory')
            ->assertJsonMissingPath('result.visit_context');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request['input']), 'Hey Maanyag')
            && ! str_contains(json_encode($request->data()), 'stock_value'));
    }

    public function test_agentic_clarification_keeps_the_explicit_period_across_http_turns(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.send_store_results_to_gemini' => false, 'assistant.agentic_scope_audit' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store area would you like for last month?","pending":"store_goal","period":"last_month"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'How did we do last month?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales, please'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '"pending_clarification_period":"last_month"'));
    }

    public function test_agentic_sales_period_reply_uses_the_pending_sales_measure_across_http_turns(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.agentic_scope_audit' => true,
            'assistant.send_store_results_to_gemini' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period for sales?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'yesterday', 'metric_evidence' => '', 'period_evidence' => 'Yesterday']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Show me total sales'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday would be great'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'])
            && str_contains($request['input'], '"trusted_pending_clarification":"sales_period"'));
    }

    public function test_agentic_public_aggregate_offer_survives_one_owner_follow_up_without_leaking_context(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Manila'));
        config([
            'assistant.mode' => 'agentic', 'assistant.send_store_results_to_gemini' => false, 'assistant.agentic_scope_audit' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I can show total sales this month instead. Want that?","pending":"offered_tool","offer":{"tool":"sales_summary","arguments":{"period":"this_month"}}}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => '', 'period_evidence' => '']]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Show sales by cashier this month?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes, please'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonMissingPath('result.visit_context');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '"pending_offer":{"tool":"sales_summary"')
            && ! str_contains(json_encode($request->data()), 'total_sales'));
    }

    public function test_agentic_scope_audit_keeps_a_filtered_request_off_the_fact_path_until_the_owner_accepts(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.agentic_scope_audit' => true,
            'assistant.send_store_results_to_gemini' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'running low']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"jacket category","clarification":"I cannot filter by jacket yet. Want the overall low-stock list?","offer_ungrouped":true,"language":"en"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'What is running low in the jacket category?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.sources')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes, please'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'inventory');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'])
            && str_contains($request['input'], '"trusted_pending_offer":{"tool":"low_stock"'));
    }

    public function test_agentic_unsupported_scope_without_an_offer_survives_to_the_next_turn(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.agentic_scope_audit' => true,
            'assistant.send_store_results_to_gemini' => false,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'almost sold out']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"tops","clarification":"I cannot filter this to tops. Could you restate a supported request?","offer_ungrouped":false,"language":"en"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_inventory', 'name' => 'inventory_summary', 'arguments' => ['metric_evidence' => 'inventory']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Which tops are almost sold out?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'Show overall inventory'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'inventory');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => is_array($request['input'])
            && str_contains($request['input'][0]['content'][0]['text'] ?? '', '"pending_clarification":"scope_goal"'));
    }

    public function test_agentic_short_follow_up_keeps_the_previous_clarification_language(): void
    {
        config([
            'assistant.mode' => 'agentic', 'assistant.agentic_scope_audit' => true,
            'services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'almost sold out']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"tops","clarification":"I cannot filter this to tops.","offer_ungrouped":false,"language":"en"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Please restate the full store question.","pending":"scope_goal"}']]]]]);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Which tops are almost sold out?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes, please'])
            ->assertJsonPath('result.status', 'clarification');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request['system_instruction'] ?? '', 'previous clarification was in English'));
    }

    public function test_owner_can_move_from_chat_to_a_sourced_recorded_activity_summary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 18:00:00', 'UTC'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Anything, just the latest actions happened in the Store.'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.answer_type', 'fact')
            ->assertJsonPath('result.sources.0.type', 'recorded_activity')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-17')
            ->assertSee('Recorded store activity for November 17, 2026')
            ->assertSee('Sales recorded: 0')
            ->assertSee('Other tracked changes: none')
            ->assertSee('Scheduled product collection dates today: 0')
            ->assertJsonMissingPath('result.visit_context');
        Http::assertNothingSent();
    }

    public function test_owner_can_correct_a_chat_reply_without_repeating_its_words(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: hey')
                ->andReturn('{"kind":"general_chat","reply":"Hey there, glad you dropped by."}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"reply":"Hey there, glad you dropped by."')
                    && str_ends_with($prompt, 'Current screened general-chat turn: not wonderful'))
                ->andReturn('{"kind":"general_chat","reply":"Sorry it has been rough. Happy to listen."}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"reply":"Sorry it has been rough. Happy to listen."')
                    && str_ends_with($prompt, 'Current screened general-chat turn: not fantastic'))
                ->andReturn('{"kind":"general_chat","reply":"I hear you. Want to tell me more?"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"reply":"I hear you. Want to tell me more?"')
                    && str_ends_with($prompt, 'Current screened general-chat turn: nothing much'))
                ->andReturn('{"kind":"general_chat","reply":"I am here if you feel like talking."}');
        });
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Not wonderful'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'Sorry it has been rough. Happy to listen.')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'Not fantastic'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'I hear you. Want to tell me more?');
        $this->postJson(route('assistant.ask'), ['question' => 'Nothing much.'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'I am here if you feel like talking.');
        $this->postJson(route('assistant.ask'), ['question' => 'Not Alicia'])
            ->assertJsonPath('result.status', 'declined');

        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Not fantastic'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Nothing much.'])
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_owner_can_move_from_chat_to_a_broad_store_question_then_select_a_verified_skill(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => "What's going on in the Store for now?"])
            ->assertJsonPath('result.status', 'clarification')
            ->assertSee('Which store information would you like')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'Can I see stock and its value?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.label', 'Inventory summary');
        Http::assertNothingSent();
    }

    public function test_short_reactions_follow_the_previous_public_reply_and_reset_with_new_chat(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertSee('having a wonderful day');
        $this->postJson(route('assistant.ask'), ['question' => 'Not wonderful'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertSee('Happy to listen or keep things quiet')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'Too quiet'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertSee('Want a little distraction');

        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Not wonderful'])
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_owner_can_move_from_chat_to_sourced_store_follow_ups_during_a_provider_outage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->atLeast()->once()->andThrow(new ConnectionException('Simulated outage'));
        });
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Hey Maanyag'])
            ->assertJsonPath('result.status', 'unavailable')
            ->assertJsonPath('result.message', 'Sorry, I’m having trouble continuing our chat right now. Please try again in a moment.');
        $this->postJson(route('assistant.ask'), ['question' => 'Uhmm, nothing really'])
            ->assertJsonPath('result.status', 'unavailable')
            ->assertJsonPath('result.message', 'Sorry, I’m having trouble continuing our chat right now. Please try again in a moment.');

        $this->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertSee('transaction counts');
        $this->postJson(route('assistant.ask'), ['question' => 'Just the totals please'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertSee('transaction counts');
        $this->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertSee('Which period');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.label', 'Sales summary');
        $this->postJson(route('assistant.ask'), ['question' => 'Could you show the previous month too?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('sales for October 2026');
        $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.label', 'Inventory summary')
            ->assertSee('0 active products, 0 units, stock value PHP 0.00');
        Http::assertNothingSent();
    }

    public function test_failed_chat_turn_keeps_only_prior_successful_public_chat_for_a_retry(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $prompts = [];
        $this->mock(GeminiClient::class, function (MockInterface $mock) use (&$prompts): void {
            $mock->shouldReceive('generateText')->times(3)->andReturnUsing(function (string $prompt) use (&$prompts): string {
                $prompts[] = $prompt;

                return match (count($prompts)) {
                    1 => '{"kind":"general_chat","reply":"Hey there, glad you dropped by."}',
                    2 => throw new ConnectionException('Simulated outage'),
                    default => '{"kind":"general_chat","reply":"I am still here with you."}',
                };
            });
        });
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'hello'])
            ->assertJsonPath('result.status', 'unavailable')
            ->assertJsonPath('result.message', 'Sorry, I’m having trouble continuing our chat right now. Please try again in a moment.');
        $this->postJson(route('assistant.ask'), ['question' => 'hey'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'I am still here with you.');

        $this->assertCount(3, $prompts);
        $this->assertStringContainsString('"turn":"hey","reply":"Hey there, glad you dropped by."', $prompts[2]);
        $this->assertStringNotContainsString('"turn":"hello"', $prompts[2]);
        Http::assertNothingSent();
    }

    public function test_generated_chat_does_not_lose_pending_sales_choices_or_the_validated_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Hey Maanyag'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Just the totals please'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Uhmm, nothing really'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday would be great'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('daily on 2026-11-15')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Hey Maanyag'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'How did we do last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('monthly on 2026-10-16');

        $this->postJson(route('assistant.ask'), ['question' => 'Give me the big picture on how the shop did last month.'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Uhmm, nothing really'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales totals'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('monthly on 2026-10-16');
        Http::assertNothingSent();
    }

    public function test_gemini_writes_greetings_and_unfamiliar_casual_replies_without_store_access(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->actingAs($owner);

        foreach ([
            'Hey Maanyag' => 'Hey! Happy to chat. How is it going?',
            'Uhmm, nothing really' => 'No worries, a little idle chat is welcome!',
            'Haha, just hanging around' => 'Haha, sounds relaxed! Happy to keep you company.',
            'Pwede ta magchika kadyot' => 'Oo, pwede kaayo! Unsa may gusto nimong hisgutan?',
        ] as $question => $reply) {
            $this->postJson(route('assistant.ask'), ['question' => $question])
                ->assertJsonPath('result.status', 'general_chat')
                ->assertJsonPath('result.message', $reply)
                ->assertJsonMissingPath('result.sources')
                ->assertJsonMissingPath('result.visit_context');
        }

        Http::assertNothingSent();
    }

    public function test_varied_replies_complete_sales_and_unpaid_clarifications(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Just the totals please'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertSee('For which period')
            ->assertJsonMissingPath('result.sources')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday would be great'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('daily on 2026-11-15')
            ->assertJsonPath('result.sources.0.type', 'sales');

        $this->postJson(route('assistant.ask'), ['question' => 'Unpaid?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Pila tanan nga wala nabayran'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('0 pending sales')
            ->assertJsonPath('result.sources.0.type', 'unpaid_summary')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');
        Http::assertNothingSent();
    }

    public function test_varied_reply_cannot_reuse_an_unpaid_clarification_after_visit_resets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $this->actingAs($owner);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        foreach (['new_chat', 'expiry', 'private'] as $reset) {
            $this->postJson(route('assistant.ask'), ['question' => 'Unpaid?'])
                ->assertJsonPath('result.status', 'clarification');

            if ($reset === 'new_chat') {
                $this->get(route('assistant.index'));
            } elseif ($reset === 'expiry') {
                $this->travel(21)->minutes();
            } else {
                $this->postJson(route('assistant.ask'), ['question' => 'The whole balance for Ana please'])
                    ->assertJsonPath('result.status', 'declined');
            }

            $this->postJson(route('assistant.ask'), ['question' => 'The whole balance please'])
                ->assertJsonPath('result.status', 'declined')
                ->assertJsonMissingPath('result.sources');
        }

        Http::assertNothingSent();
    }

    public function test_capitalized_unpaid_wording_uses_the_existing_verified_aggregate_skill(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many are Unpaid yet?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'unpaid_summary')
            ->assertSee('0 pending sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');
        Http::assertNothingSent();
    }

    public function test_ordinary_connecting_words_reach_interpretation_and_verified_skills(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Can I see stock and its value?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.label', 'Inventory summary');
        $this->postJson(route('assistant.ask'), ['question' => 'How much money came in from sales yesterday?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.label', 'Sales summary')
            ->assertSee('daily on 2026-11-15');
        $this->postJson(route('assistant.ask'), ['question' => 'Can you give me an idea of how sales are doing?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.sources');
        Http::assertNothingSent();
    }

    public function test_natural_store_wording_clarifies_and_preserves_the_period_until_the_owner_selects_sales(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Give me the big picture on how the shop did last month.'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'For last month, would you like sales totals or transaction counts?')
            ->assertJsonMissingPath('result.sources')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales totals'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-16: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');
        $this->postJson(route('assistant.ask'), ['question' => 'Pila atong halin last week?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('weekly on 2026-11-09')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Are we close to running out of anything?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'No recorded low-stock products.')
            ->assertJsonPath('result.sources.0.label', 'Low-stock products');
        Http::assertNothingSent();
    }

    public function test_natural_interpretation_without_store_sharing_never_reads_records(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Pila atong halin last week?'])
            ->assertJsonPath('result.status', 'disabled')
            ->assertJsonMissingPath('result.sources');
        Http::assertNothingSent();
    }

    public function test_natural_pending_period_is_cleared_by_new_chat_expiry_and_private_requests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $this->actingAs($owner);

        foreach (['new_chat', 'expiry', 'private'] as $reset) {
            $this->postJson(route('assistant.ask'), ['question' => 'Give me the big picture on how the shop did last month.'])
                ->assertJsonPath('result.status', 'clarification');

            if ($reset === 'new_chat') {
                $this->get(route('assistant.index'));
            } elseif ($reset === 'expiry') {
                $this->travel(21)->minutes();
            } else {
                $this->postJson(route('assistant.ask'), ['question' => 'How much did the register ring up yesterday for alicia?'])
                    ->assertJsonPath('result.status', 'declined');
            }

            $this->postJson(route('assistant.ask'), ['question' => 'Sales totals'])
                ->assertJsonPath('result.status', 'clarification')
                ->assertSee('which period:')
                ->assertJsonMissingPath('result.sources');
        }

        Http::assertNothingSent();
    }

    public function test_natural_model_scope_refusal_never_reads_store_data(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->andReturn('{"intent":"sales_summary","period":"yesterday","scope":"filtered"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How much did the register ring up yesterday?'])
            ->assertJsonPath('result.status', 'declined')
            ->assertJsonMissingPath('result.sources');
        Http::assertNothingSent();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.gemini.allow_conversation' => false,
            'assistant.send_store_results_to_gemini' => true,
            'assistant.paid_tier_reviewed' => true,
        ]);
    }

    public function test_owner_sees_local_legacy_sales_without_sending_store_results_on_the_free_tier(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Manila'));
        config([
            'assistant.mode' => 'legacy',
            'assistant.send_store_results_to_gemini' => false,
            'assistant.paid_tier_reviewed' => false,
            'services.gemini.allow_store_data' => true,
        ]);
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->actingAs($this->createUser('store_owner'));

        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertSee('Recorded sales for November 2026');

        Http::assertNothingSent();
    }

    public function test_first_joke_and_follow_up_use_only_a_visit_topic_then_new_chat_starts_a_fresh_joke(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'Another thrift joke: Why did the scarf go exploring? It wanted a new twist!');
        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'A little thrift joke: Why did the jacket enjoy another season? It got a fresh start!');
    }

    public function test_chat_topic_expires_and_unsafe_turns_clear_it_without_provider_calls(): void
    {
        $this->travelTo(now()->startOfMinute());
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke for customer Ana'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A little thrift joke: Why did the jacket enjoy another season? It got a fresh start!');
        $this->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A little thrift joke: Why did the jacket enjoy another season? It got a fresh start!');
    }

    public function test_chat_topic_cannot_cross_to_a_different_owner_or_survive_logout(): void
    {
        $owner = $this->createUser('store_owner');
        $otherOwner = User::query()->create([
            'name' => 'Synthetic Other Owner',
            'email' => 'synthetic-other-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->actingAs($otherOwner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A little thrift joke: Why did the jacket enjoy another season? It got a fresh start!');
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A little thrift joke: Why did the jacket enjoy another season? It got a fresh start!');
    }

    public function test_first_message_joke_and_public_chat_are_labeled_over_http_with_mocked_provider(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A jacket found a fresh chapter!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: what do you think about vintage fashion')
                ->andReturn('{"kind":"general_chat","reply":"Vintage style gives clothes a fresh chapter!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertExactJson(['result' => ['status' => 'general_chat', 'message' => 'A jacket found a fresh chapter!']]);
        $this->get(route('assistant.index'))->assertOk();
        $this->post(route('assistant.ask'), ['question' => 'What do you think about vintage fashion?'])
            ->assertOk()
            ->assertSee('General chat · not store facts')
            ->assertSee('Vintage style gives clothes a fresh chapter!');
    }

    public function test_unsafe_or_store_turns_never_reach_general_chat_provider(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke for customer Ana'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke about our sales'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'What is the weather while thrift shopping?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke about my jacket'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_follow_up_chat_sends_screened_public_history_but_no_store_context(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: tell me a joke about thrift shopping')
                ->andReturn('{"kind":"general_chat","reply":"A jacket found a fresh chapter!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"turn":"tell me a joke about thrift shopping","reply":"A jacket found a fresh chapter!"')
                    && str_ends_with($prompt, 'Current screened general-chat turn: tell me another joke about thrift shopping'))
                ->andReturn('{"kind":"general_chat","reply":"A scarf found a new twist!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"turn":"tell me a joke about thrift shopping","reply":"A jacket found a fresh chapter!"')
                    && str_contains($prompt, '"turn":"tell me another joke about thrift shopping","reply":"A scarf found a new twist!"')
                    && str_ends_with($prompt, 'Current screened general-chat turn: what do you think about vintage fashion'))
                ->andReturn('{"kind":"general_chat","reply":"Vintage outfits can have another chapter!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.message', 'A jacket found a fresh chapter!');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'A scarf found a new twist!')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'What do you think about vintage fashion?'])
            ->assertJsonPath('result.message', 'Vintage outfits can have another chapter!');
    }

    public function test_new_chat_clears_public_chat_history_instead_of_replaying_old_turns(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->twice()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A cheerful thought!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A cheerful thought!');
    }

    public function test_public_chat_history_expires_after_inactivity_without_crossing_owners_or_logout(): void
    {
        $this->travelTo(now()->startOfMinute());
        config(['services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $otherOwner = User::query()->create([
            'name' => 'Synthetic Other Owner', 'email' => 'second-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->times(5)
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A cheerful thought!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->actingAs($otherOwner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A cheerful thought!');
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A cheerful thought!');
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A cheerful thought!');
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A cheerful thought!');
    }

    public function test_private_sale_result_never_enters_public_history_even_when_the_owner_acknowledges_it(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-PRIVATE-001',
            'sale_type' => 'walk_in',
            'online_platform' => '',
            'customer_name' => 'Synthetic Buyer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic address',
            'total_amount' => '35.00',
            'payment_status' => 'partially_paid',
            'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-07 10:00:00',
        ]);
        $sale->unpaidPayment()->create([
            'amount_paid' => '10.00', 'balance' => '25.00', 'payment_date' => null,
            'status' => 'pending', 'updated_by' => $owner->id,
        ]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A cheerful thought!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"turn":"joke","reply":"A cheerful thought!"')
                    && str_contains($prompt, 'A store question about unpaid happened since that chat.')
                    && str_ends_with($prompt, 'Current screened general-chat turn: okay')
                    && ! str_contains($prompt, 'SYN-PRIVATE-001')
                    && ! str_contains($prompt, 'Synthetic Buyer')
                    && ! str_contains($prompt, '0000000001')
                    && ! str_contains($prompt, '25.00'))
                ->andReturn('{"kind":"general_chat","reply":"Got it! Happy to chat more."}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Show the latest unpaid sale'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Okay'])
            ->assertExactJson(['result' => ['status' => 'general_chat', 'message' => 'Got it! Happy to chat more.']]);
    }

    public function test_unsafe_interlude_is_not_forwarded_or_confused_with_the_last_public_chat_turn(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A cheerful thought!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, '"turn":"joke","reply":"A cheerful thought!"')
                    && str_contains($prompt, 'A non-chat turn happened since that chat.')
                    && ! str_contains($prompt, 'customer Ana')
                    && str_ends_with($prompt, 'Current screened general-chat turn: okay'))
                ->andReturn('{"kind":"general_chat","reply":"Happy to chat!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke for customer Ana'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Okay'])
            ->assertJsonPath('result.message', 'Happy to chat!');
    }

    public function test_disabling_chat_consent_discards_public_history_before_it_can_be_forwarded_again(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: joke')
                ->andReturn('{"kind":"general_chat","reply":"A cheerful thought!"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: tell me another joke about thrift shopping')
                ->andReturn('{"kind":"general_chat","reply":"A lovely twist!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Joke']);
        config(['services.gemini.allow_conversation' => false]);
        $this->postJson(route('assistant.ask'), ['question' => 'Okay'])
            ->assertJsonPath('result.status', 'general_chat');
        config(['services.gemini.allow_conversation' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.message', 'A lovely twist!');
    }

    public function test_a_screened_public_store_paraphrase_can_clarify_via_mocked_intent_without_store_data(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: could we talk about how our sales are doing')
                ->andReturn('{"intent":"sales_goal","period":"none"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'clarification / sales_goal'))
                ->andReturn('{"style":"warm","suggested_skill":"none"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Could we talk about how our sales are doing?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.visit_context');
    }

    public function test_owner_gets_verified_sales_for_natural_screened_wording_with_only_the_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How did our sales go last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-15: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15');
        $this->postJson(route('assistant.ask'), ['question' => 'How did our sales go last month for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'How did our sales and inventory go last month?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_owner_gets_verified_sales_for_unfamiliar_descriptive_wording_with_only_the_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How are our sales performing last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16')
            ->assertSee('2026-10-16');

        Http::assertNothingSent();
    }

    public function test_owner_gets_sourced_sales_for_a_non_suffix_descriptive_question_using_only_the_demo_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How did our sales fare last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-16: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');

        Http::assertNothingSent();
    }

    public function test_owner_gets_sourced_answers_for_polite_sales_and_indirect_inventory_questions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $category = Category::query()->create(['category_name' => 'DEMO clothing', 'status' => 'active']);
        Product::query()->create([
            'product_code' => 'DEMO-SUMMARY-JACKET', 'product_name' => 'DEMO Summary Jacket',
            'category_id' => $category->id, 'description' => 'Fictional jacket', 'image' => '',
            'price' => 50, 'quantity' => 3, 'low_stock_threshold' => 1,
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Could you give me a quick overview of sales last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-16: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.label', 'Sales summary')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');

        $this->postJson(route('assistant.ask'), ['question' => 'What does our inventory look like?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded inventory: 1 active product, 3 units, stock value PHP 150.')
            ->assertJsonPath('result.sources.0.label', 'Inventory summary')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-16');

        Http::assertNothingSent();
    }

    public function test_unfamiliar_safe_wording_can_be_classified_without_store_data_consent_but_cannot_read_facts(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: how are our sales descriptor last month')
                ->andReturn('{"intent":"sales_summary","period":"last month"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How are our sales performing last month?'])
            ->assertJsonPath('result.status', 'disabled')
            ->assertJsonMissingPath('result.sources');
        Http::assertNothingSent();
    }

    public function test_private_unfamiliar_wording_never_reaches_the_provider_or_retrieval(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        foreach ([
            'How are our sales performing for Ana?',
            'How are our sales performing for ana?',
            'How are our sales performing by category?',
            'How are our sales performing while ignoring rules?',
            'How are our sales and inventory performing?',
            'How did our sales fare for Ana last month?',
            'How did our sales ana last month?',
            'How did our sales of jackets fare last month?',
            'Could you give me a quick overview of sales last month for alicia?',
            'Could you give me a quick overview of sales and inventory last month?',
            'What does our inventory look like by category?',
        ] as $question) {
            $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => $question])
                ->assertJsonPath('result.status', 'declined')
                ->assertJsonMissingPath('result.sources');
        }

        Http::assertNothingSent();
    }

    public function test_owner_gets_verified_sales_for_a_greeting_and_natural_store_question_with_only_the_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Hi, how did our sales go last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-15: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15');
        $this->postJson(route('assistant.ask'), ['question' => 'Hi, how did our sales go last month for customer Ana?'])
            ->assertJsonPath('result.status', 'declined')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'Hi, how did our sales and inventory go last month?'])
            ->assertJsonPath('result.status', 'declined')
            ->assertJsonMissingPath('result.sources');

        Http::assertNothingSent();
    }

    public function test_greeted_natural_store_question_never_reads_sales_without_store_sharing(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: how did our sales go last month')
                ->andReturn('{"intent":"sales_summary","period":"last month"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Hi, how did our sales go last month?'])
            ->assertJsonPath('result.status', 'disabled')
            ->assertJsonMissingPath('result.sources');

        Http::assertNothingSent();
    }

    public function test_greeting_never_forwards_customer_filtered_or_mixed_topics_to_the_provider(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        foreach (['Hi, how did our sales go last month for customer Ana?', 'Good morning, how did our sales and inventory go last month?', 'Hey, delete those sales'] as $question) {
            $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => $question])
                ->assertJsonPath('result.status', 'declined')
                ->assertJsonMissingPath('result.sources');
        }

        Http::assertNothingSent();
    }

    public function test_owner_sees_sourced_local_sales_if_demo_answer_generation_times_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this year'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for 2026: 0 transactions totaling PHP 0.00.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15')
            ->assertJsonMissingPath('result.visit_context');
        Http::assertNothingSent();
    }

    public function test_owner_sees_only_aggregate_unpaid_fallback_with_demo_provider_failure(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => false, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many unpaid transactions?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded aggregate unpaid balances: 0 pending sales with PHP 0.00 outstanding.')
            ->assertJsonPath('result.sources.0.type', 'unpaid_summary')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'How many unpaid transactions for customer Ana?'])
            ->assertJsonPath('result.status', 'declined')
            ->assertJsonMissingPath('result.sources');

        Http::assertNothingSent();
    }

    public function test_unpaid_aggregate_remains_disabled_without_store_sharing_even_with_a_fake_provider(): void
    {
        config(['services.gemini.allow_conversation' => false, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many unpaid transactions?'])
            ->assertJsonPath('result.status', 'disabled');
        Http::assertNothingSent();
    }

    public function test_conversation_consent_never_forwards_adjustment_totals_or_individual_sale_details_to_intent_model(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments');
        $this->postJson(route('assistant.ask'), ['question' => 'Show the latest unpaid sale'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonMissingPath('result.sources');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_verified_sales_topic_supports_screened_previous_month_follow_up_without_prior_messages(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Could you show the previous month too?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-15: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15')
            ->assertJsonMissingPath('result.visit_context');
        Http::assertNothingSent();
    }

    public function test_verified_sales_topic_allows_how_did_we_do_without_ever_sending_prior_answers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How did we do last month?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->postJson(route('assistant.ask'), ['question' => 'How did we do last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-15: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15');
        $this->postJson(route('assistant.ask'), ['question' => 'How did we do last month for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'How did we do last month?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_screened_low_stock_paraphrase_uses_model_interpretation_and_verified_inventory_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Which items are running low?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'No recorded low-stock products.')
            ->assertJsonPath('result.sources.0.type', 'inventory')
            ->assertJsonPath('result.sources.0.label', 'Low-stock products')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15');
        $this->postJson(route('assistant.ask'), ['question' => 'Which items are running low for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_populated_best_sellers_uses_the_local_fake_classifier_and_only_verified_ranking_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andReturn([
                'best_sellers' => [['product_name' => 'DEMO Cotton Tee', 'units_sold' => 3, 'sales_total' => 30.0]],
                'as_of' => ['best_sellers' => '2026-11-15'],
                'scopes' => ['best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.'],
            ]);
        });
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'List best-selling products'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded best sellers from paid sales: DEMO Cotton Tee (3 paid units).')
            ->assertJsonPath('result.sources.0.type', 'best_sellers')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-15');
        Http::assertNothingSent();
    }

    public function test_low_stock_paraphrase_never_reads_inventory_without_store_sharing(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened store turn: which items are running low')
                ->andReturn('{"intent":"low_stock","period":"none"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Which items are running low?'])
            ->assertJsonPath('result.status', 'disabled')
            ->assertJsonMissingPath('result.sources');
        Http::assertNothingSent();
    }

    public function test_implied_sales_follow_up_needs_a_fresh_verified_topic_and_store_consent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $followUp = ['question' => 'Could you show the previous month too?'];

        $this->actingAs($owner)->postJson(route('assistant.ask'), $followUp)
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        config(['services.gemini.allow_store_data' => false]);
        $this->postJson(route('assistant.ask'), $followUp)
            ->assertJsonPath('result.status', 'disabled');
        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), $followUp)
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->postJson(route('assistant.ask'), ['question' => 'Could you show the previous month too for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), $followUp)
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_implied_follow_up_clears_on_new_chat_and_visit_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');
        $followUp = ['question' => 'Could you show the previous month too?'];

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), $followUp)->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), $followUp)->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_a_bounded_store_question_typo_clarifies_in_the_browser_without_a_provider_call(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Wahat about transaction counts?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, last week, this month, last month, this year, or last year?');
    }

    public function test_owner_sees_labeled_local_general_chat_and_no_saved_chat_context(): void
    {
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->post(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertOk()
            ->assertSee('General chat · not store facts')
            ->assertSee('A little thrift joke');
        $this->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_cashier_cannot_request_general_chat_even_with_conversation_sharing_on(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $cashier = $this->createUser('cashier');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($cashier)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertForbidden();
    }

    public function test_owner_chat_over_http_sends_only_a_public_store_topic_with_separate_consent(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'clarification / sales_goal'))
                ->andReturn('{"style":"warm","suggested_skill":"none"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'clarification / sales_period'))
                ->andReturn('{"style":"warm","suggested_skill":"none"}');
            $mock->shouldReceive('generateText')->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'A store question about sales happened since that chat.')
                    && str_ends_with($prompt, 'Current screened general-chat turn: tell me a joke about thrift shopping')
                    && ! str_contains($prompt, 'sales_goal'))
                ->andReturn('{"kind":"general_chat","reply":"A preloved jacket gets another chapter!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertExactJson(['result' => [
                'status' => 'general_chat',
                'message' => 'A preloved jacket gets another chapter!',
            ]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.sources');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
    }

    public function test_cashier_cannot_view_the_assistant(): void
    {
        $cashier = $this->createUser('cashier');

        $this->actingAs($cashier)->get(route('assistant.index'))->assertForbidden();
    }

    public function test_cashier_cannot_submit_a_question(): void
    {
        $cashier = $this->createUser('cashier');

        $this->actingAs($cashier)
            ->post(route('assistant.ask'), ['question' => 'List recently collected products'])
            ->assertForbidden();
    }

    public function test_owner_sees_the_read_only_assistant_form(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->get(route('assistant.index'));

        $response->assertOk();
        $response->assertSee('Maanyag Assistant');
        $response->assertSee('Your smart assistant, Maanyag!');
        $response->assertSee('New Chat');
        $response->assertSee('href="'.route('assistant.index').'"', false);
        $response->assertSee('Maximum 500 characters');
        $response->assertSee('cannot create, edit, delete, archive, or settle records');
        $response->assertSee('Follow up during this visit');
        $response->assertSee('With conversation sharing enabled, up to 40 screened public-chat exchanges are temporarily kept and may be sent to Gemini.');
        $response->assertSee('Before enabling conversation sharing: screened, non-identifying store questions may be sent to Gemini only to choose an approved skill.');
        $response->assertSee('Brief casual replies can reach Gemini as typed after basic privacy checks.');
        $response->assertSee('Free-text screening can miss a private detail, including a lowercase name.');
        $response->assertSee('Store-data sharing requires separate approval; retrieved store results stay on this system on the free tier. Gemini cannot directly access the database.');
        $response->assertSee('New Chat, returning here, or logout clears them; they also expire after 20 minutes of inactivity. No permanent chat archive.');
        $response->assertDontSee('chats are not saved');
        $response->assertSee('Greetings and brief chat still work with a local fallback');
        $response->assertSee('List recently collected products.');
        $response->assertSee('How were sales last month?');
        $response->assertSee('value="How were sales last month?"', false);
        $response->assertSee('Show the latest unpaid sale.');
        $response->assertSee('How many damaged item adjustments?');
        $response->assertSee('value="How many damaged item adjustments?"', false);
        $response->assertSee('Summarize stock adjustments.');
        $response->assertSee('value="Summarize stock adjustments"', false);
        $response->assertSee('Summarize stock adjustments this month.');
        $response->assertSee('value="Summarize stock adjustments this month"', false);
        $response->assertSee('aggregate stock-adjustment or damaged-item totals');
        $response->assertSee('read-only stock-adjustment totals');
        $response->assertSee('value="List recently collected products"', false);
        $response->assertSee('Check Inventory');
        $response->assertSee('Sales Insights');
        $response->assertDontSee('Generate Report');
        $response->assertViewMissing('result');
    }

    public function test_owner_sees_keyboard_instructions_for_the_question_composer(): void
    {
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->get(route('assistant.index'));

        $response->assertSee('Enter to send · Shift+Enter for a new line.');
    }

    public function test_owner_receives_a_mocked_answer_without_calling_gemini(): void
    {
        $owner = $this->createUser('store_owner');

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->with('How many unpaid transactions?', null, null)
                ->andReturn([
                    'status' => 'answered',
                    'message' => 'Synthetic aggregate answer.',
                    'sources' => [
                        ['type' => 'unpaid_summary', 'label' => 'Unpaid summary', 'as_of' => '2026-11-06'],
                    ],
                ]);
        });

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => 'How many unpaid transactions?',
        ]);

        $response->assertOk();
        $response->assertSee('How many unpaid transactions?');
        $response->assertSee('Synthetic aggregate answer.');
        $response->assertSee('Unpaid summary');
        $response->assertSee('As of 2026-11-06');
    }

    public function test_owner_sees_escaped_question_and_answer_in_the_chat(): void
    {
        $owner = $this->createUser('store_owner');
        $question = '<script>alert("question")</script>';

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use ($question): void {
            $mock->shouldReceive('answer')
                ->once()
                ->with($question, null, null)
                ->andReturn([
                    'status' => 'answered',
                    'message' => '<img src=x onerror=alert(1)>',
                ]);
        });

        $response = $this->actingAs($owner)->post(route('assistant.ask'), ['question' => $question]);

        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $response->assertDontSee($question, false);
        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_owner_sees_the_disabled_message_when_store_data_sharing_is_off(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => 'List recently collected products',
        ]);

        $response->assertOk();
        $response->assertSee('disabled until sharing store data');
    }

    public function test_owner_receives_sourced_empty_best_seller_answer_over_json_without_a_provider_call(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
        config(['services.gemini.allow_store_data' => true, 'services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'List best-selling products'])
            ->assertExactJson(['result' => [
                'status' => 'answered',
                'message' => 'No best-selling products can be ranked from recorded paid sales yet.',
                'sources' => [['type' => 'best_sellers', 'label' => 'Best sellers', 'as_of' => '2026-11-02']],
                'answer_type' => 'fact',
                'confidence' => 'high',
            ]]);
    }

    public function test_owner_receives_sourced_populated_best_sellers_from_mocked_provider_only(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $category = Category::query()->create(['category_name' => 'Synthetic clothing', 'status' => 'active']);
        $jacket = Product::query()->create([
            'product_code' => 'SYN-JACKET', 'product_name' => 'Synthetic Jacket',
            'category_id' => $category->id, 'description' => 'Fictional jacket',
            'price' => 10, 'quantity' => 2, 'low_stock_threshold' => 1,
            'image' => '', 'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $shirt = Product::query()->create([
            'product_code' => 'SYN-SHIRT', 'product_name' => 'Synthetic Shirt',
            'category_id' => $category->id, 'description' => 'Fictional shirt',
            'price' => 10, 'quantity' => 5, 'low_stock_threshold' => 1,
            'image' => '', 'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $paid = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-PAID', 'sale_type' => 'walk_in', 'online_platform' => 'N/A',
            'total_amount' => 30, 'payment_status' => 'paid', 'recorded_by' => $owner->id,
            'transaction_date' => '2026-10-10 12:00:00',
        ]);
        $paid->items()->create(['product_id' => $jacket->id, 'quantity' => 3, 'unit_price' => 10, 'subtotal' => 30]);
        $unpaid = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-UNPAID', 'sale_type' => 'walk_in', 'online_platform' => 'N/A',
            'customer_name' => 'Fictional Customer', 'total_amount' => 90,
            'payment_status' => 'unpaid', 'recorded_by' => $owner->id,
            'transaction_date' => '2026-10-10 13:00:00',
        ]);
        $unpaid->items()->create(['product_id' => $shirt->id, 'quantity' => 9, 'unit_price' => 10, 'subtotal' => 90]);

        $providerContext = null;
        $this->mock(GeminiClient::class, function (MockInterface $mock) use (&$providerContext): void {
            $mock->shouldReceive('generateText')->once()
                ->withArgs(function (string $prompt, string $systemInstruction, array $schema) use (&$providerContext): bool {
                    $providerContext = json_decode(explode("Verified system context (JSON):\n", $prompt, 2)[1] ?? '', true);

                    return $schema === AssistantResponseValidator::RESPONSE_SCHEMA
                        && str_contains($systemInstruction, 'best sellers use paid transactions only');
                })
                ->andReturn(json_encode([
                    'answer' => 'Synthetic Jacket leads with 3 paid units and PHP 30 in paid sales.',
                    'answer_type' => 'summary',
                    'sources' => [['type' => 'best_sellers', 'label' => 'Best sellers', 'as_of' => '2026-11-02']],
                    'confidence' => 'high',
                    'refusal' => false,
                ]));
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'List best-selling products'])
            ->assertExactJson(['result' => [
                'status' => 'answered',
                'message' => 'Synthetic Jacket leads with 3 paid units and PHP 30 in paid sales.',
                'sources' => [['type' => 'best_sellers', 'label' => 'Best sellers', 'as_of' => '2026-11-02']],
                'answer_type' => 'summary',
                'confidence' => 'high',
            ]]);

        $this->assertSame([['product_name' => 'Synthetic Jacket', 'units_sold' => 3, 'sales_total' => 30]], $providerContext['best_sellers']);
        $this->assertSame(['best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.'], $providerContext['scopes']);
        $this->assertSame([['type' => 'best_sellers', 'label' => 'Best sellers', 'as_of' => '2026-11-02']], $providerContext['allowed_sources']);
        $this->assertSame([], $providerContext['sales']['summary']);
        $this->assertStringNotContainsString('Fictional Customer', json_encode($providerContext));
        $this->assertStringNotContainsString('Synthetic Shirt', json_encode($providerContext));
    }

    public function test_owner_sees_unsupported_question_as_a_declined_chat_message(): void
    {
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => 'What is the weather today?',
        ]);

        $response->assertSee('What is the weather today?');
        $response->assertSee('Not supported');
        $response->assertViewHas('result', fn (array $result): bool => empty($result['sources']));
    }

    public function test_owner_receives_a_local_greeting_in_html_and_json_without_gemini(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');

        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->post(route('assistant.ask'), ['question' => 'Hi!'])
            ->assertSee('Maanyag · Chat')
            ->assertSee('Hi! I am Maanyag.');
        $this->postJson(route('assistant.ask'), ['question' => 'Good evening'])
            ->assertExactJson(['result' => [
                'status' => 'social',
                'message' => 'Good evening! I am here for a quick chat or a supported store question.',
            ]]);
    }

    public function test_cashier_cannot_submit_a_social_question(): void
    {
        $cashier = $this->createUser('cashier');

        $this->actingAs($cashier)->postJson(route('assistant.ask'), ['question' => 'Hi!'])->assertForbidden();
    }

    public function test_local_chat_preserves_only_the_last_verified_skill_for_follow_ups(): void
    {
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->times(3)->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return $question === 'Hi!'
                    ? ['status' => 'social', 'message' => 'Hi! I am Maanyag.']
                    : ['status' => 'answered', 'message' => 'Synthetic verified answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->postJson(route('assistant.ask'), ['question' => 'Hi!'])
            ->assertExactJson(['result' => ['status' => 'social', 'message' => 'Hi! I am Maanyag.']]);
        $this->postJson(route('assistant.ask'), ['question' => 'What about yesterday?']);

        $this->assertSame([
            null,
            ['owner_id' => $owner->id, 'intent' => 'sales_summary'],
            ['owner_id' => $owner->id, 'intent' => 'sales_summary'],
        ], $receivedContexts);
    }

    public function test_social_chat_does_not_create_follow_up_context_without_a_verified_answer(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Hi!'])
            ->assertJsonPath('result.status', 'social');
        $this->postJson(route('assistant.ask'), ['question' => 'What about yesterday?'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_owner_can_clarify_sales_locally_without_saving_the_chat_or_querying_store_data(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $first = $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?']);
        $second = $this->postJson(route('assistant.ask'), ['question' => 'Transaction counts']);
        $third = $this->postJson(route('assistant.ask'), ['question' => 'Yesterday']);

        $first->assertJsonPath('result.status', 'clarification')
            ->assertJsonMissingPath('result.visit_context');
        $second->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, last week, this month, last month, this year, or last year?');
        $third->assertJsonPath('result.status', 'disabled');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_owner_can_reply_naturally_to_sales_clarification_without_a_provider_call(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => "I'd like totals, please"])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, last week, this month, last month, this year, or last year?')
            ->assertJsonMissingPath('result.visit_context');
    }

    public function test_owner_can_complete_natural_sales_clarification_and_follow_up_with_a_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => "I'd like totals, please"])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Of course! For which period: today, yesterday, this week, last week, this month, last month, this year, or last year?');
        $this->postJson(route('assistant.ask'), ['question' => 'Today, please'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for daily on 2026-11-14: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'And what about yesterday?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for daily on 2026-11-13: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14')
            ->assertJsonMissingPath('result.visit_context');

        Http::assertNothingSent();
    }

    public function test_owner_can_ask_for_previous_sales_periods_and_follow_up_with_the_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'How were sales last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2025-12-01: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-01-01');
        $this->postJson(route('assistant.ask'), ['question' => 'And what about last year?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for yearly on 2025-01-01: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales')
            ->assertJsonPath('result.sources.0.as_of', '2026-01-01');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales last month and this week'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_owner_can_use_screened_conversational_previous_month_sales_with_a_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Could we talk about how our sales are doing last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-14: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Could we talk about how our sales are doing last month and this week?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Could we talk about how our sales are doing last month for customer Ana?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_bare_previous_month_only_follows_an_answered_sales_question_in_the_owner_visit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-14: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales');

        Http::assertNothingSent();
    }

    public function test_owner_can_ask_for_prior_calendar_sales_without_sending_raw_wording_to_the_provider(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How were sales previous month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-14: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Prior week'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for weekly on 2026-11-07: 0 transactions totaling PHP 0.');

        Http::assertNothingSent();
    }

    public function test_damaged_adjustment_totals_are_owner_only_and_do_not_bypass_sharing_or_call_a_provider(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        config(['services.gemini.allow_store_data' => false]);
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertJsonPath('result.status', 'disabled');

        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (all time). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments')
            ->assertJsonMissingPath('result.visit_context');

        $this->actingAs($this->createUser('cashier'))->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_damaged_adjustment_months_return_local_sourced_counts_without_a_provider_call(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments this month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during November 2026). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during October 2026). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments this month and last month?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_stock_adjustment_summary_keeps_the_gate_and_reports_only_aggregate_directions_to_the_owner(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        config(['services.gemini.allow_store_data' => false]);
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments'])
            ->assertJsonPath('result.status', 'disabled');

        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (all time): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments');
        $this->actingAs($this->createUser('cashier'))->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_stock_adjustment_months_are_sourced_locally_and_keep_the_sharing_gate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        config(['services.gemini.allow_store_data' => false]);
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments this month'])
            ->assertJsonPath('result.status', 'disabled');

        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments this month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during November 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments last month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during October 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments this month and last month'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_bare_month_follows_only_an_answered_adjustment_in_the_same_owner_visit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during October 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments');

        $this->postJson(route('assistant.ask'), ['question' => 'Thanks'])
            ->assertJsonPath('result.status', 'social');
        $this->postJson(route('assistant.ask'), ['question' => 'This month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during November 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.');

        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertJsonPath('result.status', 'answered');
        $this->postJson(route('assistant.ask'), ['question' => 'What about last month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during October 2026). Reasons and product details are not shown.');
        $this->postJson(route('assistant.ask'), ['question' => 'Show damaged item reasons'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');

        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments?'])
            ->assertJsonPath('result.status', 'answered');
        config(['services.gemini.allow_store_data' => false]);
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'disabled');
        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');

        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Last month'])
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_natural_monthly_adjustment_phrases_use_local_aggregates_without_a_provider(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments for last month'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during October 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments were there this month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during November 2026). Reasons and product details are not shown.');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments were there this month for Synthetic Jacket?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_weekly_and_yearly_adjustment_summaries_remain_owner_gated_and_local(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        config(['services.gemini.allow_store_data' => false]);
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments this week'])
            ->assertJsonPath('result.status', 'disabled');

        config(['services.gemini.allow_store_data' => true]);
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments this week'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during week of November 9, 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments');
        $this->postJson(route('assistant.ask'), ['question' => 'Last week'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during week of November 2, 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments last year?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during 2025). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'Show stock adjustment reasons this week'])
            ->assertJsonPath('result.status', 'declined');
        Http::assertNothingSent();
    }

    public function test_daily_adjustment_totals_follow_only_verified_topics_and_do_not_bypass_sharing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        config(['services.gemini.allow_store_data' => false]);
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments today'])
            ->assertJsonPath('result.status', 'disabled');
        config(['services.gemini.allow_store_data' => true]);

        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Summarize stock adjustments today'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during November 14, 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded non-sales stock adjustments (during November 13, 2026): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.')
            ->assertJsonPath('result.sources.0.type', 'inventory_adjustments')
            ->assertJsonPath('result.sources.0.as_of', '2026-11-14');
        $this->postJson(route('assistant.ask'), ['question' => 'How many damaged item adjustments yesterday?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded damaged-item adjustments: 0 decreases, 0 units removed (during November 13, 2026). Reasons and product details are not shown.');
        $this->postJson(route('assistant.ask'), ['question' => 'Show damaged item reasons yesterday'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_owner_can_use_screened_prior_month_conversational_sales_with_the_fixed_local_fake(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Could we talk about how our sales are doing prior month?'])
            ->assertJsonPath('result.status', 'answered')
            ->assertJsonPath('result.message', 'Recorded sales for monthly on 2026-10-14: 0 transactions totaling PHP 0.')
            ->assertJsonPath('result.sources.0.type', 'sales');
        $this->postJson(route('assistant.ask'), ['question' => 'Could we talk about our sales for customer Ana prior month?'])
            ->assertJsonPath('result.status', 'declined');

        Http::assertNothingSent();
    }

    public function test_owner_can_phrase_the_latest_unpaid_sale_naturally_without_a_provider_call(): void
    {
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Could you pull up the most recent unpaid sale?'])
            ->assertExactJson(['result' => [
                'status' => 'answered',
                'message' => 'No pending unpaid sale with a positive balance was found.',
            ]]);
    }

    public function test_unpaid_clarification_can_follow_into_a_local_sale_without_a_provider_call(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Unpaid?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'The latest one'])
            ->assertExactJson(['result' => [
                'status' => 'answered',
                'message' => 'No pending unpaid sale with a positive balance was found.',
            ]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Aggregate'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_latest_pending_requires_yes_to_read_one_fictional_sale_and_never_sends_it_to_gemini(): void
    {
        config(['services.gemini.allow_store_data' => true, 'services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-CONFIRM-001',
            'sale_type' => 'walk_in',
            'online_platform' => '',
            'customer_name' => 'Synthetic Buyer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic address',
            'total_amount' => '35.00',
            'payment_status' => 'partially_paid',
            'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-07 10:00:00',
        ]);
        $sale->unpaidPayment()->create([
            'amount_paid' => '10.00', 'balance' => '25.00', 'payment_date' => null,
            'status' => 'pending', 'updated_by' => $owner->id,
        ]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending?'])
            ->assertExactJson(['result' => [
                'status' => 'clarification',
                'message' => 'Do you mean the latest pending unpaid sale? Reply yes to look it up, or no to cancel.',
            ]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'answered')
            ->assertSee('SYN-CONFIRM-001')
            ->assertSee('Synthetic Buyer')
            ->assertJsonMissingPath('result.visit_context');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_no_cancels_latest_pending_confirmation_and_an_unsafe_reply_clears_it(): void
    {
        config(['services.gemini.allow_store_data' => true, 'services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'No'])
            ->assertExactJson(['result' => ['status' => 'social', 'message' => "Okay, I won't look up a sale."]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes for Customer Ana'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending sales'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_latest_pending_confirmation_resets_on_new_chat_expiry_owner_change_and_logout(): void
    {
        $this->travelTo(now()->startOfMinute());
        $owner = $this->createUser('store_owner');
        $other = User::query()->create([
            'name' => 'Synthetic Other Owner', 'email' => 'confirm-other@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->get(route('assistant.index'))->assertOk();
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->actingAs($other)->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Latest pending'])
            ->assertJsonPath('result.status', 'clarification');
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_natural_unpaid_question_clarifies_locally_then_shows_only_the_latest_sale_without_a_provider_call(): void
    {
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Unpaid balances?'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Do you mean the aggregate unpaid balance or the latest pending sale? I can show either one.');
        $this->postJson(route('assistant.ask'), ['question' => 'The latest one'])
            ->assertExactJson(['result' => [
                'status' => 'answered',
                'message' => 'No pending unpaid sale with a positive balance was found.',
            ]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Aggregate'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_only_the_owner_sees_local_latest_sale_details_escaped_in_html_without_a_provider_call(): void
    {
        config(['services.gemini.allow_store_data' => true, 'services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-LOCAL',
            'sale_type' => 'walk_in',
            'online_platform' => '',
            'customer_name' => '<script>alert("synthetic")</script>',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic address',
            'total_amount' => '35.00',
            'payment_status' => 'partially_paid',
            'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-07 10:00:00',
        ]);
        $sale->unpaidPayment()->create([
            'amount_paid' => '10.00', 'balance' => '25.00', 'payment_date' => null,
            'status' => 'pending', 'updated_by' => $owner->id,
        ]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $response = $this->actingAs($owner)->post(route('assistant.ask'), ['question' => 'Show the latest unpaid sale']);

        $response->assertSee('SYN-LOCAL')
            ->assertSee('₱25.00')
            ->assertSee('&lt;script&gt;alert', false)
            ->assertDontSee('<script>alert("synthetic")</script>', false);
        $this->postJson(route('assistant.ask'), ['question' => 'The latest one'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_cashier_cannot_request_a_latest_sale_detail(): void
    {
        config(['services.gemini.allow_store_data' => true, 'services.gemini.allow_conversation' => true]);
        $cashier = $this->createUser('cashier');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($cashier)->postJson(route('assistant.ask'), ['question' => 'Show the latest unpaid sale'])
            ->assertForbidden();
        $this->postJson(route('assistant.ask'), ['question' => 'Unpaid balances?'])
            ->assertForbidden();
        $this->postJson(route('assistant.ask'), ['question' => 'Latest pending?'])
            ->assertForbidden();
        $this->postJson(route('assistant.ask'), ['question' => 'Yes'])
            ->assertForbidden();
    }

    public function test_new_chat_and_expiry_clear_only_the_pending_choice(): void
    {
        $this->travelTo(now()->startOfMinute());
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->get(route('assistant.index'))->assertViewMissing('result');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_local_chat_preserves_a_pending_choice_without_saving_social_text(): void
    {
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Hi!'])
            ->assertExactJson(['result' => [
                'status' => 'social',
                'message' => 'Hi! I am Maanyag. Happy to chat briefly or help with a supported store question.',
            ]]);
        $this->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'clarification')
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, last week, this month, last month, this year, or last year?');
    }

    public function test_a_pending_choice_cannot_survive_logout_or_cross_to_another_owner(): void
    {
        $owner = $this->createUser('store_owner');
        $other = User::query()->create([
            'name' => 'Synthetic Other Owner',
            'email' => 'synthetic-other-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->actingAs($other)->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Sales?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($other)->postJson(route('assistant.ask'), ['question' => 'Totals'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_an_unsafe_turn_clears_pending_clarification(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $owner = $this->createUser('store_owner');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()
                ->andReturn('{"style":"warm","suggested_skill":"none"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Unpaid?'])
            ->assertJsonPath('result.status', 'clarification');
        $this->postJson(route('assistant.ask'), ['question' => 'Unpaid for Customer Ana?'])
            ->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'Aggregate'])
            ->assertJsonPath('result.status', 'declined');
    }

    public function test_cashier_cannot_trigger_provider_conversation_even_with_the_conversation_gate_on(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $cashier = $this->createUser('cashier');
        $this->mock(GeminiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });

        $this->actingAs($cashier)->postJson(route('assistant.ask'), ['question' => 'Sales?'])->assertForbidden();
    }

    public function test_question_is_required(): void
    {
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->post(route('assistant.ask'), []);

        $response->assertSessionHasErrors('question');
    }

    public function test_question_is_limited_to_500_characters(): void
    {
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => str_repeat('a', 501),
        ]);

        $response->assertSessionHasErrors('question');
    }

    public function test_owner_can_follow_up_across_skills_without_exposing_visit_context_in_json(): void
    {
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->twice()->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return [
                    'status' => 'answered',
                    'message' => 'Synthetic answer for '.$question,
                    'visit_context' => ['intent' => $question === 'Show me sales for this month' ? 'sales_summary' : 'inventory_summary'],
                ];
            });
        });

        $first = $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $second = $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $first->assertExactJson(['result' => ['status' => 'answered', 'message' => 'Synthetic answer for Show me sales for this month']]);
        $second->assertExactJson(['result' => ['status' => 'answered', 'message' => 'Synthetic answer for What about inventory?']]);
        $this->assertSame([null, ['owner_id' => $owner->id, 'intent' => 'sales_summary']], $receivedContexts);
    }

    public function test_two_real_answer_requests_retrieve_fresh_skill_context_without_sharing_previous_turns(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 7)->startOfDay());
        config(['services.gemini.allow_store_data' => true]);
        $owner = $this->createUser('store_owner');

        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->twice()->andReturn(
                $this->syntheticConversationContext(2),
                $this->syntheticConversationContext(7),
            );
        });

        $prompts = [];
        $this->mock(GeminiClient::class, function (MockInterface $mock) use (&$prompts): void {
            $mock->shouldReceive('generateText')->twice()->andReturnUsing(function (string $prompt) use (&$prompts): string {
                $prompts[] = $prompt;

                return json_encode(count($prompts) === 1
                    ? [
                        'answer' => 'Monthly sales totaled PHP 125.00 across 4 transactions.',
                        'answer_type' => 'calculation',
                        'sources' => [['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-07']],
                        'confidence' => 'high',
                        'refusal' => false,
                    ]
                    : [
                        'answer' => 'There are 7 units in current stock.',
                        'answer_type' => 'fact',
                        'sources' => [['type' => 'inventory', 'label' => 'Inventory summary', 'as_of' => '2026-11-07']],
                        'confidence' => 'high',
                        'refusal' => false,
                    ], JSON_THROW_ON_ERROR);
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertJsonPath('result.status', 'answered');
        $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?'])
            ->assertJsonPath('result.message', 'There are 7 units in current stock.');

        $this->assertCount(2, $prompts);
        $this->assertStringContainsString('"total_units": 7', $prompts[1]);
        $this->assertStringNotContainsString('Monthly sales totaled', $prompts[1]);
        $this->assertStringNotContainsString('What about inventory?', $prompts[1]);
        $this->assertStringNotContainsString('transaction_count', $prompts[1]);
    }

    public function test_returning_to_the_assistant_resets_visit_context(): void
    {
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->twice()->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return ['status' => 'answered', 'message' => 'Synthetic answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->get(route('assistant.index'))->assertViewMissing('result');
        $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $this->assertSame([null, null], $receivedContexts);
    }

    public function test_visit_context_expires_when_the_owner_is_inactive(): void
    {
        $this->travelTo(now()->startOfMinute());
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->twice()->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return ['status' => 'answered', 'message' => 'Synthetic answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->travel(21)->minutes();
        $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $this->assertSame([null, null], $receivedContexts);
    }

    public function test_logging_out_does_not_restore_a_previous_visit_context(): void
    {
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->twice()->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return ['status' => 'answered', 'message' => 'Synthetic answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $this->assertSame([null, null], $receivedContexts);
    }

    public function test_visit_context_is_not_shared_with_a_different_owner_in_the_same_browser_session(): void
    {
        $firstOwner = $this->createUser('store_owner');
        $secondOwner = User::query()->create([
            'name' => 'Synthetic Other Owner',
            'email' => 'other-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->twice()->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return ['status' => 'answered', 'message' => 'Synthetic answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($firstOwner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->actingAs($secondOwner)->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $this->assertSame([null, null], $receivedContexts);
    }

    public function test_unanswered_questions_do_not_retain_visit_context(): void
    {
        $owner = $this->createUser('store_owner');
        $receivedContexts = [];

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock) use (&$receivedContexts): void {
            $mock->shouldReceive('answer')->times(3)->andReturnUsing(function (string $question, mixed $date, ?array $context) use (&$receivedContexts): array {
                $receivedContexts[] = $context;

                return $question === 'What about the most recent one?'
                    ? ['status' => 'declined', 'message' => 'That reference is ambiguous.']
                    : ['status' => 'answered', 'message' => 'Synthetic answer.', 'visit_context' => ['intent' => 'sales_summary']];
            });
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Show me sales for this month']);
        $this->postJson(route('assistant.ask'), ['question' => 'What about the most recent one?'])->assertJsonPath('result.status', 'declined');
        $this->postJson(route('assistant.ask'), ['question' => 'What about inventory?']);

        $this->assertSame([null, ['owner_id' => $owner->id, 'intent' => 'sales_summary'], null], $receivedContexts);
    }

    public function test_cashier_cannot_submit_a_conversational_json_request(): void
    {
        $cashier = $this->createUser('cashier');

        $this->actingAs($cashier)->postJson(route('assistant.ask'), ['question' => 'What about inventory?'])->assertForbidden();
    }

    public function test_invalid_conversational_json_question_is_rejected(): void
    {
        $owner = $this->createUser('store_owner');

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticConversationContext(int $totalUnits): array
    {
        return [
            'sales' => [
                'period' => 'monthly',
                'date' => '2026-11-07',
                'summary' => ['transaction_count' => 4, 'total_sales' => 125.0, 'average_sale' => 31.25],
            ],
            'inventory' => ['stats' => ['product_count' => 1, 'total_units' => $totalUnits, 'stock_value' => 30.0]],
            'as_of' => ['sales' => '2026-11-07', 'inventory' => '2026-11-07'],
            'scopes' => ['sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.'],
        ];
    }

    private function createUser(string $role): User
    {
        return User::query()->create([
            'name' => 'Synthetic '.ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => $role,
            'profile_image' => '',
            'status' => 'active',
        ]);
    }
}
