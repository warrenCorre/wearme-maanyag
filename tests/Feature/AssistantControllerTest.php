<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssistantAnswerService;
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
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->get(route('assistant.index'));

        $response->assertOk();
        $response->assertSee('Maanyag Assistant');
        $response->assertSee('Your smart assistant, Maanyag!');
        $response->assertSee('New Chat');
        $response->assertSee('href="'.route('assistant.index').'"', false);
        $response->assertSee('Maximum 500 characters');
        $response->assertSee('cannot create, edit, delete, archive, or settle records');
        $response->assertSee('List recently collected products.');
        $response->assertSee('value="List recently collected products"', false);
        $response->assertSee('Check Inventory');
        $response->assertSee('Sales Insights');
        $response->assertDontSee('Generate Report');
        $response->assertViewMissing('result');
    }

    public function test_owner_receives_a_mocked_answer_without_calling_gemini(): void
    {
        $owner = $this->createUser('store_owner');

        $this->mock(AssistantAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->with('How many unpaid transactions?')
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
                ->with($question)
                ->andReturn([
                    'status' => 'answered',
                    'message' => '<img src=x onerror=alert(1)>',
                ]);
        });

        $response = $this->actingAs($owner)->post(route('assistant.ask'), ['question' => $question]);

        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $response->assertDontSee('<script>', false);
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
        $response->assertDontSee('Source:', false);
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
