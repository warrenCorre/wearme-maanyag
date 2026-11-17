<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cashier_account_changes_create_anonymous_events(): void
    {
        $owner = $this->createOwner();
        $payload = [
            'name' => 'Synthetic Cashier', 'email' => 'cashier@example.test',
            'password' => 'Synthetic-pass-123', 'password_confirmation' => 'Synthetic-pass-123',
            'phone' => '0000000000', 'address' => 'Synthetic address',
        ];

        $this->actingAs($owner)->post(route('cashiers.store'), $payload)
            ->assertRedirect(route('cashiers.index'));
        $cashier = User::query()->where('role', 'cashier')->firstOrFail();
        $unchanged = array_replace($payload, ['password' => '', 'password_confirmation' => '']);
        $this->actingAs($owner)->put(route('cashiers.update', $cashier), $unchanged)
            ->assertRedirect(route('cashiers.index'));
        $this->actingAs($owner)->put(route('cashiers.update', $cashier), array_replace($unchanged, [
            'name' => 'Synthetic Cashier Updated',
        ]))->assertRedirect(route('cashiers.index'));
        $this->actingAs($owner)->patch(route('cashiers.status', $cashier))
            ->assertRedirect(route('cashiers.index'));
        $this->actingAs($owner)->delete(route('cashiers.destroy', $cashier))
            ->assertRedirect(route('cashiers.index'));

        $this->assertSoftDeleted('tbl_users', ['id' => $cashier->id]);
        foreach (['cashier_created', 'cashier_updated', 'cashier_status_changed', 'cashier_deleted'] as $eventType) {
            $this->assertDatabaseHas('tbl_store_activity_events', [
                'event_type' => $eventType, 'subject_id' => $cashier->id, 'actor_id' => $owner->id,
            ]);
        }
        $this->assertDatabaseCount('tbl_store_activity_events', 4);
    }

    public function test_invalid_or_unauthorized_cashier_creation_leaves_no_activity(): void
    {
        $owner = $this->createOwner();
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier', 'email' => 'cashier@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'cashier',
            'profile_image' => '', 'status' => 'active',
        ]);

        $this->actingAs($owner)->post(route('cashiers.store'), [])->assertSessionHasErrors('name');
        $this->actingAs($cashier)->post(route('cashiers.store'), [])->assertForbidden();

        $this->assertDatabaseCount('tbl_store_activity_events', 0);
    }

    private function createOwner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
    }
}
