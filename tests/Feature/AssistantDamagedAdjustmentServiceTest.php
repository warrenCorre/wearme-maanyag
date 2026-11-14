<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantDamagedAdjustmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_calendar_months_exclude_other_adjustments_and_respect_the_first_day_boundary(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-period-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-PERIOD', 'product_name' => 'Synthetic item',
            'category_id' => $category->id, 'description' => 'Synthetic item',
            'collection_date' => '2026-09-01', 'price' => '15.00',
            'quantity' => 10, 'low_stock_threshold' => 1, 'image' => '',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);

        foreach ([
            ['2026-09-30 23:59:59', 6, 'Damaged item: Synthetic earlier'],
            ['2026-10-01 00:00:00', 4, 'Damaged item: Synthetic prior'],
            ['2026-10-31 23:59:59', 1, 'Damaged item: Synthetic prior last day'],
            ['2026-11-01 00:00:00', 2, 'Damaged item: Synthetic current'],
            ['2026-11-14 09:00:00', 8, 'Synthetic other removal'],
            ['2026-12-01 00:00:00', 3, 'Damaged item: Synthetic later'],
        ] as [$createdAt, $quantity, $reason]) {
            DB::table('tbl_inventory_adjustments')->insert([
                'product_id' => $product->id, 'adjusted_by' => $owner->id,
                'direction' => 'decrease', 'quantity' => $quantity,
                'quantity_before' => 10, 'quantity_after' => 10 - $quantity,
                'reason' => $reason, 'created_at' => $createdAt,
            ]);
        }

        $service = app(AssistantDamagedAdjustmentService::class);

        $this->assertSame(
            'Recorded damaged-item adjustments: 1 decrease, 2 units removed (during November 2026). Reasons and product details are not shown.',
            $service->answer('monthly', CarbonImmutable::parse('2026-11-14')),
        );
        $this->assertSame(
            'Recorded damaged-item adjustments: 2 decreases, 5 units removed (during October 2026). Reasons and product details are not shown.',
            $service->answer('monthly', CarbonImmutable::parse('2026-10-14')),
        );
        $this->assertSame(
            'Recorded damaged-item adjustments: 5 decreases, 16 units removed (all time). Reasons and product details are not shown.',
            $service->answer(),
        );
    }
}
