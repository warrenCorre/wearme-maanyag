<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_declarations_and_validation_share_an_exact_read_only_scope(): void
    {
        $this->actingAs($this->owner());
        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $registry = app(AssistantToolRegistry::class);
        $declarations = $registry->declarations();

        $this->assertContains('sales_summary', array_column($declarations, 'name'));
        $this->assertContains('latest_unpaid_sale', array_column($declarations, 'name'));
        $this->assertTrue($registry->validate('sales_summary', ['period' => 'yesterday'])['valid']);
        $this->assertSame('unsupported_scope', $registry->validate('sales_summary', ['period' => 'yesterday', 'customer' => 'Fictional'])['reason']);
        $this->assertSame('unsupported_scope', $registry->validate('sales_summary', [])['reason']);
        $this->assertSame('unsupported_argument', $registry->validate('sales_summary', ['period' => '2030'])['reason']);
        $this->assertSame('unknown_tool', $registry->validate('delete_sale', [])['reason']);
        $this->assertTrue($registry->validate('latest_unpaid_sale', [])['local_only']);
        $this->assertTrue($registry->validate('adjustment_totals', ['kind' => 'damaged', 'period' => 'today'])['local_only']);
    }

    public function test_tool_execution_requires_active_owner_and_both_sharing_consents(): void
    {
        config(['services.gemini.allow_conversation' => false, 'services.gemini.allow_store_data' => false]);
        $registry = app(AssistantToolRegistry::class);
        $this->assertSame('owner_required', $registry->validate('inventory_summary', [])['reason']);

        $this->actingAs($this->owner());
        $this->assertSame('sharing_disabled', $registry->validate('inventory_summary', [])['reason']);

        config(['services.gemini.allow_conversation' => true, 'services.gemini.allow_store_data' => true]);
        $answer = $registry->execute('unpaid_aggregate', []);
        $this->assertSame('answered', $answer['answer']['status']);
        $this->assertSame('unpaid_summary', $answer['answer']['sources'][0]['type']);
        $this->assertFalse($answer['local_only']);
    }

    private function owner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test', 'password' => 'synthetic-password',
            'phone' => '0000000000', 'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
    }
}
