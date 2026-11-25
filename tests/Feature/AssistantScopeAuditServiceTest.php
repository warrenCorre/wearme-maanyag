<?php

namespace Tests\Feature;

use App\Services\AssistantScopeAuditService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AssistantScopeAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.api_key' => 'fictional-key']);
        Http::preventStrayRequests();
    }

    public function test_a_missing_category_filter_is_flagged_without_retrieving_store_facts(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text',
                'text' => '{"covered":false,"uncovered":"jacket category","clarification":"I cannot filter low stock by jacket yet. Want the overall list?","offer_ungrouped":true,"language":"en"}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'What is running low in the jacket category?', 'low_stock', [],
            'Top ten low-stock products across all categories. No category filter.', null,
        );

        $this->assertSame(['covered' => false, 'unavailable' => false, 'message' => 'I cannot filter low stock by jacket yet. Want the overall list?', 'uncovered' => 'jacket category', 'offer_ungrouped' => true, 'language' => 'en'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['store'] === false
            && str_contains($request['input'], 'jacket category')
            && ! str_contains($request['input'], 'customer_name')
            && ! str_contains($request['input'], 'total_sales'));
    }

    public function test_a_complete_periodic_tool_call_passes_the_scope_audit(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text',
                'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Show sales this month', 'sales_summary', ['period' => 'this_month'],
            'Recorded sales count and total for one Philippine-time period.', null,
        );

        $this->assertTrue($result['covered']);
        $this->assertNull($result['message']);
    }

    public function test_a_pending_sales_period_is_sent_without_store_facts(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Yesterday would be great', 'sales_summary', ['period' => 'yesterday'],
            'Recorded sales count and total for one Philippine-time period.', null, 'sales_period',
        );

        $this->assertTrue($result['covered']);
        Http::assertSent(fn (Request $request): bool => $request['store'] === false
            && str_contains($request['input'], '"trusted_pending_clarification":"sales_period"')
            && ! str_contains($request['input'], 'total_sales'));
    }

    public function test_a_pending_store_period_is_sent_without_the_prior_question_or_store_facts(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text', 'text' => '{"covered":false,"uncovered":"Sales","clarification":"Which sales measure would you like?","offer_ungrouped":false,"language":"en"}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Sales, please', 'sales_summary', ['period' => 'last_month'],
            'Recorded sales count and total for one Philippine-time period.', null,
            pendingStorePeriod: 'last_month',
        );

        $this->assertFalse($result['covered']);
        Http::assertSent(fn (Request $request): bool => $request['store'] === false
            && str_contains($request['input'], '"trusted_pending_store_period":"last_month"')
            && ! str_contains($request['input'], 'How did we do last month?')
            && ! str_contains($request['input'], 'total_sales')
            && str_contains($request['system_instruction'], 'A broad mention of sales'));
    }

    public function test_a_clear_sales_total_can_use_the_validated_pending_store_period(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Sales totals, please', 'sales_summary', ['period' => 'last_month'],
            'Recorded sales count and total for one Philippine-time period.', null,
            pendingStorePeriod: 'last_month',
        );

        $this->assertTrue($result['covered']);
        Http::assertSentCount(1);
    }

    public function test_an_invented_qualifier_or_provider_failure_fails_closed(): void
    {
        Log::spy();
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text',
                'text' => '{"covered":false,"uncovered":"customer Ana","clarification":"Ask without a customer name.","offer_ungrouped":false,"language":"en"}',
            ]]]]])
            ->push(['error' => ['message' => 'Synthetic failure']], 503)
            ->push(['error' => ['message' => 'Synthetic failure']], 503);
        $service = app(AssistantScopeAuditService::class);

        $invalid = $service->audit('Show sales this month', 'sales_summary', ['period' => 'this_month'], 'Sales total.', null);
        $failed = $service->audit('Show sales this month', 'sales_summary', ['period' => 'this_month'], 'Sales total.', null);

        $this->assertTrue($invalid['unavailable']);
        $this->assertTrue($failed['unavailable']);
        $this->assertFalse($failed['covered']);
        Http::assertSentCount(3);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Assistant agentic provider failed'
                && array_keys($context) === ['stage', 'http_status', 'exception', 'latency_ms']
                && $context['stage'] === 'scope_audit'
                && $context['http_status'] === 503
                && $context['exception'] === RequestException::class
                && is_int($context['latency_ms']);
        });
    }

    public function test_fast_audit_failure_recovers_before_any_store_fact_is_read(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Synthetic outage']], 503)
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}',
            ]]]]])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Show sales this month', 'sales_summary', ['period' => 'this_month'], 'Recorded sales count and total.', null,
        );

        $this->assertTrue($result['covered']);
        Http::assertSentCount(2);
    }
}
