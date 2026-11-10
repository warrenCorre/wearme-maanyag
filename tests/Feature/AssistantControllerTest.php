<?php

namespace Tests\Feature;

use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\AssistantAnswerService;
use App\Services\AssistantContextService;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.gemini.allow_conversation' => false]);
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

    public function test_follow_up_chat_sends_only_a_safe_current_turn_not_the_previous_joke(): void
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
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: tell me another joke about thrift shopping')
                ->andReturn('{"kind":"general_chat","reply":"A scarf found a new twist!"}');
        });

        $this->actingAs($owner)->postJson(route('assistant.ask'), ['question' => 'Tell me a joke about thrift shopping'])
            ->assertJsonPath('result.message', 'A jacket found a fresh chapter!');
        $this->postJson(route('assistant.ask'), ['question' => 'Joke'])
            ->assertJsonPath('result.status', 'general_chat')
            ->assertJsonPath('result.message', 'A scarf found a new twist!')
            ->assertJsonMissingPath('result.visit_context');
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
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, this month, or this year?');
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

    public function test_owner_chat_over_http_sends_only_the_screened_current_turn_with_separate_consent(): void
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
                ->withArgs(fn (string $prompt): bool => $prompt === 'Current screened general-chat turn: tell me a joke about thrift shopping')
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
            ->assertJsonPath('result.status', 'declined');
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
        $response->assertSee('Greetings and brief chat still work with a local fallback');
        $response->assertSee('List recently collected products.');
        $response->assertSee('Show the latest unpaid sale.');
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
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, this month, or this year?');
        $third->assertJsonPath('result.status', 'disabled');
        $this->postJson(route('assistant.ask'), ['question' => 'Yesterday'])
            ->assertJsonPath('result.status', 'declined');
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
            ->assertJsonPath('result.message', 'Which period for sales totals: today, yesterday, this week, this month, or this year?');
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
