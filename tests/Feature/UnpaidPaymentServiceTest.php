<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use App\Models\User;
use App\Services\UnpaidPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnpaidPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_item_sale_has_one_balance_and_customer_details_on_the_header(): void
    {
        [$owner, $sale, $payment, $products] = $this->createUnpaidSale(2);

        $this->assertDatabaseCount('tbl_sales_transaction_items', 2);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
        $this->assertDatabaseHas('tbl_sales_transactions', [
            'id' => $sale->id,
            'recorded_by' => $owner->id,
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic delivery address',
            'total_amount' => '20.00',
            'payment_status' => 'partially_paid',
        ]);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payment->id,
            'transaction_id' => $sale->id,
            'amount_paid' => '5.00',
            'balance' => '15.00',
            'status' => 'pending',
        ]);

        foreach ($products as $product) {
            $this->assertSame(2, $product->fresh()->quantity);
        }
    }

    public function test_a_sale_cannot_have_a_second_balance_row(): void
    {
        [, $sale] = $this->createUnpaidSale(1);

        $this->expectException(QueryException::class);

        $sale->unpaidPayment()->create([
            'amount_paid' => '0.00',
            'balance' => '10.00',
            'status' => 'pending',
            'updated_by' => $sale->recorded_by,
        ]);
    }

    public function test_balance_cannot_exist_without_a_sale(): void
    {
        [$owner] = $this->createUnpaidSale(1);

        $this->expectException(QueryException::class);

        UnpaidPayment::query()->create([
            'transaction_id' => null,
            'amount_paid' => '0.00',
            'balance' => '10.00',
            'status' => 'pending',
            'updated_by' => $owner->id,
        ]);
    }

    public function test_schema_change_refuses_to_discard_existing_sale_balances(): void
    {
        $this->createUnpaidSale(1);
        $migration = require database_path('migrations/2026_11_05_160419_align_tbl_unpaid_payments_to_one_row_per_sale.php');

        foreach (['up', 'down'] as $direction) {
            try {
                $migration->{$direction}();
                $this->fail('The migration must not discard an unpaid balance.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('while', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
    }

    public function test_schema_change_can_reverse_on_an_empty_unpaid_table(): void
    {
        $migration = require database_path('migrations/2026_11_05_160419_align_tbl_unpaid_payments_to_one_row_per_sale.php');

        $migration->down();

        $this->assertTrue(Schema::hasColumn('tbl_unpaid_payments', 'item_description'));
        $this->assertTrue(Schema::hasColumn('tbl_unpaid_payments', 'product_id'));

        $migration->up();

        $this->assertFalse(Schema::hasColumn('tbl_unpaid_payments', 'item_description'));
        $this->assertFalse(Schema::hasColumn('tbl_unpaid_payments', 'product_id'));
    }

    public function test_individual_payments_settle_the_whole_sale_without_deducting_stock_again(): void
    {
        [$owner, $sale, $payment, $products] = $this->createUnpaidSale(2);
        $service = app(UnpaidPaymentService::class);

        $service->updateCumulativePayment($payment, 1000, $owner);

        $this->assertSame('partially_paid', $sale->fresh()->payment_status);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '10.00', 'balance' => '10.00', 'status' => 'pending']);

        $service->updateCumulativePayment($payment, 2000, $owner);

        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '20.00', 'balance' => '0.00', 'status' => 'settled']);
        $this->assertNotNull($payment->fresh()->payment_date);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);

        foreach ($products as $product) {
            $this->assertSame(2, $product->fresh()->quantity);
        }
    }

    public function test_adding_an_item_updates_the_same_balance_and_deducts_only_the_new_stock(): void
    {
        [$owner, $sale, $payment, $products] = $this->createUnpaidSale(1);
        $newProduct = $this->createProduct($owner, 'SYN-EXTRA');

        $updated = app(UnpaidPaymentService::class)->updateTransaction($sale, [
            'customer_name' => 'Updated Synthetic Customer',
            'customer_contact' => '0000000002',
            'delivery_address' => null,
            'products' => [['product_id' => $newProduct->id, 'quantity' => 1]],
            'amount_paid' => '15.00',
        ], $owner);

        $this->assertSame($payment->id, $updated->unpaidPayment->id);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
        $this->assertDatabaseCount('tbl_sales_transaction_items', 2);
        $this->assertDatabaseHas('tbl_sales_transactions', ['id' => $sale->id, 'customer_name' => 'Updated Synthetic Customer', 'customer_contact' => '0000000002', 'delivery_address' => null, 'total_amount' => '20.00', 'payment_status' => 'partially_paid']);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '15.00', 'balance' => '5.00', 'status' => 'pending']);
        $this->assertSame(2, $products[0]->fresh()->quantity);
        $this->assertSame(2, $newProduct->fresh()->quantity);
    }

    public function test_invalid_cumulative_payment_rolls_back_new_items_and_stock(): void
    {
        [$owner, $sale, $payment] = $this->createUnpaidSale(1);
        $newProduct = $this->createProduct($owner, 'SYN-EXTRA');

        try {
            app(UnpaidPaymentService::class)->updateTransaction($sale, [
                'customer_name' => 'Changed Name',
                'customer_contact' => '0000000002',
                'products' => [['product_id' => $newProduct->id, 'quantity' => 1]],
                'amount_paid' => '25.00',
            ], $owner);
            $this->fail('An overpayment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_paid', $exception->errors());
        }

        $this->assertDatabaseCount('tbl_sales_transaction_items', 1);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
        $this->assertDatabaseHas('tbl_sales_transactions', ['id' => $sale->id, 'customer_name' => 'Synthetic Customer', 'total_amount' => '10.00']);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '5.00', 'balance' => '5.00']);
        $this->assertSame(3, $newProduct->fresh()->quantity);
    }

    /**
     * @return array{0: User, 1: SalesTransaction, 2: UnpaidPayment, 3: array<int, Product>}
     */
    private function createUnpaidSale(int $itemCount): array
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test', 'password' => 'synthetic-password',
            'phone' => '0000000000', 'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $products = [];

        for ($item = 1; $item <= $itemCount; $item++) {
            $products[] = $this->createProduct($owner, "SYN-{$item}");
        }

        $sale = app(UnpaidPaymentService::class)->recordPendingItems([
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic delivery address',
            'products' => array_map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 1], $products),
            'amount_paid' => '5.00',
        ], $owner);

        return [$owner, $sale, $sale->unpaidPayment, $products];
    }

    private function createProduct(User $owner, string $code): Product
    {
        $category = Category::query()->first() ?? Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);

        return Product::query()->create([
            'product_code' => $code, 'product_name' => "Synthetic {$code}",
            'category_id' => $category->id, 'description' => 'Synthetic product',
            'collection_date' => '2026-11-05', 'price' => '10.00', 'quantity' => 3,
            'low_stock_threshold' => 1, 'image' => 'products/synthetic.jpg',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
    }
}
