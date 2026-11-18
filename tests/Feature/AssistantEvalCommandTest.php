<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_agentic_flag_is_reserved_until_phase_two(): void
    {
        $this->createOwner();

        $this->assertSame(1, Artisan::call('assistant:eval', ['--flow' => 'agentic', '--stub' => true]));
        Http::assertNothingSent();
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
