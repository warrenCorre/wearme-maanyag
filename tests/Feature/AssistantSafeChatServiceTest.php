<?php

namespace Tests\Feature;

use App\Services\AssistantSafeChatService;
use App\Services\AssistantSafeTurnScreen;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AssistantSafeChatServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.gemini.allow_conversation' => false]);
    }

    public function test_conversation_gate_off_uses_a_local_reply_even_when_store_sharing_is_on(): void
    {
        config(['services.gemini.allow_store_data' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $reply = $this->service($client)->reply('tell me a joke about thrift shopping');

        $this->assertStringContainsString('thrift joke', $reply);
    }

    public function test_only_a_re_screened_current_public_turn_goes_to_the_mocked_provider(): void
    {
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => false]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()
            ->withArgs(function (string $prompt, string $instruction, array $schema): bool {
                return $prompt === 'Current screened general-chat turn: tell me a joke about thrift shopping'
                    && str_contains($instruction, 'This is not a store answer')
                    && $schema['properties']['kind']['enum'] === ['general_chat']
                    && ! str_contains($prompt, 'previous turn');
            })
            ->andReturn('{"kind":"general_chat","reply":"A preloved jacket gets another chapter. That is pretty lovely!"}');

        $reply = $this->service($client)->reply('tell me a joke about thrift shopping');

        $this->assertSame('A preloved jacket gets another chapter. That is pretty lovely!', $reply);
    }

    public function test_a_direct_unsafe_call_never_reaches_the_provider(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('generateText');

        $this->service($client)->reply('tell me a joke about customer Ana');
    }

    public function test_untrusted_or_store_claiming_provider_output_falls_back_locally(): void
    {
        config(['services.gemini.allow_conversation' => true]);

        foreach ([
            '{"kind":"general_chat","reply":"Your store has five jackets in stock."}',
            '{"kind":"general_chat","reply":"We have plenty of lovely secondhand outfits for you."}',
            '{"kind":"general_chat","reply":"Three jackets are ready for you."}',
            '{"kind":"general_chat","reply":"Visit https://example.test"}',
            '{"kind":"general_chat","reply":"Ana is your best buyer."}',
            '{"kind":"general_chat","reply":"My secret password is hidden."}',
            '{"kind":"general_chat","reply":"A fun thought!","skill":"latest_unpaid_sale"}',
            '{"kind":"store_fact","reply":"A fun thought!"}',
            '{"kind":"general_chat","reply":"<script>alert(1)</script>"}',
            '{"kind":"general_chat","reply":"'.str_repeat('a', 241).'"}',
            'not json',
        ] as $output) {
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('generateText')->once()->andReturn($output);

            $this->assertStringContainsString('thrift joke', $this->service($client)->reply('tell me a joke about thrift shopping'), $output);
        }
    }

    public function test_provider_failure_uses_the_local_reply(): void
    {
        config(['services.gemini.allow_conversation' => true]);
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('generateText')->once()->andThrow(new ConnectionException('Synthetic timeout'));

        $this->assertStringContainsString('another chapter', $this->service($client)->reply('can we chat about reusing clothes'));
    }

    private function service(GeminiClient $client): AssistantSafeChatService
    {
        return new AssistantSafeChatService($client, new AssistantSafeTurnScreen);
    }
}
