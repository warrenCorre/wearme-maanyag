<?php

namespace Tests\Feature;

use App\Exports\InventoryReportExport;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function test_inventory_adjustment_history_paginates_without_losing_filters_or_archived_products(): void
    {
        [$owner, $activeProduct, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, 'Old archived adjustment');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        for ($number = 1; $number <= 20; $number++) {
            $this->createReportAdjustment($activeProduct, $owner, 'increase', 1, 2, 3, "Synthetic adjustment {$number}");
        }

        $filters = ['period' => 'yearly', 'date' => '2026-11-14'];
        $firstPage = $this->actingAs($owner)->get(route('reports.index', $filters));

        $firstPage->assertOk()
            ->assertSee('Synthetic adjustment 20')
            ->assertSee('adjustments_page=2', false)
            ->assertDontSee('Old archived adjustment');
        $this->assertCount(20, $firstPage['adjustments']);
        $this->assertSame(21, $firstPage['adjustments']->total());
        $this->assertSame('adjustments_page', $firstPage['adjustments']->getPageName());
        $nextPageUrl = $firstPage['adjustments']->nextPageUrl();
        $this->assertStringContainsString('period=yearly', $nextPageUrl);
        $this->assertStringContainsString('date=2026-11-14', $nextPageUrl);
        $this->assertStringContainsString('adjustments_page=2', $nextPageUrl);
        $this->assertStringEndsWith('#inventory-report-panel', $nextPageUrl);

        $secondPage = $this->get($nextPageUrl);
        $secondPage->assertOk()
            ->assertSee('Old archived adjustment')
            ->assertDontSee('Synthetic adjustment 20');
        $this->assertCount(1, $secondPage['adjustments']);
        $this->assertSame($archivedProduct->id, $secondPage['adjustments'][0]->product->id);
        $this->assertSame('yearly', $secondPage['period']);
        $this->assertSame('2026-11-14', $secondPage['date']);
        $this->get(route('reports.index', [...$filters, 'adjustments_page' => 999]))
            ->assertOk()
            ->assertSee('No adjustments on this page.')
            ->assertDontSee('No non-sales stock adjustments recorded yet.');
    }

    public function test_inventory_adjustment_history_rejects_invalid_page_numbers(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $this->actingAs($owner)->get(route('reports.index', ['adjustments_page' => 'invalid']))
            ->assertSessionHasErrors('adjustments_page');
        $this->get(route('reports.index', ['adjustments_page' => 0]))
            ->assertSessionHasErrors('adjustments_page');
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
        $report = [
            'start' => CarbonImmutable::parse('2026-11-14'),
            'end' => CarbonImmutable::parse('2026-11-14 23:59:59'),
            'summary' => ['transaction_count' => 2, 'total_sales' => 200.0, 'average_sale' => 100.0],
            'rows' => [['label' => '09:00', 'transactions' => 2, 'total' => 200.0]],
        ];

        $html = view('reports.exports.sales', [
            'reports' => array_fill_keys(['daily', 'weekly', 'monthly', 'yearly'], $report),
            'date' => '2026-11-14',
        ])->render();

        $this->assertStringContainsString('recorded sales', $html);
        $this->assertStringNotContainsString('completed sales', $html);
        foreach (['Daily', 'Weekly', 'Monthly', 'Yearly'] as $period) {
            $this->assertStringContainsString($period.' Sales', $html);
        }
        $this->assertStringContainsString('These periods overlap; do not add their totals together.', $html);
    }

    public function test_sales_exports_include_all_four_periods_for_one_reference_date(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportSale($owner, 'SYN-TODAY', '100.00', '2026-11-14 09:00:00');
        $this->createReportSale($owner, 'SYN-YESTERDAY', '50.00', '2026-11-13 10:00:00');
        $this->createReportSale($owner, 'SYN-OCTOBER', '25.00', '2026-10-10 10:00:00');

        $excel = $this->actingAs($owner)->get(route('reports.sales.export', ['date' => '2026-11-14', 'format' => 'excel']));
        $excel->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Periods overlap: do not add the four totals together.', $sheet->getCell('A3')->getValue());
        $totals = [];

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $label = $sheet->getCell('A'.$row)->getValue();
            if (in_array($label, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
                $totals[$label] = [
                    'range' => $sheet->getCell('B'.$row)->getValue(),
                    'total' => $sheet->getCell('B'.($row + 1))->getValue(),
                    'transactions' => $sheet->getCell('B'.($row + 2))->getValue(),
                ];
            }
        }

        $this->assertSame(['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], array_keys($totals));
        $this->assertSame(['range' => 'Nov 14, 2026', 'total' => 100.0, 'transactions' => 1], $totals['DAILY']);
        $this->assertSame(['range' => 'Nov 9, 2026 - Nov 15, 2026', 'total' => 150.0, 'transactions' => 2], $totals['WEEKLY']);
        $this->assertSame(['range' => 'Nov 1, 2026 - Nov 30, 2026', 'total' => 150.0, 'transactions' => 2], $totals['MONTHLY']);
        $this->assertSame(['range' => 'Jan 1, 2026 - Dec 31, 2026', 'total' => 175.0, 'transactions' => 3], $totals['YEARLY']);

        $pdf = $this->get(route('reports.sales.export', ['date' => '2026-11-14', 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $reports = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $reports[$period] = app(ReportService::class)->salesReport($period, CarbonImmutable::parse('2026-11-14'));
        }
        $html = view('reports.exports.sales', ['reports' => $reports, 'date' => '2026-11-14'])->render();
        foreach (['100.00', '150.00', '175.00'] as $amount) {
            $this->assertStringContainsString('PHP '.$amount, $html);
        }
    }

    public function test_sales_excel_export_labels_all_four_periods_when_there_are_no_sales(): void
    {
        [$owner] = $this->createProductsWithRecordedAndUnknownDates();

        $response = $this->actingAs($owner)->get(route('reports.sales.export', ['date' => '2026-11-14', 'format' => 'excel']));

        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $periods = [];
        $emptyMessages = 0;

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $label = $sheet->getCell('A'.$row)->getValue();
            if (in_array($label, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
                $periods[] = $label;
                $this->assertSame(0.0, $sheet->getCell('B'.($row + 1))->getValue());
            }

            if ($label === 'No sales recorded for this period.') {
                $emptyMessages++;
            }
        }

        $this->assertSame(['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], $periods);
        $this->assertSame(4, $emptyMessages);
    }

    public function test_inventory_pdf_view_includes_recorded_and_unknown_collection_dates(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $html = view('reports.exports.inventory', [
            'products' => collect([$datedProduct, $legacyProduct]),
            'lowStockProducts' => collect(),
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
            'lowStockProducts' => collect(),
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
            'lowStockProducts' => collect(),
            'adjustments' => InventoryAdjustment::with(['product', 'adjustedBy'])->get(),
            'stats' => ['product_count' => 0, 'total_units' => 0, 'stock_value' => 0],
            'lowStockCount' => 0,
        ])->render();

        $this->assertStringContainsString('Stock Adjustment History', $html);
        $this->assertStringContainsString('Synthetic Pants', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("demo")</script>', $html);
    }

    public function test_inventory_pdf_includes_all_low_stock_products_and_adjustment_history(): void
    {
        [$owner, $lowStockProduct, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $lowStockProduct->update(['quantity' => 1]);
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, 'Synthetic damage');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        $html = view('reports.exports.inventory', [
            'products' => collect([$lowStockProduct->load('category')]),
            'lowStockProducts' => collect([$lowStockProduct]),
            'adjustments' => InventoryAdjustment::with(['product', 'adjustedBy'])->get(),
            'stats' => ['product_count' => 1, 'total_units' => 1, 'stock_value' => 100.0],
            'lowStockCount' => 1,
        ])->render();

        $this->assertStringContainsString('Low-Stock Details', $html);
        $this->assertStringContainsString('All Active Products', $html);
        $this->assertStringContainsString('Stock Adjustment History', $html);
        $this->assertStringContainsString('Synthetic damage', $html);
        $this->assertStringContainsString('2026-10-15', $html);

        $this->actingAs($owner)->get(route('reports.inventory.export', ['format' => 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_inventory_excel_rows_include_recorded_and_unknown_collection_dates(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $export = new InventoryReportExport(collect([$datedProduct, $legacyProduct]));
        $rows = iterator_to_array($export->generator(), false);
        $productRow = $this->inventorySectionDataRow($rows, 'All Active Products');

        $this->assertSame('Wear Me Maanyag - Inventory Report', $export->headings()[0]);
        $this->assertSame('Collection Date', $rows[$productRow - 1][3]);
        $this->assertSame('2026-10-15', $rows[$productRow][3]);
        $this->assertSame('Not recorded', $rows[$productRow + 1][3]);
    }

    public function test_inventory_excel_rows_include_actual_product_categories(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $rows = iterator_to_array((new InventoryReportExport(collect([$datedProduct, $legacyProduct])))->generator(), false);
        $productRow = $this->inventorySectionDataRow($rows, 'All Active Products');

        $this->assertSame('Pants', $rows[$productRow][2]);
        $this->assertSame('Pants', $rows[$productRow + 1][2]);
    }

    public function test_inventory_excel_rows_include_archived_product_adjustments_as_a_separate_table(): void
    {
        [$owner, $activeProduct, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, '=HYPERLINK("https://example.test","demo")');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        $rows = iterator_to_array((new InventoryReportExport(
            collect([$activeProduct->load('category')]),
            InventoryAdjustment::with(['product', 'adjustedBy'])->get(),
        ))->generator(), false);
        $productRow = $this->inventorySectionDataRow($rows, 'All Active Products');
        $adjustmentRow = $this->inventorySectionDataRow($rows, 'Stock Adjustment History');

        $this->assertSame('SYN-001', $rows[$productRow][0]);
        $this->assertSame([''], $rows[$adjustmentRow - 3]);
        $this->assertSame(['Stock Adjustment History'], $rows[$adjustmentRow - 2]);
        $this->assertSame(['Recorded', 'Product', 'Direction', 'Quantity', 'Before', 'After', 'Reason', 'Owner'], $rows[$adjustmentRow - 1]);
        $this->assertSame('Synthetic Pants', $rows[$adjustmentRow][1]);
        $this->assertSame('Decrease', $rows[$adjustmentRow][2]);
        $this->assertSame([1, 2, 1], array_slice($rows[$adjustmentRow], 3, 3));
        $this->assertSame('\'=HYPERLINK("https://example.test","demo")', $rows[$adjustmentRow][6]);
        $this->assertSame('Synthetic Owner', $rows[$adjustmentRow][7]);
    }

    public function test_inventory_excel_rows_explain_when_no_adjustment_history_exists(): void
    {
        [, $datedProduct, $legacyProduct] = $this->createProductsWithRecordedAndUnknownDates();

        $rows = iterator_to_array((new InventoryReportExport(collect([$datedProduct, $legacyProduct])))->generator(), false);
        $adjustmentRow = $this->inventorySectionDataRow($rows, 'Stock Adjustment History');

        $this->assertSame(['No non-sales stock adjustments recorded yet.'], $rows[$adjustmentRow]);
    }

    public function test_inventory_excel_treats_product_names_starting_with_a_formula_as_text(): void
    {
        [, $product] = $this->createProductsWithRecordedAndUnknownDates();
        $product->product_name = '=1+1';

        $rows = iterator_to_array((new InventoryReportExport(collect([$product])))->generator(), false);

        $this->assertSame("'=1+1", $rows[$this->inventorySectionDataRow($rows, 'All Active Products')][1]);
    }

    public function test_inventory_excel_download_contains_adjustment_history_as_plain_text(): void
    {
        [$owner, , $product] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($product, $owner, 'decrease', 1, 2, 1, '=1+1');
        $product->update(['status' => 'archived']);
        $product->delete();

        $response = $this->actingAs($owner)->get(route('reports.inventory.export', ['format' => 'excel']));

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $adjustmentRow = $this->spreadsheetSectionDataRow($sheet, 'Stock Adjustment History');
        $this->assertSame('Synthetic Pants', $sheet->getCell('B'.$adjustmentRow)->getValue());
        $this->assertSame("'=1+1", $sheet->getCell('G'.$adjustmentRow)->getValue());
        $this->assertSame('Synthetic Owner', $sheet->getCell('H'.$adjustmentRow)->getValue());
    }

    public function test_inventory_excel_download_includes_adjustments_beyond_the_first_database_chunk(): void
    {
        [$owner, $activeProduct, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, 'Old archived adjustment');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        for ($number = 1; $number <= 204; $number++) {
            $this->createReportAdjustment($activeProduct, $owner, 'increase', 1, 2, 3, "Synthetic adjustment {$number}");
        }

        $response = $this->actingAs($owner)->get(route('reports.inventory.export', ['format' => 'excel']));

        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $adjustmentRow = $this->spreadsheetSectionDataRow($sheet, 'Stock Adjustment History');
        $this->assertSame('Synthetic adjustment 204', $sheet->getCell('G'.$adjustmentRow)->getValue());
        $this->assertSame('Old archived adjustment', $sheet->getCell('G'.($adjustmentRow + 204))->getValue());
        $this->assertSame('Synthetic Pants', $sheet->getCell('B'.($adjustmentRow + 204))->getValue());
        $this->assertSame($adjustmentRow + 204, $sheet->getHighestDataRow());
    }

    public function test_inventory_excel_download_contains_overview_low_stock_products_and_archived_adjustments(): void
    {
        [$owner, $product, $archivedProduct] = $this->createProductsWithRecordedAndUnknownDates();
        $product->update(['quantity' => 1]);
        $this->createReportAdjustment($archivedProduct, $owner, 'decrease', 1, 2, 1, 'Synthetic damaged item');
        $archivedProduct->update(['status' => 'archived']);
        $archivedProduct->delete();

        $response = $this->actingAs($owner)->get(route('reports.inventory.export', ['format' => 'excel']));

        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Inventory Overview', $sheet->getCell('A3')->getValue());
        $this->assertSame(1, $sheet->getCell('B4')->getValue());
        $this->assertSame(1, $sheet->getCell('B5')->getValue());
        $this->assertSame(100.0, $sheet->getCell('B6')->getValue());
        $this->assertSame(1, $sheet->getCell('B7')->getValue());
        $lowStockRow = $this->spreadsheetSectionDataRow($sheet, 'Low-Stock Details');
        $this->assertSame('SYN-001', $sheet->getCell('A'.$lowStockRow)->getValue());
        $this->assertSame('2026-10-15', $sheet->getCell('D'.$lowStockRow)->getValue());
        $productRow = $this->spreadsheetSectionDataRow($sheet, 'All Active Products');
        $this->assertSame('Low Stock', $sheet->getCell('H'.$productRow)->getValue());
        $adjustmentRow = $this->spreadsheetSectionDataRow($sheet, 'Stock Adjustment History');
        $this->assertSame('Synthetic damaged item', $sheet->getCell('G'.$adjustmentRow)->getValue());
        $this->assertSame('Synthetic Pants', $sheet->getCell('B'.$adjustmentRow)->getValue());
    }

    public function test_inventory_excel_download_shows_zero_totals_and_empty_sections(): void
    {
        [$owner, $first, $second] = $this->createProductsWithRecordedAndUnknownDates();
        foreach ([$first, $second] as $product) {
            $product->update(['status' => 'archived']);
            $product->delete();
        }

        $response = $this->actingAs($owner)->get(route('reports.inventory.export', ['format' => 'excel']));

        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(0, $sheet->getCell('B4')->getValue());
        $this->assertSame(0, $sheet->getCell('B5')->getValue());
        $this->assertSame(0.0, $sheet->getCell('B6')->getValue());
        $this->assertSame('No low-stock products.', $sheet->getCell('A'.$this->spreadsheetSectionDataRow($sheet, 'Low-Stock Details'))->getValue());
        $this->assertSame('No active products yet.', $sheet->getCell('A'.$this->spreadsheetSectionDataRow($sheet, 'All Active Products'))->getValue());
        $this->assertSame('No non-sales stock adjustments recorded yet.', $sheet->getCell('A'.$this->spreadsheetSectionDataRow($sheet, 'Stock Adjustment History'))->getValue());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function inventorySectionDataRow(array $rows, string $section): int
    {
        $index = array_search([$section], $rows, true);
        $this->assertNotFalse($index);

        return $index + 2;
    }

    private function spreadsheetSectionDataRow(Worksheet $sheet, string $section): int
    {
        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            if ($sheet->getCell('A'.$row)->getValue() === $section) {
                return $row + 2;
            }
        }

        $this->fail("Missing {$section} section in inventory workbook.");
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
