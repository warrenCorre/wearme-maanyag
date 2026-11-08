<?php

namespace Tests\Feature;

use App\Services\AssistantConversationService;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AssistantConversationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.gemini.allow_conversation' => false]);
    }

    public function test_conversation_sharing_requires_its_own_gate_even_when_store_sharing_is_enabled(): void
    {
        config(['services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $reply = (new AssistantConversationService($client))->reply('social', 'hi');

        $this->assertNull($reply);
    }

    public function test_only_a_safe_current_topic_and_no_store_context_is_sent_to_the_mocked_provider(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(function (string $prompt, string $instruction, array $schema): bool {
                return $prompt === 'Current safe topic: clarification / unpaid_goal. Select a short, friendly style for this turn.'
                    && str_contains($instruction, 'Laravel will supply all user-facing words')
                    && $schema['properties']['suggested_skill']['enum'] === ['none'];
            })
            ->andReturn('{"style":"bright","suggested_skill":"none"}');

        $reply = (new AssistantConversationService($client))->reply('clarification', 'unpaid_goal');

        $this->assertSame('Let’s clarify: the aggregate unpaid balance or a particular pending sale? Only the aggregate is available right now.', $reply);
    }

    public function test_unknown_topics_never_call_the_provider(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $this->assertNull((new AssistantConversationService($client))->reply('clarification', 'sale_detail'));
        $this->assertNull((new AssistantConversationService($client))->reply('social', 'hi private@example.test'));
    }

    public function test_untrusted_provider_replies_or_skill_suggestions_cannot_be_displayed(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            '{"style":"warm","suggested_skill":"latest_unpaid_sale"}',
            '{"style":"warm","suggested_skill":"none","message":"Invented sale detail"}',
            '{"style":"unknown","suggested_skill":"none"}',
            'not json',
        ] as $output) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()->andReturn($output);

            $this->assertNull((new AssistantConversationService($client))->reply('social', 'hi'), $output);
        }
    }

    public function test_provider_failure_falls_back_locally(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andThrow(new ConnectionException('Synthetic timeout'));

        $this->assertNull((new AssistantConversationService($client))->reply('social', 'thanks'));
    }
}
