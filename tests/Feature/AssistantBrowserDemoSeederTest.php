<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantContextService;
use App\Services\AssistantLatestUnpaidSaleService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AssistantBrowserDemoSeeder;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AssistantBrowserDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_populates_paid_rankings_pending_balances_stock_and_latest_sale_without_a_provider(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        Storage::fake('public');
        Http::preventStrayRequests();
        $owner = $this->createOwner();

        $this->seedDemo();

        $this->assertDatabaseCount('tbl_categories', 3);
        $this->assertDatabaseCount('tbl_products', 5);
        $this->assertSame(4, Product::query()->where('status', 'active')->count());
        $this->assertSame(1, Product::onlyTrashed()->count());
        $this->assertDatabaseCount('tbl_sales_transactions', 6);
        $this->assertDatabaseCount('tbl_sales_transaction_items', 9);
        $this->assertDatabaseCount('tbl_unpaid_payments', 3);
        $this->assertDatabaseCount('tbl_inventory_adjustments', 2);
        $this->assertDatabaseHas('tbl_products', ['product_code' => 'DEMO-JACKET', 'quantity' => 9, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('tbl_products', ['product_code' => 'DEMO-BLOUSE', 'quantity' => 5]);
        $this->assertDatabaseHas('tbl_products', ['product_code' => 'DEMO-TEE', 'quantity' => 0]);
        $this->assertDatabaseHas('tbl_products', ['product_code' => 'DEMO-SCARF', 'quantity' => 0]);
        $this->assertDatabaseHas('tbl_sales_transactions', ['transaction_no' => 'WM-DEMO-SETTLED', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('tbl_sales_transactions', ['transaction_no' => 'WM-DEMO-PARTIAL', 'payment_status' => 'partially_paid', 'total_amount' => '350.00']);
        $this->assertDatabaseHas('tbl_inventory_adjustments', ['direction' => 'increase', 'quantity_before' => 7, 'quantity_after' => 9]);
        $this->assertDatabaseHas('tbl_inventory_adjustments', ['direction' => 'decrease', 'quantity_before' => 1, 'quantity_after' => 0, 'reason' => 'Damaged item: DEMO torn fabric.']);
        Storage::disk('public')->assertExists('products/demo-browser-fixture.png');

        $context = app(AssistantContextService::class)->build('monthly', CarbonImmutable::today());
        $this->assertSame(['product_count' => 4, 'total_units' => 14, 'stock_value' => 5750.0], $context['inventory']['stats']);
        $this->assertSame(['pending_transaction_count' => 2, 'outstanding_balance' => 550.0], $context['unpaid_summary']);
        $this->assertSame([
            ['product_name' => 'DEMO Denim Jacket', 'units_sold' => 3, 'sales_total' => 1500.0],
            ['product_name' => 'DEMO Linen Blouse', 'units_sold' => 2, 'sales_total' => 500.0],
            ['product_name' => 'DEMO Cotton Tee', 'units_sold' => 1, 'sales_total' => 150.0],
            ['product_name' => 'DEMO Vintage Scarf', 'units_sold' => 1, 'sales_total' => 100.0],
        ], $context['best_sellers']);
        $this->assertSame(['DEMO Vintage Scarf', 'DEMO Cotton Tee'], array_column($context['inventory']['low_stock_products'], 'product_name'));
        $this->assertSame(['DEMO Vintage Scarf', 'DEMO Cotton Tee', 'DEMO Linen Blouse', 'DEMO Denim Jacket'], array_column($context['collection_dates'], 'product_name'));
        $this->assertSame(['transaction_count' => 5, 'total_sales' => 2400.0, 'average_sale' => 480.0], $context['sales']['summary']);
        $this->assertStringNotContainsString('DEMO Partial One', json_encode($context));

        $reports = app(ReportService::class);
        $this->assertSame(['transaction_count' => 1, 'total_sales' => 500.0, 'average_sale' => 500.0], $reports->salesReport('monthly', CarbonImmutable::today()->subMonthNoOverflow())['summary']);
        $this->assertSame('image/png', getimagesizefromstring(Storage::disk('public')->get('products/demo-browser-fixture.png'))['mime']);

        $latestSale = app(AssistantLatestUnpaidSaleService::class)->answer();
        $this->assertStringContainsString('WM-DEMO-PARTIAL', $latestSale);
        $this->assertStringContainsString('Balance: ₱250.00', $latestSale);
        $this->assertStringContainsString('DEMO Partial One', $latestSale);
    }

    public function test_existing_demo_fixtures_cannot_be_reseeded_or_overwritten(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00'));
        Storage::fake('public');
        $this->createOwner();
        $this->seedDemo();
        $originalImage = Storage::disk('public')->get('products/demo-browser-fixture.png');

        try {
            $this->seedDemo();
            $this->fail('Reseeding existing DEMO records must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
        }

        $this->assertDatabaseCount('tbl_sales_transactions', 6);
        $this->assertDatabaseHas('tbl_products', ['product_code' => 'DEMO-JACKET', 'quantity' => 9]);
        $this->assertSame($originalImage, Storage::disk('public')->get('products/demo-browser-fixture.png'));
    }

    public function test_partial_demo_marker_blocks_all_fixture_writes(): void
    {
        Storage::fake('public');
        $this->createOwner();
        Category::query()->create(['category_name' => 'DEMO Tops', 'status' => 'active']);

        try {
            $this->seedDemo();
            $this->fail('An incomplete fixture set must not be extended blindly.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exist or are incomplete', $exception->getMessage());
        }

        $this->assertDatabaseCount('tbl_categories', 1);
        $this->assertDatabaseCount('tbl_products', 0);
        $this->assertDatabaseCount('tbl_sales_transactions', 0);
        Storage::disk('public')->assertMissing('products/demo-browser-fixture.png');
    }

    public function test_missing_active_owner_leaves_database_and_disk_untouched(): void
    {
        Storage::fake('public');

        try {
            $this->seedDemo();
            $this->fail('The seeder must require an existing active Owner.');
        } catch (RecordsNotFoundException $exception) {
            $this->assertDatabaseCount('tbl_categories', 0);
        }

        $this->assertDatabaseCount('tbl_products', 0);
        Storage::disk('public')->assertMissing('products/demo-browser-fixture.png');
    }

    private function seedDemo(): void
    {
        app()->call([app(AssistantBrowserDemoSeeder::class), 'run']);
    }

    private function createOwner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'fixture-owner@example.test',
            'password' => 'not-a-live-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);
    }
}
