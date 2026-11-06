<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Services\AssistantContextService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_approved_aggregates_without_customer_details(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));

        $ownerId = DB::table('tbl_users')->insertGetId([
            'name' => 'Synthetic Owner',
            'email' => 'owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = Category::query()->create([
            'category_name' => 'Synthetic clothing',
            'status' => 'active',
        ])->id;

        $bestSeller = $this->createProduct($ownerId, $categoryId, 'Synthetic Jacket', 2, 3);
        $otherProduct = $this->createProduct($ownerId, $categoryId, 'Synthetic Shirt', 5, 2);

        $transaction = SalesTransaction::query()->create([
            'transaction_no' => 'SYNTHETIC-001',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'total_amount' => 50,
            'payment_status' => 'paid',
            'recorded_by' => $ownerId,
            'transaction_date' => '2026-10-10 12:00:00',
        ]);

        SalesTransactionItem::query()->create([
            'transaction_id' => $transaction->id,
            'product_id' => $bestSeller->id,
            'quantity' => 3,
            'unit_price' => 10,
            'subtotal' => 30,
        ]);
        SalesTransactionItem::query()->create([
            'transaction_id' => $transaction->id,
            'product_id' => $otherProduct->id,
            'quantity' => 2,
            'unit_price' => 10,
            'subtotal' => 20,
        ]);

        $unpaidTransaction = SalesTransaction::query()->create([
            'transaction_no' => 'SYNTHETIC-002',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'customer_name' => 'Synthetic Customer Name',
            'customer_contact' => '0000000001',
            'total_amount' => 35,
            'payment_status' => 'partially_paid',
            'recorded_by' => $ownerId,
            'transaction_date' => '2026-10-10 12:00:00',
        ]);

        DB::table('tbl_unpaid_payments')->insert([
            'transaction_id' => $unpaidTransaction->id,
            'amount_paid' => 10,
            'balance' => 25,
            'payment_date' => null,
            'status' => 'pending',
            'updated_by' => $ownerId,
        ]);

        $context = app(AssistantContextService::class)->build(
            'monthly',
            CarbonImmutable::parse('2026-10-15'),
        );

        $this->assertSame([
            'period' => 'monthly',
            'date' => '2026-10-15',
            'summary' => [
                'transaction_count' => 2,
                'total_sales' => 85.0,
                'average_sale' => 42.5,
            ],
        ], $context['sales']);
        $this->assertSame(2, $context['inventory']['stats']['product_count']);
        $this->assertSame(7, $context['inventory']['stats']['total_units']);
        $this->assertSame('Synthetic Jacket', $context['inventory']['low_stock_products'][0]['product_name']);
        $this->assertSame('Synthetic Jacket', $context['best_sellers'][0]['product_name']);
        $this->assertSame(3, $context['best_sellers'][0]['units_sold']);
        $this->assertSame([], $context['collection_dates']);
        $this->assertSame([
            'pending_transaction_count' => 1,
            'outstanding_balance' => 25.0,
        ], $context['unpaid_summary']);
        $this->assertSame([
            'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
            'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
            'collection_dates' => 'Up to 10 active products with recorded collection dates, newest first; excludes archived products and unknown dates.',
            'unpaid_summary' => 'One pending balance per sale; excludes settled balances.',
        ], $context['scopes']);
        $this->assertSame([
            'sales' => '2026-10-15',
            'inventory' => '2026-11-02',
            'best_sellers' => '2026-11-02',
            'collection_dates' => '2026-11-02',
            'unpaid_summary' => '2026-11-02',
        ], $context['as_of']);
        $this->assertStringNotContainsString('Synthetic Customer', json_encode($context));
        $this->assertArrayNotHasKey('customer_name', $context['unpaid_summary']);
        $this->assertArrayNotHasKey('customer_contact', $context['unpaid_summary']);
    }

    public function test_it_excludes_settled_sale_balances_from_outstanding_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));

        $ownerId = DB::table('tbl_users')->insertGetId([
            'name' => 'Synthetic Owner',
            'email' => 'owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-SETTLED',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'customer_name' => 'Synthetic Customer Name',
            'total_amount' => '12.00',
            'payment_status' => 'paid',
            'recorded_by' => $ownerId,
            'transaction_date' => '2026-10-10 12:00:00',
        ]);

        DB::table('tbl_unpaid_payments')->insert([
            'transaction_id' => $transaction->id,
            'amount_paid' => 12,
            'balance' => 0,
            'payment_date' => '2026-10-10 12:00:00',
            'status' => 'settled',
            'updated_by' => $ownerId,
        ]);

        $context = app(AssistantContextService::class)->build('monthly', CarbonImmutable::parse('2026-10-15'));

        $this->assertSame([
            'pending_transaction_count' => 0,
            'outstanding_balance' => 0.0,
        ], $context['unpaid_summary']);
        $this->assertSame([
            'sales' => '2026-10-15',
            'inventory' => '2026-11-02',
            'best_sellers' => '2026-11-02',
            'collection_dates' => '2026-11-02',
            'unpaid_summary' => '2026-11-02',
        ], $context['as_of']);
        $this->assertStringNotContainsString('Synthetic Customer', json_encode($context));
        $this->assertArrayNotHasKey('customer_name', $context['unpaid_summary']);
        $this->assertArrayNotHasKey('customer_contact', $context['unpaid_summary']);
    }

    public function test_it_lists_only_the_ten_latest_active_products_with_recorded_collection_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
        $ownerId = DB::table('tbl_users')->insertGetId([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = Category::query()->create(['category_name' => 'Synthetic clothing', 'status' => 'active'])->id;

        for ($day = 1; $day <= 12; $day++) {
            $this->createProduct($ownerId, $categoryId, "Synthetic Item {$day}", 2, 1)
                ->update(['collection_date' => sprintf('2026-10-%02d', $day)]);
        }

        $this->createProduct($ownerId, $categoryId, 'Synthetic Unknown Date', 2, 1);
        $this->createProduct($ownerId, $categoryId, 'Synthetic Archived', 2, 1)
            ->update(['collection_date' => '2026-10-31', 'status' => 'archived']);
        $this->createProduct($ownerId, $categoryId, 'Synthetic Deleted', 2, 1)
            ->update(['collection_date' => '2026-10-30']);
        Product::query()->where('product_name', 'Synthetic Deleted')->firstOrFail()->delete();

        $context = app(AssistantContextService::class)->build('monthly', CarbonImmutable::parse('2026-10-15'));

        $this->assertCount(10, $context['collection_dates']);
        $this->assertSame(
            ['product_name' => 'Synthetic Item 12', 'collection_date' => '2026-10-12'],
            $context['collection_dates'][0],
        );
        $this->assertSame(
            ['product_name' => 'Synthetic Item 3', 'collection_date' => '2026-10-03'],
            $context['collection_dates'][9],
        );
        $this->assertSame('2026-11-02', $context['as_of']['collection_dates']);
        $this->assertStringNotContainsString('Synthetic Archived', json_encode($context['collection_dates']));
        $this->assertStringNotContainsString('Synthetic Unknown Date', json_encode($context['collection_dates']));
        $this->assertStringNotContainsString('Synthetic Deleted', json_encode($context['collection_dates']));
    }

    private function createProduct(int $ownerId, int $categoryId, string $name, int $quantity, int $threshold): Product
    {
        return Product::query()->create([
            'product_code' => strtoupper(str_replace(' ', '-', $name)),
            'product_name' => $name,
            'category_id' => $categoryId,
            'description' => 'Synthetic product description',
            'price' => 10,
            'quantity' => $quantity,
            'low_stock_threshold' => $threshold,
            'image' => '',
            'status' => 'active',
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
