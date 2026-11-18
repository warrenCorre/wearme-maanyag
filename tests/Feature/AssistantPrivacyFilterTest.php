<?php

namespace Tests\Feature;

use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\AssistantPrivacyFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantPrivacyFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_fictional_privacy_canary_is_blocked_or_redacted_before_provider_use(): void
    {
        $this->seedCustomers([
            'Ana Reyes', 'Juan dela Cruz', 'Maria Santos', 'Pedro Ramirez', 'Victor Lim',
        ]);
        Http::preventStrayRequests();
        $filter = new AssistantPrivacyFilter;

        foreach ($this->fixtureCases('PRV-') as $case) {
            $turn = $case['turns'][0];
            $result = $filter->screen($turn['say']);

            $this->assertContains($result['status'], ['redacted', 'blocked'], $case['id']);
            $this->assertNotSame('screen_unavailable', $result['reason'], $case['id']);

            foreach ($turn['expect']['never_send'] as $canary) {
                $this->assertStringNotContainsStringIgnoringCase($canary, $result['text'] ?? '', $case['id']);
                $this->assertStringNotContainsStringIgnoringCase($canary, $result['message'] ?? '', $case['id']);
            }
        }

        Http::assertNothingSent();
    }

    public function test_fictional_false_positive_controls_pass_through_unchanged(): void
    {
        $this->seedCustomers(['May', 'Will', 'Hope', 'Joy']);
        $filter = new AssistantPrivacyFilter;

        foreach ($this->fixtureCases('CTL-') as $case) {
            $question = $case['turns'][0]['say'];
            $result = $filter->screen($question);

            $this->assertSame('safe', $result['status'], $case['id']);
            $this->assertSame($question, $result['text'], $case['id']);
        }

        $this->assertSame('safe', $filter->screen('How much were sales for May?')['status']);
    }

    public function test_db_backed_name_matching_uses_whole_tokens_and_person_context(): void
    {
        $this->seedCustomers(['Ana Reyes', 'Victor Lim']);
        $filter = new AssistantPrivacyFilter;

        $this->assertSame('Could you ask [private person] about it?', $filter->screen('Could you ask ana about it?')['text']);
        $this->assertSame("Is Mr. [private person]'s order ready?", $filter->screen("Is Mr. Lim's order ready?")['text']);
        $this->assertSame('safe', $filter->screen('I found a banana print today.')['status']);
        $this->assertSame('blocked', $filter->screen('Ana Reyes')['status']);
    }

    public function test_db_backed_contact_and_sale_reference_are_redacted_even_in_unusual_formats(): void
    {
        $this->seedCustomers(['Ana Reyes'], contact: '02-8123-4567', reference: 'WMM-42XZ');
        $filter = new AssistantPrivacyFilter;

        $this->assertSame('Call [private contact] tomorrow.', $filter->screen('Call 0281234567 tomorrow.')['text']);
        $this->assertSame('Can you check [private reference]?', $filter->screen('Can you check WMM-42XZ?')['text']);
    }

    public function test_credentials_block_without_echoing_the_value(): void
    {
        $filter = new AssistantPrivacyFilter;

        foreach (['My password is FictionalPass-77!', 'API key AIzaFAKE-FAKE-FAKE-FAKE-1234567890', 'token ghp_FAKEFAKEFAKEFAKEFAKE1234'] as $message) {
            $result = $filter->screen($message);

            $this->assertSame('blocked', $result['status']);
            $this->assertNull($result['text']);
            $this->assertStringNotContainsString('FAKE', $result['message']);
            $this->assertStringNotContainsString('FictionalPass', $result['message']);
        }
    }

    /** @return list<array<string, mixed>> */
    private function fixtureCases(string $prefix): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/assistant_eval_set.json')), true, 32, JSON_THROW_ON_ERROR);

        return array_values(array_filter(
            $fixture['cases'],
            fn (array $case): bool => str_starts_with($case['id'], $prefix),
        ));
    }

    /** @param list<string> $names */
    private function seedCustomers(array $names, string $contact = '0000000000', string $reference = 'SYN-PRIVACY'): void
    {
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier',
            'email' => 'privacy-cashier@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic store address',
            'role' => 'cashier',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);

        foreach ($names as $index => $name) {
            SalesTransaction::query()->create([
                'transaction_no' => count($names) === 1 ? $reference : substr($reference, 0, 16).'-'.$index,
                'sale_type' => 'online',
                'online_platform' => 'Facebook',
                'customer_name' => $name,
                'customer_contact' => $contact,
                'delivery_address' => 'Synthetic delivery address',
                'total_amount' => '100.00',
                'payment_status' => 'paid',
                'recorded_by' => $cashier->id,
                'transaction_date' => '2026-11-18 09:00:00',
            ]);
        }
    }
}
