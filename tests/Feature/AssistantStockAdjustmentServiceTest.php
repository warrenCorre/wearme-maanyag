<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantStockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantStockAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direction_totals_count_all_recorded_non_sales_adjustments_without_exposing_reasons_or_names(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-stock-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-STOCK', 'product_name' => 'Synthetic Jacket',
            'category_id' => $category->id, 'description' => 'Synthetic item',
            'collection_date' => '2026-11-01', 'price' => '15.00',
            'quantity' => 10, 'low_stock_threshold' => 1, 'image' => '',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);

        foreach ([
            ['increase', 2, 'Synthetic restock'],
            ['increase', 3, 'Synthetic private contact note'],
            ['decrease', 1, 'Damaged item: Synthetic private reason'],
            ['decrease', 4, 'Synthetic non-damage removal'],
        ] as [$direction, $quantity, $reason]) {
            $product->inventoryAdjustments()->create([
                'adjusted_by' => $owner->id, 'direction' => $direction,
                'quantity' => $quantity, 'quantity_before' => 10,
                'quantity_after' => $direction === 'decrease' ? 10 - $quantity : 10 + $quantity,
                'reason' => $reason,
            ]);
        }

        $product->delete();

        $this->assertSame(
            'Recorded non-sales stock adjustments (all time): 2 increases (5 units added), 2 decreases (5 units removed). Reasons and product details are not shown.',
            app(AssistantStockAdjustmentService::class)->answer(),
        );
    }

    public function test_it_reports_zero_adjustments_without_inventing_movements(): void
    {
        $this->assertSame(
            'Recorded non-sales stock adjustments (all time): 0 increases (0 units added), 0 decreases (0 units removed). Reasons and product details are not shown.',
            app(AssistantStockAdjustmentService::class)->answer(),
        );
    }
}
