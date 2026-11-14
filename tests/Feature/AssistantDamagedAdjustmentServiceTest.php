<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantDamagedAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantDamagedAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_only_recorded_damaged_decreases_without_reading_or_echoing_reason_text(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-adjustment-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-ADJUSTMENT', 'product_name' => 'Synthetic item',
            'category_id' => $category->id, 'description' => 'Synthetic item',
            'collection_date' => '2026-11-01', 'price' => '15.00',
            'quantity' => 10, 'low_stock_threshold' => 1, 'image' => '',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);

        foreach ([
            ['decrease', 2, 'Damaged item: Synthetic private note about buyer'],
            ['decrease', 3, 'Damaged item: Synthetic torn fabric'],
            ['decrease', 4, 'Synthetic other removal'],
            ['increase', 7, 'Damaged item: Synthetic mislabeled increase'],
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
            'Recorded damaged-item adjustments: 2 decreases, 5 units removed (all time). Reasons and product details are not shown.',
            app(AssistantDamagedAdjustmentService::class)->answer(),
        );
    }

    public function test_it_reports_zero_when_no_damaged_adjustments_exist(): void
    {
        $this->assertSame(
            'Recorded damaged-item adjustments: 0 decreases, 0 units removed (all time). Reasons and product details are not shown.',
            app(AssistantDamagedAdjustmentService::class)->answer(),
        );
    }

    public function test_one_damaged_adjustment_uses_singular_units_and_decrease(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-singular-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-ONE', 'product_name' => 'Synthetic item',
            'category_id' => $category->id, 'description' => 'Synthetic item',
            'collection_date' => '2026-11-01', 'price' => '15.00',
            'quantity' => 9, 'low_stock_threshold' => 1, 'image' => '',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $product->inventoryAdjustments()->create([
            'adjusted_by' => $owner->id, 'direction' => 'decrease',
            'quantity' => 1, 'quantity_before' => 10, 'quantity_after' => 9,
            'reason' => 'Damaged item: Synthetic note',
        ]);

        $this->assertSame(
            'Recorded damaged-item adjustments: 1 decrease, 1 unit removed (all time). Reasons and product details are not shown.',
            app(AssistantDamagedAdjustmentService::class)->answer(),
        );
    }
}
