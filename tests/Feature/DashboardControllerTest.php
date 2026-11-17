<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_owner_sees_today_and_seven_day_sales_using_philippine_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 23:59:59', 'Asia/Manila'));
        $owner = User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'dashboard-sales-owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
        $sales = [
            ['SYN-DASH-OLD', '2026-11-10 15:59:59', '80.00'],
            ['SYN-DASH-FIRST', '2026-11-10 16:00:00', '70.00'],
            ['SYN-DASH-YESTERDAY', '2026-11-16 15:59:59', '10.00'],
            ['SYN-DASH-TODAY-START', '2026-11-16 16:00:00', '20.00'],
            ['SYN-DASH-TODAY-END', '2026-11-17 15:59:59', '30.00'],
            ['SYN-DASH-TOMORROW', '2026-11-17 16:00:00', '40.00'],
        ];

        foreach ($sales as [$number, $date, $total]) {
            DB::table('tbl_sales_transactions')->insert([
                'transaction_no' => $number,
                'sale_type' => 'walk_in',
                'online_platform' => '',
                'total_amount' => $total,
                'payment_status' => 'paid',
                'recorded_by' => $owner->id,
                'transaction_date' => $date,
            ]);
        }

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee("Today's Sales · Philippine time", false)
            ->assertSee('Sales - Last 7 Days · Philippine time', false)
            ->assertViewHas('salesStats', fn (array $stats): bool => $stats === [
                'today_total' => 50.0,
                'today_count' => 2,
                'last_seven_days' => [
                    'labels' => ['Nov 11', 'Nov 12', 'Nov 13', 'Nov 14', 'Nov 15', 'Nov 16', 'Nov 17'],
                    'totals' => [70.0, 0.0, 0.0, 0.0, 0.0, 10.0, 50.0],
                ],
            ]);
    }
}
