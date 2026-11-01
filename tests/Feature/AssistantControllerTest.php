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
            ->post(route('assistant.ask'), ['question' => 'Show me sales for this month'])
            ->assertForbidden();
    }

    public function test_owner_sees_the_read_only_assistant_form(): void
    {
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->get(route('assistant.index'));

        $response->assertOk();
        $response->assertSee('Maanyag Assistant');
        $response->assertSee('Maximum 500 characters');
        $response->assertSee('cannot create, edit, delete, archive, or settle records');
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
                ]);
        });

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => 'How many unpaid transactions?',
        ]);

        $response->assertOk();
        $response->assertSee('Synthetic aggregate answer.');
    }

    public function test_owner_sees_the_disabled_message_when_store_data_sharing_is_off(): void
    {
        config(['services.gemini.allow_store_data' => false]);
        $owner = $this->createUser('store_owner');

        $response = $this->actingAs($owner)->post(route('assistant.ask'), [
            'question' => 'Show me sales for this month',
        ]);

        $response->assertOk();
        $response->assertSee('disabled until sharing store data');
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
