<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssistantAnswerService;
use App\Services\AssistantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_canary_report_stays_local_and_contains_no_sensitive_prompt(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true, 'services.gemini.api_key' => 'fictional-key']);

        $exitCode = Artisan::call('assistant:eval', [
            '--group' => 'privacy.canary', '--limit' => '1', '--stub' => true, '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $report['cases']);
        $this->assertSame('privacy_safe', $report['turns'][0]['outcome']);
        $this->assertStringNotContainsString('Ana Reyes', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_routing_only_baseline_never_builds_store_context(): void
    {
        $this->createOwner();
        config([
            'services.gemini.allow_conversation' => true,
            'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
        ]);
        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $exitCode = Artisan::call('assistant:eval', [
            '--group' => 'store_answer.sales', '--limit' => '1', '--stub' => true, '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $report['cases']);
        $this->assertSame(1, count($report['turns']));
        $this->assertFalse($report['reply_quality_evaluated']);
    }

    public function test_live_canaries_and_full_fact_phrasing_are_rejected_before_provider_calls(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true]);
        Http::preventStrayRequests();

        $canaryExit = Artisan::call('assistant:eval', ['--group' => 'privacy.canary']);
        $fullExit = Artisan::call('assistant:eval', ['--full' => true]);

        $this->assertSame(1, $canaryExit);
        $this->assertSame(1, $fullExit);
        Http::assertNothingSent();
    }

    public function test_agentic_stub_flow_runs_without_live_provider_or_store_fact_retrieval(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true, 'services.gemini.api_key' => 'fictional-key']);

        $this->mock(AssistantContextService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });

        $exit = Artisan::call('assistant:eval', ['--flow' => 'agentic', '--group' => 'store_answer.sales', '--limit' => '1', '--stub' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('agentic', $report['flow']);
        $this->assertFalse($report['reply_quality_evaluated']);
    }

    public function test_broad_current_store_question_is_scored_as_recorded_activity(): void
    {
        $this->createOwner();
        $this->mock(AssistantAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')->times(9)->andReturnUsing(function (string $question): array {
                if (str_contains($question, 'yesterday') || str_contains($question, 'kahapon')) {
                    return ['status' => 'declined', 'message' => 'Only today is available.'];
                }

                return ['status' => 'evaluation', 'message' => '', 'evaluation' => [
                    'tool' => 'recent_activity', 'args' => ['period' => 'today'],
                ]];
            });
        });

        $exit = Artisan::call('assistant:eval', [
            '--flow' => 'agentic', '--group' => 'store_answer.recent_activity',
            '--limit' => '9', '--phase' => '0', '--stub' => true, '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(9, $report['cases']);
        $this->assertSame(9, $report['passed']);
        $this->assertSame('ACT-009', $report['turns'][8]['id']);
        $this->assertSame('answer', $report['turns'][8]['outcome']);
        $this->assertSame([], $report['failures']);
        Http::assertNothingSent();
    }

    public function test_unbounded_live_agentic_eval_is_rejected_before_provider_calls(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true]);
        Http::preventStrayRequests();

        $this->assertSame(1, Artisan::call('assistant:eval', ['--flow' => 'agentic']));
        Http::assertNothingSent();
    }

    public function test_the_first_sales_followup_eval_scores_goal_then_period_then_answer(): void
    {
        $this->createOwner();
        config([
            'services.gemini.allow_conversation' => true,
            'services.gemini.allow_store_data' => true,
            'services.gemini.api_key' => 'fictional-key',
            'assistant.agentic_scope_audit' => true,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Are you looking for sales totals or best sellers?","pending":"sales_goal"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"clarification","reply":"Which reporting period would you like?","pending":"sales_period"}']]]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'function_call', 'id' => 'call_sales', 'name' => 'sales_summary', 'arguments' => ['period' => 'yesterday', 'metric_evidence' => '', 'period_evidence' => 'Yesterday']]]])
            ->push(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"covered":true,"uncovered":"","clarification":"","offer_ungrouped":false,"language":"en"}']]]]]);

        $exit = Artisan::call('assistant:eval', [
            '--flow' => 'agentic', '--group' => 'followup', '--limit' => '1', '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['passed']);
        $this->assertSame([], $report['failures']);
        $this->assertCount(3, $report['turns']);
        Http::assertSentCount(4);
    }

    public function test_an_offered_aggregate_is_scored_as_a_scope_clarification(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true]);
        $this->mock(AssistantAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')->once()->andReturn([
                'status' => 'clarification',
                'message' => 'I cannot filter low stock to jackets. Want the overall list?',
                'visit_context' => ['pending' => 'offered_tool', 'offer' => ['tool' => 'low_stock', 'arguments' => []]],
            ]);
        });

        $exit = Artisan::call('assistant:eval', [
            '--flow' => 'agentic', '--group' => 'filtered.phase3', '--phase' => '0',
            '--limit' => '1', '--stub' => true, '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['passed']);
        $this->assertSame([], $report['failures']);
    }

    public function test_an_unsupported_scope_without_an_offer_is_scored_as_a_scope_clarification(): void
    {
        $this->createOwner();
        config(['services.gemini.allow_conversation' => true]);
        $this->mock(AssistantAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')->once()->andReturn([
                'status' => 'clarification',
                'message' => 'I cannot filter this to tops. Could you restate a supported request?',
                'visit_context' => ['pending' => 'scope_goal'],
            ]);
        });

        $exit = Artisan::call('assistant:eval', [
            '--flow' => 'agentic', '--group' => 'filtered.phase3', '--phase' => '0',
            '--limit' => '1', '--stub' => true, '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['passed']);
        $this->assertSame([], $report['failures']);
    }

    private function createOwner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'synthetic-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
    }
}
