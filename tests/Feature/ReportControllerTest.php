<?php

namespace Tests\Feature;

use App\Exports\InventoryReportExport;
use App\Models\Category;
use App\Models\InventoryAdjustment;
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

    public function test_yearly_report_groups_sales_by_month_in_the_test_database(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-JANUARY', '120.00', '2026-01-15 09:15:00'],
            ['SYN-NOVEMBER', '80.00', '2026-11-14 14:45:00'],
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
            'period' => 'yearly', 'date' => '2026-11-14',
        ]));

        $response->assertOk();
        $this->assertSame(120.0, $response['report']['chart']['totals'][0]);
        $this->assertSame(80.0, $response['report']['chart']['totals'][10]);
        $this->assertSame([
            ['label' => 'Jan', 'transactions' => 1, 'total' => 120.0],
            ['label' => 'Nov', 'transactions' => 1, 'total' => 80.0],
        ], $response['report']['rows']);
    }

    public function test_daily_report_includes_both_day_boundaries_without_neighboring_sales(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-BEFORE', '10.00', '2026-11-13 23:59:59'],
            ['SYN-START', '40.00', '2026-11-14 00:00:00'],
            ['SYN-END', '60.00', '2026-11-14 23:59:59'],
            ['SYN-AFTER', '20.00', '2026-11-15 00:00:00'],
        ] as [$number, $total, $date]) {
            $this->createReportSale($owner, $number, $total, $date);
        }

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'daily', 'date' => '2026-11-14',
        ]));

        $response->assertOk();
        $this->assertSame(['transaction_count' => 2, 'total_sales' => 100.0, 'average_sale' => 50.0], $response['report']['summary']);
        $this->assertSame(40.0, $response['report']['chart']['totals'][0]);
        $this->assertSame(60.0, $response['report']['chart']['totals'][23]);
        $this->assertSame(0.0, $response['report']['chart']['totals'][1]);
    }

    public function test_weekly_report_includes_monday_through_sunday_only(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-SUNDAY-BEFORE', '10.00', '2026-11-01 23:59:59'],
            ['SYN-MONDAY', '40.00', '2026-11-02 00:00:00'],
            ['SYN-SUNDAY', '60.00', '2026-11-08 23:59:59'],
            ['SYN-MONDAY-AFTER', '20.00', '2026-11-09 00:00:00'],
        ] as [$number, $total, $date]) {
            $this->createReportSale($owner, $number, $total, $date);
        }

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'weekly', 'date' => '2026-11-05',
        ]));

        $response->assertOk();
        $this->assertSame(['transaction_count' => 2, 'total_sales' => 100.0, 'average_sale' => 50.0], $response['report']['summary']);
        $this->assertSame([40.0, 0.0, 0.0, 0.0, 0.0, 0.0, 60.0], $response['report']['chart']['totals']);
        $this->assertSame([
            ['label' => 'Mon, Nov 2', 'transactions' => 1, 'total' => 40.0],
            ['label' => 'Sun, Nov 8', 'transactions' => 1, 'total' => 60.0],
        ], $response['report']['rows']);
    }

    public function test_monthly_report_includes_leap_day_without_adjacent_months(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-JANUARY', '10.00', '2024-01-31 23:59:59'],
            ['SYN-FEBRUARY-START', '40.00', '2024-02-01 00:00:00'],
            ['SYN-LEAP-DAY', '60.00', '2024-02-29 23:59:59'],
            ['SYN-MARCH', '20.00', '2024-03-01 00:00:00'],
        ] as [$number, $total, $date]) {
            $this->createReportSale($owner, $number, $total, $date);
        }

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'monthly', 'date' => '2024-02-14',
        ]));

        $response->assertOk();
        $this->assertSame(['transaction_count' => 2, 'total_sales' => 100.0, 'average_sale' => 50.0], $response['report']['summary']);
        $this->assertCount(29, $response['report']['chart']['labels']);
        $this->assertSame(40.0, $response['report']['chart']['totals'][0]);
        $this->assertSame(60.0, $response['report']['chart']['totals'][28]);
        $this->assertSame([
            ['label' => 'Feb 1', 'transactions' => 1, 'total' => 40.0],
            ['label' => 'Feb 29', 'transactions' => 1, 'total' => 60.0],
        ], $response['report']['rows']);
    }

    public function test_yearly_report_excludes_sales_from_neighboring_years(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        foreach ([
            ['SYN-OLD-YEAR', '10.00', '2025-12-31 23:59:59'],
            ['SYN-JANUARY-START', '40.00', '2026-01-01 00:00:00'],
            ['SYN-DECEMBER-END', '60.00', '2026-12-31 23:59:59'],
            ['SYN-NEW-YEAR', '20.00', '2027-01-01 00:00:00'],
        ] as [$number, $total, $date]) {
            $this->createReportSale($owner, $number, $total, $date);
        }

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'yearly', 'date' => '2026-11-14',
        ]));

        $response->assertOk();
        $this->assertSame(['transaction_count' => 2, 'total_sales' => 100.0, 'average_sale' => 50.0], $response['report']['summary']);
        $this->assertSame(40.0, $response['report']['chart']['totals'][0]);
        $this->assertSame(60.0, $response['report']['chart']['totals'][11]);
        $this->assertSame([
            ['label' => 'Jan', 'transactions' => 1, 'total' => 40.0],
            ['label' => 'Dec', 'transactions' => 1, 'total' => 60.0],
        ], $response['report']['rows']);
    }

    public function test_daily_report_without_sales_has_zero_totals_and_an_empty_breakdown(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'daily', 'date' => '2026-11-14',
        ]));

        $response->assertOk()->assertSee('No sales recorded for this period.');
        $this->assertSame(['transaction_count' => 0, 'total_sales' => 0.0, 'average_sale' => 0.0], $response['report']['summary']);
        $this->assertSame([], $response['report']['rows']);
        $this->assertSame(array_fill(0, 24, 0.0), $response['report']['chart']['totals']);
    }

    public function test_yearly_report_without_sales_has_twelve_zero_buckets(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'yearly', 'date' => '2026-11-14',
        ]));

        $response->assertOk()->assertSee('No sales recorded for this period.');
        $this->assertSame(['transaction_count' => 0, 'total_sales' => 0.0, 'average_sale' => 0.0], $response['report']['summary']);
        $this->assertSame([], $response['report']['rows']);
        $this->assertSame(array_fill(0, 12, 0.0), $response['report']['chart']['totals']);
    }

    public function test_guest_cannot_view_or_download_reports(): void
    {
        foreach ([
            route('reports.index'),
            route('reports.sales.export', ['format' => 'pdf']),
            route('reports.inventory.export', ['format' => 'excel']),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_cashier_cannot_view_or_download_owner_reports(): void
    {
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier',
            'email' => 'report-cashier@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'cashier',
            'profile_image' => '',
            'status' => 'active',
        ]);

        foreach ([
            route('reports.index'),
            route('reports.sales.export', ['format' => 'pdf']),
            route('reports.inventory.export', ['format' => 'excel']),
        ] as $url) {
            $this->actingAs($cashier)->get($url)->assertForbidden();
        }
    }

    public function test_reports_reject_invalid_period_date_and_export_formats(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $this->actingAs($owner)->get(route('reports.index', ['period' => 'decade']))
            ->assertSessionHasErrors('period');
        $this->get(route('reports.index', ['date' => 'not-a-date']))
            ->assertSessionHasErrors('date');
        $this->get(route('reports.sales.export', ['period' => 'weekly', 'format' => 'csv']))
            ->assertSessionHasErrors('format');
        $this->get(route('reports.inventory.export', ['format' => 'csv']))
            ->assertSessionHasErrors('format');
        $this->get(route('reports.inventory.export'))
            ->assertSessionHasErrors('format');
    }

    public function test_inventory_report_shows_non_sales_adjustments_for_archived_products_safely(): void
    {
        [$owner, $activeProduct, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($activeProduct, $owner, 'increase', 1, 2, 3, 'Synthetic restock');
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, '<script>alert("demo")</script> archived item');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        $response = $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'monthly', 'date' => '2026-11-14',
        ]));

        $response->assertOk()
            ->assertSee('Stock Adjustment History')
            ->assertSee('Synthetic restock')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("demo")</script>', false);
        $this->assertCount(2, $response['adjustments']);
        $this->assertSame($archivedProduct->id, $response['adjustments'][0]->product->id);
    }

    public function test_inventory_report_shows_an_empty_adjustment_history_message(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $this->actingAs($owner)->get(route('reports.index', [
            'period' => 'monthly', 'date' => '2026-11-14',
        ]))->assertOk()->assertSee('No non-sales stock adjustments recorded yet.');
    }

    public function test_reports_label_paid_and_unpaid_transactions_as_recorded_sales(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();
        foreach (['paid', 'unpaid'] as $status) {
            SalesTransaction::query()->create([
                'transaction_no' => 'SYN-'.strtoupper($status),
                'sale_type' => 'walk_in',
                'online_platform' => '',
                'total_amount' => '100.00',
                'payment_status' => $status,
                'recorded_by' => $owner->id,
                'transaction_date' => '2026-11-14 09:00:00',
            ]);
        }

        $this->actingAs($owner)->get(route('reports.index', ['period' => 'daily', 'date' => '2026-11-14']))
            ->assertOk()
            ->assertSee('recorded sales')
            ->assertDontSee('completed sales');
    }

    public function test_reports_use_singular_recorded_sale_for_one_transaction(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();
        SalesTransaction::query()->create([
            'transaction_no' => 'SYN-UNPAID',
            'sale_type' => 'walk_in',
            'online_platform' => '',
            'total_amount' => '100.00',
            'payment_status' => 'unpaid',
            'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-14 09:00:00',
        ]);

        $this->actingAs($owner)->get(route('reports.index', ['period' => 'daily', 'date' => '2026-11-14']))
            ->assertOk()
            ->assertSee('recorded sale</p>', false)
            ->assertDontSee('completed sale');
    }

    public function test_sales_pdf_view_labels_transactions_as_recorded_sales(): void
    {
        $html = view('reports.exports.sales', [
            'report' => [
                'summary' => ['transaction_count' => 2, 'total_sales' => 200.0, 'average_sale' => 100.0],
                'rows' => [['label' => '09:00', 'transactions' => 2, 'total' => 200.0]],
            ],
            'period' => 'daily',
            'rangeLabel' => 'Nov 14, 2026',
        ])->render();

        $this->assertStringContainsString('recorded sales', $html);
        $this->assertStringNotContainsString('completed sales', $html);
    }

    public function test_inventory_pdf_view_includes_recorded_and_unknown_collection_dates(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $html = view('reports.exports.inventory', [
            'products' => collect([$datedProduct, $legacyProduct]),
            'adjustments' => collect(),
            'stats' => ['product_count' => 2, 'total_units' => 4, 'stock_value' => 400],
            'lowStockCount' => 0,
        ])->render();

        $this->assertStringContainsString('Collection Date', $html);
        $this->assertStringContainsString('2026-10-15', $html);
        $this->assertStringContainsString('Not recorded', $html);
    }

    public function test_inventory_pdf_view_includes_actual_product_categories(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $html = view('reports.exports.inventory', [
            'products' => collect([$datedProduct, $legacyProduct]),
            'adjustments' => collect(),
            'stats' => ['product_count' => 2, 'total_units' => 4, 'stock_value' => 400],
            'lowStockCount' => 0,
        ])->render();

        $this->assertStringContainsString('<td>Pants</td>', $html);
        $this->assertStringNotContainsString('<td>Uncategorized</td>', $html);
    }

    public function test_inventory_pdf_view_includes_archived_product_adjustments_and_escapes_reasons(): void
    {
        [$owner, , $product] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($product, $owner, 'decrease', 1, 2, 1, '<script>alert("demo")</script> damaged');
        $product->update(['status' => 'archived']);
        $product->delete();

        $html = view('reports.exports.inventory', [
            'products' => collect(),
            'adjustments' => InventoryAdjustment::with(['product', 'adjustedBy'])->get(),
            'stats' => ['product_count' => 0, 'total_units' => 0, 'stock_value' => 0],
            'lowStockCount' => 0,
        ])->render();

        $this->assertStringContainsString('Stock Adjustment History', $html);
        $this->assertStringContainsString('Synthetic Pants', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("demo")</script>', $html);
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

    public function test_inventory_excel_rows_include_actual_product_categories(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $rows = (new InventoryReportExport(collect([$datedProduct, $legacyProduct])))->collection()->all();

        $this->assertSame('Pants', $rows[0][2]);
        $this->assertSame('Pants', $rows[1][2]);
    }

    private function createReportSale(User $owner, string $number, string $total, string $date): void
    {
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

    private function createReportAdjustment(Product $product, User $owner, string $direction, int $quantity, int $before, int $after, string $reason): void
    {
        InventoryAdjustment::query()->create([
            'product_id' => $product->id,
            'adjusted_by' => $owner->id,
            'direction' => $direction,
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
        ]);
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
