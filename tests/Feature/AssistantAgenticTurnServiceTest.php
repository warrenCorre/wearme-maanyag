<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantAgenticTurnService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'assistant.agentic_scope_audit' => false,
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

    public function test_store_questions_still_work_when_public_chat_history_is_full(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_inventory', 'name' => 'inventory_summary', 'arguments' => ['metric_evidence' => 'inventory']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Happy to chat!"}']]]]]);
        $history = array_fill(0, 40, ['turn' => 'Hey there', 'reply' => 'Good to see you']);
        $service = app(AssistantAgenticTurnService::class);

        $store = $service->answer('Show me overall inventory', visitContext: ['chat_history' => $history], routingOnly: true);
        $chat = $service->answer('Hello again', visitContext: ['chat_history' => $history]);

        $this->assertSame(['tool' => 'inventory_summary', 'args' => []], $store['evaluation']);
        $this->assertSame('general_chat', $chat['status']);
        $this->assertStringContainsString('Start a New Chat', $chat['message']);
        $this->assertSame($history, $chat['visit_context']['chat_history']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $input = json_decode($request['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], $input['screened_public_chat_history']);
        }
    }

    public function test_provider_failure_logs_only_stage_status_type_and_latency(): void
    {
        Log::spy();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Synthetic sensitive provider message']], 503)]);

        $result = app(AssistantAgenticTurnService::class)->answer('Hello there');

        $this->assertSame('unavailable', $result['status']);
        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Assistant agentic provider failed'
                && array_keys($context) === ['stage', 'http_status', 'exception', 'latency_ms']
                && $context['stage'] === 'tool_routing'
                && $context['http_status'] === 503
                && $context['exception'] === RequestException::class
                && is_int($context['latency_ms']);
        });
    }

    public function test_fast_routing_failure_recovers_into_a_model_written_chat_reply(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Synthetic outage']], 503)
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Good to hear from you."}']]]]])]);

        $result = app(AssistantAgenticTurnService::class)->answer('Hello there');

        $this->assertSame('general_chat', $result['status']);
        $this->assertSame('Good to hear from you.', $result['message']);
        Http::assertSentCount(2);
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
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('How did we do last month?', routingOnly: true);
        $second = $service->answer('Sales, please', visitContext: $first['visit_context'], routingOnly: true);

        $this->assertSame('clarification', $first['status']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $first['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $second['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '"pending_clarification_period":"last_month"'));
    }

    public function test_broad_store_clarification_keeps_the_screened_period_when_gemini_omits_it(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store measure would you like?","pending":"store_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('How did we do last month?', routingOnly: true);
        $second = $service->answer('Sales, please', visitContext: $first['visit_context'], routingOnly: true);

        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $first['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $second['evaluation']);
        Http::assertSentCount(2);
    }

    public function test_repeated_measure_clarifications_keep_the_validated_period_until_a_clear_tool_request(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store measure?","pending":"store_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which totals do you mean?","pending":"store_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Sales totals', 'period_evidence' => '']]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('How did we do last month?', routingOnly: true);
        $second = $service->answer('Just the totals please', visitContext: $first['visit_context'], routingOnly: true);
        $third = $service->answer('Sales totals, please', visitContext: $second['visit_context'], routingOnly: true);

        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $first['visit_context']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $second['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $third['evaluation']);
        Http::assertSentCount(3);
    }

    public function test_a_direct_sales_period_clarification_is_audited_against_the_known_period(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"Sales","clarification":"Which sales measure would you like for last month?","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month']);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('Which sales measure would you like for last month?', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertFalse(isset($requests[1]['tools']));
        $this->assertSame('application/json', $requests[1]['response_format']['mime_type']);
        $this->assertStringContainsString('"trusted_pending_store_period":"last_month"', $requests[1]['input']);
        $this->assertTrue($requests[1]['store'] === false);
    }

    public function test_a_clear_sales_total_is_answerable_after_the_model_repeats_the_period_question(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month'], routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $result['evaluation']);
        Http::assertSentCount(2);
    }

    public function test_an_audited_known_period_sales_answer_keeps_verified_facts_local(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals, please', CarbonImmutable::parse('2026-11-22', 'Asia/Manila'), ['pending' => 'store_goal', 'period' => 'last_month']);

        $this->assertSame('answered', $result['status']);
        $this->assertStringContainsString('Recorded sales for October 2026: 0 transactions totaling PHP 0.00.', $result['message']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && ! str_contains($request['input'], 'PHP 0.00'));
    }

    public function test_audited_known_period_sales_does_not_drop_an_unsupported_grouping(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"I cannot split those sales by cashier. Please ask for an overall total instead.","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals by cashier, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month']);

        $this->assertSame('clarification', $result['status']);
        $this->assertStringContainsString('cannot split', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_audited_known_period_sales_keeps_a_validated_overall_offer(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which period?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"I cannot split sales by cashier. Want the overall total for last month?","offer_ungrouped":true,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals by cashier, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month']);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'offered_tool', 'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'last_month']]], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_rejected_sales_period_call_keeps_the_known_period_for_a_measure_clarification(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"Sales","clarification":"Did you mean sales totals or another sales measure for last month?","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month']);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('Did you mean sales totals or another sales measure for last month?', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_scope_audit_receives_the_validated_period_from_a_broad_store_clarification(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store measure would you like?","pending":"store_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('How did we do last month?', routingOnly: true);
        $second = $service->answer('Sales, please', visitContext: $first['visit_context'], routingOnly: true);

        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $first['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $second['evaluation']);
        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertStringContainsString('"trusted_pending_store_period":"last_month"', $requests[2]['input']);
        Http::assertSentCount(3);
    }

    public function test_broad_store_clarification_does_not_trust_an_invented_model_period(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store measure would you like?","pending":"store_goal","period":"last_month"}']]]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did we do?', routingOnly: true);

        $this->assertSame(['pending' => 'store_goal'], $result['visit_context']);
    }

    public function test_pending_sales_period_supplies_only_the_sales_measure_for_a_period_reply(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'yesterday', 'metric_evidence' => '', 'period_evidence' => 'Yesterday']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer(
            'Yesterday would be great', visitContext: ['pending' => 'sales_period'], routingOnly: true,
        );

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'yesterday']], $result['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'])
            && str_contains($request['input'], '"trusted_pending_clarification":"sales_period"'));
    }

    public function test_a_new_reporting_period_reuses_only_an_answered_sales_summary(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        $call = ['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => [
            'period' => 'last_month', 'metric_evidence' => '', 'period_evidence' => 'previous month',
        ]]]];
        Http::fakeSequence()
            ->push($call)
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]])
            ->push($call)
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which store measure do you want for that period?"}']]]]]);
        $service = app(AssistantAgenticTurnService::class);

        $sales = $service->answer('Could you show the previous month too?', visitContext: ['intent' => 'sales_summary', 'store_topic' => 'sales'], routingOnly: true);
        $ranking = $service->answer('Could you show the previous month too?', visitContext: ['intent' => 'best_sellers', 'store_topic' => 'sales'], routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $sales['evaluation']);
        $this->assertSame('clarification', $ranking['status']);
        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertStringContainsString('"trusted_previous_measure":"sales_summary"', $requests[1]['input']);
        $this->assertStringContainsString('"missing":"measure"', $requests[3]['input']);
    }

    public function test_invalid_clarification_period_is_not_kept_as_visit_context(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which store area?","pending":"store_goal","period":"some_day"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which part of the shop would you like to check?","pending":"store_goal"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did the shop do?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'store_goal'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_tool_call_cannot_invent_a_store_measure_missing_from_the_turn(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'sales', 'period_evidence' => 'last month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which store measure would you like to check?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did our shop do last month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_generic_shop_quote_cannot_justify_a_sales_tool(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'our shop do last month', 'period_evidence' => 'last month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which store measure did you mean?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did our shop do last month?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('Which store measure did you mean?', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['system_instruction'], 'current Owner turn is in English'));
        Http::assertSent(fn (Request $request): bool => ($request['response_format']['mime_type'] ?? null) === 'application/json'
            && ! isset($request['tools']) && $request['store'] === false
            && ! str_contains($request['input'], 'rejected_tool')
            && str_contains($request['input'], '"known_period":"last_month"'));
    }

    public function test_quantity_only_evidence_does_not_turn_a_broad_follow_up_into_sales(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'Just the totals please', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which store measure do you mean by totals?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Just the totals please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month'], routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('Which store measure do you mean by totals?', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_quantity_only_evidence_can_select_sales_totals_after_a_sales_goal_clarification(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'Just the totals please', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which reporting period would you like?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Just the totals please', visitContext: ['pending' => 'sales_goal'], routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('Which reporting period would you like?', $result['message']);
        $this->assertSame(['pending' => 'sales_period'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_short_generic_quote_does_not_hide_an_explicit_sales_measure_in_the_turn(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'totals', 'period_evidence' => '']]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month'], routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $result['evaluation']);
        Http::assertSentCount(1);
    }

    public function test_scope_audit_can_verify_an_explicit_sales_turn_when_the_model_omits_its_metric_quote(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => '', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales totals, please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month'], routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'last_month']], $result['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"trusted_pending_store_period":"last_month"'));
    }

    public function test_scope_audit_cannot_rescue_a_quantity_only_turn_without_a_store_measure(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => '', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which store measure do you mean?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Just the totals please', visitContext: ['pending' => 'store_goal', 'period' => 'last_month'], routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['response_format']['mime_type'] ?? null) === 'application/json'
            && str_contains($request['input'], '"missing":"measure"'));
    }

    public function test_a_generic_cebuano_shop_quote_asks_for_a_measure_in_the_current_language(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'atong tindahan niaging bulan', 'period_evidence' => 'niaging bulan']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Unsa nga bahin ang gusto nimong susihon?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Kumusta atong tindahan niaging bulan?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        $this->assertSame('Unsa nga bahin ang gusto nimong susihon?', $result['message']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['system_instruction'], 'current Owner turn is in Cebuano'));
    }

    public function test_invalid_structured_clarification_falls_back_without_claiming_a_store_fact(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'last_month', 'metric_evidence' => 'sales', 'period_evidence' => 'last month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"There were 99 sales."}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('How did our shop do last month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertStringNotContainsString('99', $result['message']);
        $this->assertSame(['pending' => 'store_goal', 'period' => 'last_month'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_an_english_sales_command_gets_a_language_hint_without_forcing_english_on_taglish(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which sales period would you like?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Anong sales period ang gusto mo?","pending":"sales_period"}']]]]]);
        $service = app(AssistantAgenticTurnService::class);

        $this->assertSame('Which sales period would you like?', $service->answer('Show me total sales')['message']);
        $this->assertSame('Anong sales period ang gusto mo?', $service->answer('Show me po ang total sales')['message']);

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertStringContainsString('current Owner turn is in English', $requests[0]['system_instruction']);
        $this->assertStringNotContainsString('Reply in English only', $requests[1]['system_instruction']);
    }

    public function test_a_sales_follow_up_cannot_assume_a_period_when_none_was_kept(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'Sales', 'period_evidence' => '']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Which reporting period would you like?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Sales, please', visitContext: ['pending' => 'store_goal']);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'sales_period'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_an_offered_aggregate_can_be_confirmed_without_retyping_its_measure_or_period(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I can show total sales this month instead. Want that?","pending":"offered_tool","offer":{"tool":"sales_summary","arguments":{"period":"this_month"}}}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => '', 'period_evidence' => '']]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('Show sales by cashier this month?', routingOnly: true);
        $second = $service->answer('Yes, please', visitContext: $first['visit_context'], routingOnly: true);

        $this->assertSame('clarification', $first['status']);
        $this->assertSame(['pending' => 'offered_tool', 'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']]], $first['visit_context']);
        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'this_month']], $second['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '"pending_offer":{"tool":"sales_summary"'));
    }

    public function test_a_conversational_opener_does_not_turn_an_overall_sales_request_into_a_grouping(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('By the way, how have sales been this month?', routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'this_month']], $result['evaluation']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], 'By the way, how have sales been this month?')
            && str_contains($request['input'], '"selected_tool":"sales_summary"'));
    }

    public function test_a_grouped_sales_request_cannot_silently_run_the_ungrouped_tool(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"I cannot split sales by cashier, but I can show the total this month. Want that?","offer_ungrouped":true,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('offered_tool', $result['visit_context']['pending']);
        $this->assertSame(['period' => 'this_month'], $result['visit_context']['offer']['arguments']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"selected_tool":"sales_summary"')
            && str_contains($request['input'], 'Show sales by cashier this month?'));
    }

    public function test_an_unparseable_direct_reply_recovers_with_a_screened_scope_clarification(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'I cannot split that request.']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I cannot break sales down by cashier. Could you ask for the overall total instead?","pending":"scope_goal"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('I cannot break sales down by cashier. Could you ask for the overall total instead?', $result['message']);
        $this->assertSame(['pending' => 'scope_goal'], $result['visit_context']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"current_screened_turn":"Show sales by cashier this month?"')
            && str_contains($request['input'], '"approved_read_only_tools"')
            && ! str_contains($request['input'], 'screened_public_chat_history')
            && ! isset($request['tools'])
            && $request['store'] === false);
    }

    public function test_an_unparseable_social_reply_recovers_as_chat_without_a_store_clarification(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Hello there!']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"chat","reply":"Hey, I am here with you.","pending":"none"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Hey there');

        $this->assertSame('general_chat', $result['status']);
        $this->assertSame('Hey, I am here with you.', $result['message']);
        $this->assertSame('Hey there', $result['visit_context']['chat_history'][0]['turn']);
        Http::assertSentCount(2);
    }

    public function test_an_invalid_recovery_reply_is_unavailable_without_store_facts(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Unstructured response']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":123,"pending":"scope_goal"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?', routingOnly: true);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('Sorry, I’m having trouble continuing our chat right now. Please try again in a moment.', $result['message']);
        Http::assertSentCount(2);
    }

    public function test_a_grouped_sales_offer_keeps_the_requested_period(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"I cannot split sales by cashier. Want the overall total this month?","offer_ungrouped":true,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']], $result['visit_context']['offer']);
        $this->assertStringNotContainsString('last month', $result['message']);
    }

    public function test_a_model_cannot_offer_an_individual_sale_as_an_implicit_follow_up(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Want the latest sale?","pending":"offered_tool","offer":{"tool":"latest_unpaid_sale","arguments":{}}}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I cannot provide sales by cashier. What supported store summary would you like?","pending":"scope_goal"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal'], $result['visit_context']);
        $this->assertStringNotContainsString('latest sale', $result['message']);
        Http::assertSentCount(2);
    }

    public function test_scope_audit_rejects_a_category_qualifier_dropped_by_a_valid_tool_call(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'running low']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"jacket category","clarification":"I cannot narrow low stock to jackets. Want the overall list?","offer_ungrouped":true,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('What is running low in the jacket category?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'offered_tool', 'offer' => ['tool' => 'low_stock', 'arguments' => []], 'reply_language' => 'en'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_an_english_scope_offer_is_corrected_when_the_auditor_replies_in_cebuano(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"Dili namo makita ang sales kada cashier karon. Gusto ba nimo tan-awon ang kinatibuk-ang sales karong bulana?","offer_ungrouped":true,"language":"ceb"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"I cannot break sales down by cashier. Would you like the overall sales total this month?"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('I cannot break sales down by cashier. Would you like the overall sales total this month?', $result['message']);
        $this->assertSame('en', $result['visit_context']['reply_language']);
        $this->assertSame('offered_tool', $result['visit_context']['pending']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"requested_language":"en"')
            && str_contains($request['input'], '"unsupported_part":"by cashier"')
            && ! str_contains($request['input'], 'Dili namo makita')
            && $request['store'] === false);
    }

    public function test_the_independent_audit_can_accept_only_a_validated_pending_public_offer(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Yes, please.', visitContext: [
            'pending' => 'offered_tool',
            'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']],
        ]);

        $this->assertSame('answered', $result['status']);
        $this->assertSame('sales', $result['sources'][0]['type']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"trusted_pending_offer":{"tool":"sales_summary"')
            && ! isset($request['tools'])
            && $request['store'] === false);
    }

    public function test_a_direct_clarification_offer_is_reconciled_without_using_a_wording_list(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I cannot split sales by cashier, but I can show the overall sales summary for this month. Want that instead?","pending":"scope_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"tool":"sales_summary","evidence":"overall sales summary for this month","period_evidence":"this month"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $service = app(AssistantAgenticTurnService::class);

        $first = $service->answer('Show sales by cashier this month?');
        $second = $service->answer('Yes, please.', visitContext: $first['visit_context']);

        $this->assertSame('clarification', $first['status']);
        $this->assertSame(['pending' => 'offered_tool', 'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']], 'reply_language' => 'en'], $first['visit_context']);
        $this->assertSame('answered', $second['status']);
        $this->assertSame('sales', $second['sources'][0]['type']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'] ?? null)
            && str_contains($request['input'], '"assistant_clarification"')
            && str_contains($request['input'], '"approved_read_only_tools"')
            && ! str_contains($request['input'], 'total_sales')
            && $request['store'] === false);
    }

    public function test_a_clarification_without_an_explicit_public_offer_stays_a_scope_question(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I cannot provide that cashier breakdown. Could you ask for a supported store summary?","pending":"scope_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"tool":"none","evidence":"","period_evidence":""}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_an_offered_individual_detail_cannot_be_reconciled_as_a_public_tool(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I can show the latest unpaid sale instead. Want that?","pending":"scope_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"tool":"latest_unpaid_sale","evidence":"latest unpaid sale","period_evidence":""}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?');

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_changed_or_filtered_follow_up_cannot_execute_the_pending_offer(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"I cannot provide a cashier breakdown.","offer_ungrouped":false,"language":"en"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"I cannot break that down by cashier. Would you like the overall total?","pending":"scope_goal"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Yes, please, but by cashier.', visitContext: [
            'pending' => 'offered_tool',
            'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']],
        ]);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal'], $result['visit_context']);
        Http::assertSentCount(2);
    }

    public function test_a_private_follow_up_cannot_consume_a_pending_public_offer(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fake();

        $result = app(AssistantAgenticTurnService::class)->answer('My password is secret123. Yes, please.', visitContext: [
            'pending' => 'offered_tool',
            'offer' => ['tool' => 'sales_summary', 'arguments' => ['period' => 'this_month']],
        ]);

        $this->assertNotSame('answered', $result['status']);
        Http::assertNothingSent();
    }

    public function test_a_failed_scope_language_correction_uses_a_safe_english_fallback(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"by cashier","clarification":"Dili namo makita ang sales kada cashier karon. Gusto ba nimo tan-awon ang kinatibuk-ang sales karong bulana?","offer_ungrouped":true,"language":"ceb"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"reply":"Dili namo makita ang sales kada cashier karon."}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales by cashier this month?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame('I cannot verify “by cashier” with this store summary. Please restate the full question for an overall view.', $result['message']);
        $this->assertSame(['pending' => 'scope_goal', 'reply_language' => 'en'], $result['visit_context']);
        Http::assertSentCount(3);
    }

    public function test_scope_audit_accepts_a_complete_sales_tool_call(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        $responses = Http::sequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $timeouts = [];
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request, array $options) use ($responses, &$timeouts) {
            $timeouts[] = $options['timeout'];

            return $responses($request);
        }]);

        $result = app(AssistantAgenticTurnService::class)->answer('Show sales this month', routingOnly: true);

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'this_month']], $result['evaluation']);
        $this->assertSame([18, 7], $timeouts);
        Http::assertSentCount(2);
    }

    public function test_scope_audit_keeps_an_unsupported_scope_distinct_when_no_safe_offer_exists(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'almost sold out']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"tops","clarification":"Would you like me to filter the low stock items by tops?","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Which tops are almost sold out?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal', 'reply_language' => 'en'], $result['visit_context']);
        $this->assertSame('I cannot verify “tops” with this store summary. Please restate the full question for an overall view.', $result['message']);
        $this->assertStringNotContainsString('Would you like me to filter', $result['message']);
        Http::assertSentCount(2);
    }

    public function test_unsupported_scope_fallback_uses_cebuano_without_promising_the_filter(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'hapit mahurot']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"jacket","clarification":"Would you like me to filter by jacket?","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer('Unsa nga jacket ang hapit mahurot?', routingOnly: true);

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal', 'reply_language' => 'ceb'], $result['visit_context']);
        $this->assertStringContainsString('Dili nako maapil ang “jacket”', $result['message']);
        Http::assertSentCount(2);
    }

    public function test_a_catalog_product_qualifier_cannot_turn_into_an_overall_low_stock_answer(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        $owner = User::query()->where('role', 'store_owner')->firstOrFail();
        $category = Category::query()->create(['category_name' => 'Synthetic outerwear', 'status' => 'active']);
        Product::query()->create([
            'product_code' => 'SYN-JACKET', 'product_name' => 'Synthetic Denim Jacket',
            'category_id' => $category->id, 'description' => 'Fictional product',
            'price' => 10, 'quantity' => 2, 'low_stock_threshold' => 1,
            'image' => '', 'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'low-stock']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_low', 'name' => 'low_stock', 'arguments' => ['metric_evidence' => 'low-stock']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);
        $service = app(AssistantAgenticTurnService::class);

        $filtered = $service->answer('Pila ka low-stock nga jacket karon sa tindahan?', routingOnly: true);
        $overall = $service->answer('Show the overall low-stock products', routingOnly: true);

        $this->assertSame('clarification', $filtered['status']);
        $this->assertStringContainsString('Dili nako maapil ang “jacket”', $filtered['message']);
        $this->assertSame(['tool' => 'low_stock', 'args' => []], $overall['evaluation']);
        Http::assertSentCount(3);
    }

    public function test_scope_audit_ignores_an_old_offer_when_the_owner_explicitly_switches_store_topics(): void
    {
        config(['assistant.agentic_scope_audit' => true]);
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":false,"uncovered":"sales","clarification":"You asked about sales instead.","offer_ungrouped":false,"language":"en"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'this_month', 'metric_evidence' => 'sales', 'period_evidence' => 'this month']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $result = app(AssistantAgenticTurnService::class)->answer(
            'Show me total sales for this month, please.',
            visitContext: ['pending' => 'offered_tool', 'offer' => ['tool' => 'low_stock', 'arguments' => []]],
            routingOnly: true,
        );

        $this->assertSame(['tool' => 'sales_summary', 'args' => ['period' => 'this_month']], $result['evaluation']);
        Http::assertSent(fn (Request $request): bool => is_string($request['input'])
            && str_contains($request['input'], '"trusted_pending_offer":null'));
    }

    public function test_a_short_ambiguous_follow_up_uses_the_validated_previous_reply_language(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text', 'text' => '{"kind":"clarification","reply":"Please restate the full store question.","pending":"scope_goal"}',
            ]]]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer(
            'Yes, please', visitContext: ['pending' => 'scope_goal', 'reply_language' => 'en'], routingOnly: true,
        );

        $this->assertSame('clarification', $result['status']);
        $this->assertSame(['pending' => 'scope_goal', 'reply_language' => 'en'], $result['visit_context']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['system_instruction'], 'previous clarification was in English')
            && str_contains($request['input'][0]['content'][0]['text'], '"pending_reply_language":"en"'));
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
            'status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_1', 'name' => 'unpaid_aggregate', 'arguments' => ['metric_evidence' => 'outstanding']]],
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
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_2', 'name' => 'sales_summary', 'arguments' => ['period' => 'today', 'customer' => 'Fictional', 'metric_evidence' => 'sales', 'period_evidence' => '']]]])
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
            'status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_3', 'name' => 'adjustment_totals', 'arguments' => ['kind' => 'damaged', 'period' => 'today', 'metric_evidence' => 'damaged-item adjustments', 'period_evidence' => 'today']]],
        ])]);

        $result = app(AssistantAgenticTurnService::class)->answer('How many damaged-item adjustments today?');

        $this->assertSame('answered', $result['status']);
        $this->assertSame('inventory_adjustments', $result['sources'][0]['type']);
        Http::assertSentCount(1);
    }

    public function test_individual_unpaid_sale_requires_confirmation_and_stays_local(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_private', 'name' => 'latest_unpaid_sale', 'arguments' => ['metric_evidence' => 'latest unpaid sale']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_private', 'name' => 'latest_unpaid_sale', 'arguments' => ['metric_evidence' => '']]]]);
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
                ['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'today', 'metric_evidence' => 'sales', 'period_evidence' => 'today']],
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
