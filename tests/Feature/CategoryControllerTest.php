<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_category_changes_create_events_but_an_unchanged_form_does_not(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->post(route('categories.store'), ['category_name' => 'Synthetic tops'])
            ->assertRedirect(route('categories.index'));
        $category = Category::query()->firstOrFail();
        $this->actingAs($owner)->put(route('categories.update', $category), [
            'category_name' => 'Synthetic tops', 'status' => 'active',
        ])->assertRedirect(route('categories.index'));
        $this->actingAs($owner)->put(route('categories.update', $category), [
            'category_name' => 'Synthetic coats', 'status' => 'active',
        ])->assertRedirect(route('categories.index'));
        $this->actingAs($owner)->patch(route('categories.status', $category))
            ->assertRedirect(route('categories.index'));

        $this->assertSame('inactive', $category->fresh()->status);
        foreach (['category_created', 'category_updated', 'category_status_changed'] as $eventType) {
            $this->assertDatabaseHas('tbl_store_activity_events', [
                'event_type' => $eventType, 'subject_id' => $category->id, 'actor_id' => $owner->id,
            ]);
        }
        $this->assertDatabaseCount('tbl_store_activity_events', 3);
    }

    public function test_invalid_or_unauthorized_category_requests_leave_no_activity(): void
    {
        $owner = $this->createOwner();
        $cashier = $this->createOwner('cashier');

        $this->actingAs($owner)->post(route('categories.store'), ['category_name' => ''])
            ->assertSessionHasErrors('category_name');
        $this->actingAs($cashier)->post(route('categories.store'), ['category_name' => 'Synthetic tops'])
            ->assertForbidden();

        $this->assertDatabaseCount('tbl_categories', 0);
        $this->assertDatabaseCount('tbl_store_activity_events', 0);
    }

    private function createOwner(string $role = 'store_owner'): User
    {
        return User::query()->create([
            'name' => 'Synthetic '.($role === 'cashier' ? 'Cashier' : 'Owner'),
            'email' => $role.'@example.test', 'password' => 'synthetic-password',
            'phone' => '0000000000', 'address' => 'Synthetic address',
            'role' => $role, 'profile_image' => '', 'status' => 'active',
        ]);
    }
}
