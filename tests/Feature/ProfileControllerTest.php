<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_change_records_an_event_but_an_unchanged_form_does_not(): void
    {
        $cashier = $this->createCashier();

        $this->actingAs($cashier)->patch(route('profile.update'), [
            'name' => 'Synthetic Cashier', 'email' => 'cashier@example.test',
        ])->assertRedirect(route('profile.show'));
        $this->actingAs($cashier)->patch(route('profile.update'), [
            'name' => 'Synthetic Cashier Updated', 'email' => 'cashier@example.test',
        ])->assertRedirect(route('profile.show'));

        $this->assertSame('Synthetic Cashier Updated', $cashier->fresh()->name);
        $this->assertDatabaseHas('tbl_store_activity_events', [
            'event_type' => 'profile_updated', 'subject_id' => $cashier->id, 'actor_id' => $cashier->id,
        ]);
        $this->assertDatabaseCount('tbl_store_activity_events', 1);
    }

    public function test_invalid_profile_update_does_not_record_activity(): void
    {
        $cashier = $this->createCashier();

        $this->actingAs($cashier)->patch(route('profile.update'), [
            'name' => '', 'email' => 'bad-email',
        ])->assertSessionHasErrors(['name', 'email']);

        $this->assertSame('Synthetic Cashier', $cashier->fresh()->name);
        $this->assertDatabaseCount('tbl_store_activity_events', 0);
    }

    private function createCashier(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Cashier', 'email' => 'cashier@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'cashier',
            'profile_image' => '', 'status' => 'active',
        ]);
    }
}
