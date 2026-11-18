<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssistantAgenticTurnService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantAgenticTurnServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'fictional-key',
            'services.gemini.allow_conversation' => true,
            'services.gemini.allow_store_data' => true,
            'assistant.send_store_results_to_gemini' => false,
        ]);
        $this->actingAs(User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test', 'password' => 'synthetic-password',
            'phone' => '0000000000', 'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]));
        Http::preventStrayRequests();
    }

    public function test_chat_reply_uses_screened_history_and_no_store_facts(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Hey, I am here with you."}']]]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('Hey there');

        $this->assertSame('general_chat', $result['status']);
        $this->assertSame('Hey, I am here with you.', $result['message']);
        $this->assertSame('Hey there', $result['visit_context']['chat_history'][0]['turn']);
        Http::assertSent(fn (Request $request): bool => $request['store'] === false
            && $request['generation_config']['tool_choice'] === 'auto'
            && str_contains($request['input'][0]['content'][0]['text'], 'Hey there'));
    }

    public function test_chat_reply_accepts_a_single_json_code_fence_from_gemini(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => "```json\n{\"kind\":\"chat\",\"reply\":\"Hi there!\"}\n```"]]]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('Hey Maanyag');

        $this->assertSame('general_chat', $result['status']);
        $this->assertSame('Hi there!', $result['message']);
    }

    public function test_clarification_retains_an_explicit_period_for_a_natural_follow_up(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store area would you like to check?","pending":"store_goal","period":"last_month"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month']]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('How did we do last month?', routingOnly: true);
        $second = $service->answer('Sales, please', visitContext: $first['visit_context'], routingOnly: true);

        $this->assertSame('clarification', $first['status']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $first['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $second['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '"pending_clarification_period":"last_month"'));
    }

    public function test_invalid_clarification_period_is_not_kept_as_visit_context(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store area?","pending":"store_goal","period":"some_day"}']]]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did the shop do?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'store_goal'], $result['visit_context']);
    }

    public function test_private_credential_is_blocked_before_a_provider_call(): void
    {
        Http::fake();

        $result = app(AssistantAgenticTurnService::class)->answer('password: fictional-secret');

        $this->assertSame('declined', $result['status']);
        Http::assertNothingSent();
    }

    public function test_valid_store_tool_is_selected_without_releasing_facts_to_gemini(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_1', 'name' => 'unpaid_aggregate', 'arguments' => (object) []]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('How much is outstanding overall?');

        $this->assertSame('answered', $result['status']);
        $this->assertSame('unpaid_summary', $result['sources'][0]['type']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! str_contains(json_encode($request->data()), 'outstanding_balance'));
    }

    public function test_invalid_tool_arguments_receive_one_structured_error_and_no_store_result(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_2', 'name' => 'sales_summary', 'arguments' => ['period' => 'today', 'customer' => 'Fictional']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Please ask for overall sales without a customer filter."}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show overall sales for a fictional customer');

        $this->assertSame('clarification', $result['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['generation_config']['tool_choice'] ?? null) === 'none'
            && str_contains(json_encode($request['input']), 'unsupported_scope')
            && ! str_contains(json_encode($request['input']), 'outstanding_balance'));
    }

    public function test_local_only_adjustment_tool_never_returns_its_result_to_gemini(): void
    {
        config(['assistant.send_store_results_to_gemini' => true]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_3', 'name' => 'adjustment_totals', 'arguments' => ['kind' => 'damaged', 'period' => 'today']]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('How many damaged-item adjustments today?');

        $this->assertSame('answered', $result['status']);
        $this->assertSame('inventory_adjustments', $result['sources'][0]['type']);
        Http::assertSentCount(1);
    }

    public function test_individual_unpaid_sale_requires_confirmation_and_stays_local(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_private', 'name' => 'latest_unpaid_sale', 'arguments' => (object) []]],
        ])]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('Show me the latest unpaid sale.');
        $second = $service->answer('Yes, show it here.', visitContext: $first['visit_context']);

        $this->assertSame('clarification', $first['status']);
        $this->assertSame('latest_pending_sale_confirmation', $first['visit_context']['pending']);
        $this->assertSame('answered', $second['status']);
        $this->assertSame('No pending unpaid sale with a positive balance was found.', $second['message']);
        Http::assertSentCount(2);
    }

    public function test_stateless_fact_continuation_preserves_thought_and_falls_back_on_invented_number(): void
    {
        config(['assistant.send_store_results_to_gemini' => true, 'assistant.paid_tier_reviewed' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Manila'));
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [
                ['type' => 'thought', 'signature' => 'fictional-signature'],
                ['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'today']],
            ]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Recorded sales: 9999 transactions.']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('What were our sales today?');

        $this->assertSame('answered', $result['status']);
        $this->assertStringNotContainsString('9999', $result['message']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['generation_config']['tool_choice'] ?? null) === 'none'
            && ($request['input'][1]['signature'] ?? null) === 'fictional-signature'
            && ($request['input'][3]['type'] ?? null) === 'function_result'
            && $request['store'] === false);
    }
}
