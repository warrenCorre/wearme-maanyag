<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_actual_category_for_low_stock_product(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'dashboard-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic Tops', 'status' => 'active']);
        Product::query()->create([
            'product_code' => 'SYN-LOW-STOCK',
            'product_name' => 'Synthetic Blouse',
            'category_id' => $category->id,
            'description' => 'Synthetic item',
            'collection_date' => '2026-11-01',
            'price' => '25.00',
            'quantity' => 1,
            'low_stock_threshold' => 2,
            'image' => 'products/synthetic.png',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Synthetic Blouse')
            ->assertSee('Synthetic Tops')
            ->assertDontSee('Uncategorized');
    }
}
