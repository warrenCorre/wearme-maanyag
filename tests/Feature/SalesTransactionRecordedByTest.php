<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\SalesTransactionService;
use App\Services\UnpaidPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTransactionRecordedByTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_sale_keeps_cashier_attribution_after_account_is_soft_deleted(): void
    {
        [$cashier, $product] = $this->createActorAndProduct('cashier');

        $sale = app(SalesTransactionService::class)->createPaidSale([
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'payment_status' => 'paid',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $cashier);

        $this->assertDatabaseHas('tbl_sales_transactions', [
            'id' => $sale->id,
            'recorded_by' => $cashier->id,
            'payment_status' => 'paid',
        ]);
        $this->assertSame($sale->id, $cashier->salesTransactions()->firstOrFail()->id);

        $cashier->delete();

        $this->assertSame($cashier->id, $sale->fresh()->user?->id);
    }

    public function test_unpaid_sale_records_the_owner_without_changing_payment_behavior(): void
    {
        [$owner, $product] = $this->createActorAndProduct('store_owner');

        $sale = app(UnpaidPaymentService::class)->recordPendingItems([
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'amount_paid' => '5.00',
        ], $owner);

        $this->assertDatabaseHas('tbl_sales_transactions', [
            'id' => $sale->id,
            'recorded_by' => $owner->id,
            'payment_status' => 'partially_paid',
        ]);
        $this->assertSame($owner->id, $sale->user?->id);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'transaction_id' => $sale->id,
            'amount_paid' => '5.00',
            'balance' => '5.00',
        ]);
        $this->assertSame(2, $product->fresh()->quantity);
    }

    public function test_sale_cannot_reference_a_nonexistent_recorder(): void
    {
        $this->expectException(QueryException::class);

        SalesTransaction::query()->create([
            'transaction_no' => 'SYN-INVALID-ACTOR',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'total_amount' => '10.00',
            'payment_status' => 'paid',
            'recorded_by' => 999,
            'transaction_date' => '2026-11-05 12:00:00',
        ]);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function createActorAndProduct(string $role): array
    {
        $actor = User::query()->create([
            'name' => 'Synthetic '.($role === 'cashier' ? 'Cashier' : 'Owner'),
            'email' => $role.'@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic store address',
            'role' => $role,
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'category_name' => 'Synthetic category',
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'product_code' => 'SYN-ACTOR-001',
            'product_name' => 'Synthetic Product',
            'category_id' => $category->id,
            'description' => 'Synthetic product',
            'collection_date' => '2026-11-05',
            'price' => '10.00',
            'quantity' => 3,
            'low_stock_threshold' => 1,
            'image' => 'products/synthetic.jpg',
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return [$actor, $product];
    }
}
