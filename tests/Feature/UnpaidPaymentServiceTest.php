<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnpaidPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_payment_update_refuses_a_sale_with_multiple_unpaid_rows_without_changing_balances(): void
    {
        [$owner, $transaction, $payments] = $this->createUnpaidSale(2);

        $this->actingAs($owner)
            ->patch(route('unpaid.payment.update', $payments[0]), ['amount_paid' => '10.00'])
            ->assertSessionHasErrors([
                'amount_paid' => 'This sale has multiple unpaid records. Update the transaction payment instead.',
            ]);

        $this->assertSame('unpaid', $transaction->fresh()->payment_status);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payments[0]->id,
            'amount_paid' => '0.00',
            'balance' => '10.00',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payments[1]->id,
            'amount_paid' => '0.00',
            'balance' => '10.00',
            'status' => 'pending',
        ]);
    }

    public function test_individual_payment_update_still_settles_a_sale_with_one_unpaid_row(): void
    {
        [$owner, $transaction, $payments] = $this->createUnpaidSale(1);

        $this->actingAs($owner)
            ->patch(route('unpaid.payment.update', $payments[0]), ['amount_paid' => '10.00'])
            ->assertRedirect(route('unpaid.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payments[0]->id,
            'amount_paid' => '10.00',
            'balance' => '0.00',
            'status' => 'settled',
        ]);
    }

    public function test_individual_payment_update_refuses_a_sale_with_a_settled_sibling_row(): void
    {
        [$owner, $transaction, $payments] = $this->createUnpaidSale(2);
        $payments[0]->update([
            'amount_paid' => '10.00',
            'balance' => '0.00',
            'status' => 'settled',
        ]);
        $transaction->update(['payment_status' => 'partially_paid']);

        $this->actingAs($owner)
            ->patch(route('unpaid.payment.update', $payments[1]), ['amount_paid' => '10.00'])
            ->assertSessionHasErrors([
                'amount_paid' => 'This sale has multiple unpaid records. Update the transaction payment instead.',
            ]);

        $this->assertSame('partially_paid', $transaction->fresh()->payment_status);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payments[0]->id,
            'amount_paid' => '10.00',
            'balance' => '0.00',
            'status' => 'settled',
        ]);
        $this->assertDatabaseHas('tbl_unpaid_payments', [
            'id' => $payments[1]->id,
            'amount_paid' => '0.00',
            'balance' => '10.00',
            'status' => 'pending',
        ]);
    }

    public function test_transaction_level_update_still_settles_a_sale_with_multiple_unpaid_rows(): void
    {
        [$owner, $transaction, $payments] = $this->createUnpaidSale(2);

        $this->actingAs($owner)
            ->patch(route('unpaid.transaction.update', $transaction), [
                'customer_name' => 'Synthetic Customer',
                'customer_contact' => '0000000001',
                'item_description' => 'Synthetic items',
                'amount_paid' => '20.00',
            ])
            ->assertRedirect(route('unpaid.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $transaction->fresh()->payment_status);

        foreach ($payments as $payment) {
            $this->assertDatabaseHas('tbl_unpaid_payments', [
                'id' => $payment->id,
                'amount_paid' => '10.00',
                'balance' => '0.00',
                'status' => 'settled',
            ]);
        }
    }

    /**
     * @return array{0: User, 1: SalesTransaction, 2: array<int, UnpaidPayment>}
     */
    private function createUnpaidSale(int $itemCount): array
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
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $transaction = SalesTransaction::query()->create([
            'transaction_no' => 'SYNTHETIC-UNPAID',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'total_amount' => $itemCount * 10,
            'payment_status' => 'unpaid',
            'cashier_id' => $owner->id,
            'transaction_date' => '2026-11-05 10:00:00',
        ]);

        $payments = [];

        for ($item = 1; $item <= $itemCount; $item++) {
            $product = Product::query()->create([
                'product_code' => "SYN-{$item}",
                'product_name' => "Synthetic Product {$item}",
                'category_id' => $category->id,
                'description' => 'Synthetic product.',
                'collection_date' => '2026-11-01',
                'price' => 10,
                'quantity' => 1,
                'low_stock_threshold' => 1,
                'image' => 'products/synthetic.jpg',
                'status' => 'active',
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]);

            $transaction->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => '10.00',
                'subtotal' => '10.00',
            ]);

            $payments[] = UnpaidPayment::query()->create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'customer_name' => 'Synthetic Customer',
                'customer_contact' => '0000000001',
                'item_description' => 'Synthetic items',
                'amount_paid' => '0.00',
                'balance' => '10.00',
                'payment_date' => null,
                'status' => 'pending',
                'updated_by' => $owner->id,
            ]);
        }

        return [$owner, $transaction, $payments];
    }
}
