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
            'cashier_id' => $ownerId,
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

        DB::table('tbl_unpaid_payments')->insert([
            'transaction_id' => null,
            'product_id' => $bestSeller->id,
            'quantity' => 1,
            'customer_name' => 'Synthetic Customer Name',
            'customer_contact' => '0000000001',
            'item_description' => 'Synthetic unpaid item',
            'amount_paid' => 5,
            'balance' => 15,
            'payment_date' => '2026-10-10 12:00:00',
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
                'transaction_count' => 1,
                'total_sales' => 50.0,
                'average_sale' => 50.0,
            ],
        ], $context['sales']);
        $this->assertSame(2, $context['inventory']['stats']['product_count']);
        $this->assertSame(7, $context['inventory']['stats']['total_units']);
        $this->assertSame('Synthetic Jacket', $context['inventory']['low_stock_products'][0]['product_name']);
        $this->assertSame('Synthetic Jacket', $context['best_sellers'][0]['product_name']);
        $this->assertSame(3, $context['best_sellers'][0]['units_sold']);
        $this->assertSame([
            'pending_count' => 1,
            'outstanding_balance' => 15.0,
        ], $context['unpaid_summary']);
        $this->assertStringNotContainsString('Synthetic Customer', json_encode($context));
        $this->assertArrayNotHasKey('customer_name', $context['unpaid_summary']);
        $this->assertArrayNotHasKey('customer_contact', $context['unpaid_summary']);
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
