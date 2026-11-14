<?php

namespace Tests\Feature;

use App\Exports\InventoryReportExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_recorded_and_unknown_collection_dates_in_the_inventory_report(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'weekly',
            'date' => '2026-11-05',
        ]));

        $response->assertSee('Collection Date');
        $response->assertSee('2026-10-15');
        $response->assertSee('Not recorded');
    }

    public function test_owner_sees_actual_product_categories_in_the_inventory_report(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $this->actingAs($owner)->get(route('reports.index', ['period' => 'weekly', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertSee('Pants')
            ->assertDontSee('Uncategorized');
    }

    public function test_daily_report_groups_sales_by_hour_in_the_test_database(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-MORNING', '120.00', '2026-11-14 09:15:00'],
            ['SYN-AFTERNOON', '80.00', '2026-11-14 14:45:00'],
        ] as [$number, $total, $date]) {
            SalesTransaction::query()->create([
                'transaction_no' => $number,
                'sale_type' => 'walk_in',
                'online_platform' => '',
                'total_amount' => $total,
                'payment_status' => 'paid',
                'recorded_by' => $owner->id,
                'transaction_date' => $date,
            ]);
        }

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'daily', 'date' => '2026-11-14',
        ]));

        $response->assertOk()->assertSee('09:00')->assertSee('14:00');
        $this->assertSame([
            'transaction_count' => 2, 'total_sales' => 200.0, 'average_sale' => 100.0,
        ], $response['report']['summary']);
        $this->assertSame(120.0, $response['report']['chart']['totals'][9]);
        $this->assertSame(80.0, $response['report']['chart']['totals'][14]);
        $this->assertSame([
            ['label' => '09:00', 'transactions' => 1, 'total' => 120.0],
            ['label' => '14:00', 'transactions' => 1, 'total' => 80.0],
        ], $response['report']['rows']);
    }

    public function test_inventory_pdf_view_includes_recorded_and_unknown_collection_dates(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $html = view('reports.exports.inventory', [
            'products' => collect([$datedProduct, $legacyProduct]),
            'stats' => ['product_count' => 2, 'total_units' => 4, 'stock_value' => 400],
            'lowStockCount' => 0,
        ])->render();

        $this->assertStringContainsString('Collection Date', $html);
        $this->assertStringContainsString('2026-10-15', $html);
        $this->assertStringContainsString('Not recorded', $html);
    }

    public function test_inventory_excel_rows_include_recorded_and_unknown_collection_dates(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $export = new InventoryReportExport(collect([$datedProduct, $legacyProduct]));
        $rows = $export->collection()->all();

        $this->assertSame('Collection Date', $export->headings()[3]);
        $this->assertSame('2026-10-15', $rows[0][3]);
        $this->assertSame('Not recorded', $rows[1][3]);
    }

    /**
     * @return array{0: User, 1: Product, 2: Product}
     */
    private function createProductsWithRecordedAndUnknownDates(): array
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);
        $datedProduct = Product::query()->create([
            'product_code' => 'SYN-001',
            'product_name' => 'Synthetic Pants',
            'category_id' => $category->id,
            'description' => 'Synthetic product.',
            'collection_date' => '2026-10-15',
            'price' => 100,
            'quantity' => 2,
            'low_stock_threshold' => 1,
            'image' => 'products/synthetic.jpg',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $legacyProduct = $datedProduct->replicate();
        $legacyProduct->product_code = 'SYN-002';
        $legacyProduct->collection_date = null;
        $legacyProduct->save();

        return [$owner, $datedProduct, $legacyProduct];
    }
}
