<?php

namespace Tests\Feature;

use App\Services\AssistantScopeAuditService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
                'text' => '{"covered":false,"uncovered":"jacket category","clarification":"I cannot filter low stock by jacket yet. Want the overall list?","offer_ungrouped":true}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'What is running low in the jacket category?', 'low_stock', [],
            'Top ten low-stock products across all categories. No category filter.', null,
        );

        $this->assertSame(['covered' => false, 'unavailable' => false, 'message' => 'I cannot filter low stock by jacket yet. Want the overall list?', 'offer_ungrouped' => true], $result);
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
                'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false}',
            ]]]],
        ])]);

        $result = app(AssistantScopeAuditService::class)->audit(
            'Show sales this month', 'sales_summary', ['period' => 'this_month'],
            'Recorded sales count and total for one Philippine-time period.', null,
        );

        $this->assertTrue($result['covered']);
        $this->assertNull($result['message']);
    }

    public function test_an_invented_qualifier_or_provider_failure_fails_closed(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text',
                'text' => '{"covered":false,"uncovered":"customer Ana","clarification":"Ask without a customer name.","offer_ungrouped":false}',
            ]]]]])
            ->push(['error' => ['message' => 'Synthetic failure']], 503);
        $service = app(AssistantScopeAuditService::class);

        $invalid = $service->audit('Show sales this month', 'sales_summary', ['period' => 'this_month'], 'Sales total.', null);
        $failed = $service->audit('Show sales this month', 'sales_summary', ['period' => 'this_month'], 'Sales total.', null);

        $this->assertTrue($invalid['unavailable']);
        $this->assertTrue($failed['unavailable']);
        $this->assertFalse($failed['covered']);
        Http::assertSentCount(2);
    }
}
