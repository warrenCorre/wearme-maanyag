<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnpaidPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_records_and_views_a_multi_item_sale_with_one_balance(): void
    {
        [$owner, $products] = $this->createOwnerAndProducts();

        $this->actingAs($owner)->get(route('unpaid.create'))
            ->assertSee('name="delivery_address"', false)
            ->assertDontSee('name="item_description"', false);

        $this->actingAs($owner)->post(route('unpaid.store'), $this->salePayload($products))
            ->assertRedirect(route('unpaid.index'))->assertSessionHasNoErrors();

        $sale = SalesTransaction::query()->firstOrFail();
        $this->assertDatabaseHas('tbl_sales_transactions', ['id' => $sale->id, 'customer_name' => 'Synthetic Customer', 'delivery_address' => 'Synthetic address', 'total_amount' => '20.00']);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['transaction_id' => $sale->id, 'amount_paid' => '5.00', 'balance' => '15.00']);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
        $this->assertDatabaseCount('tbl_sales_transaction_items', 2);
        $summary = app(DashboardService::class)->unpaidSummary();
        $this->assertSame(1, $summary['pending_count']);
        $this->assertSame(15.0, $summary['outstanding_balance']);

        $this->actingAs($owner)->get(route('unpaid.index'))
            ->assertOk()->assertSee('Synthetic Customer')->assertSee('Synthetic Shirt')->assertSee('Synthetic Jacket');
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()->assertSee('Synthetic Customer')->assertSee('Synthetic Jacket');
    }

    public function test_unpaid_sale_requires_customer_details_and_valid_delivery_address(): void
    {
        [$owner, $products] = $this->createOwnerAndProducts();
        $invalid = $this->salePayload($products);
        unset($invalid['customer_name'], $invalid['customer_contact']);
        $invalid['delivery_address'] = str_repeat('x', 256);

        $this->actingAs($owner)->post(route('unpaid.store'), $invalid)
            ->assertSessionHasErrors(['customer_name', 'customer_contact', 'delivery_address']);

        $this->assertDatabaseCount('tbl_sales_transactions', 0);
        $this->assertDatabaseCount('tbl_unpaid_payments', 0);
        $this->assertSame(3, $products[0]->fresh()->quantity);
    }

    public function test_owner_can_record_later_payments_without_changing_sale_items_or_stock(): void
    {
        [$owner, $products] = $this->createOwnerAndProducts();
        $this->actingAs($owner)->post(route('unpaid.store'), $this->salePayload($products));
        $payment = UnpaidPayment::query()->firstOrFail();

        $this->actingAs($owner)->patch(route('unpaid.payment.update', $payment), ['amount_paid' => '10.00'])
            ->assertRedirect(route('unpaid.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '10.00', 'balance' => '10.00']);
        $this->actingAs($owner)->patch(route('unpaid.payment.update', $payment), ['amount_paid' => '4.00'])
            ->assertSessionHasErrors('amount_paid');
        $this->actingAs($owner)->patch(route('unpaid.payment.update', $payment), ['amount_paid' => '20.00'])
            ->assertRedirect(route('unpaid.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '20.00', 'balance' => '0.00', 'status' => 'settled']);
        $this->assertDatabaseCount('tbl_sales_transaction_items', 2);
        $this->assertSame(2, $products[0]->fresh()->quantity);
        $this->assertSame(2, $products[1]->fresh()->quantity);
    }

    public function test_owner_can_update_sale_customer_details_and_settle_payment_without_more_stock_changes(): void
    {
        [$owner, $products] = $this->createOwnerAndProducts();
        $this->actingAs($owner)->post(route('unpaid.store'), $this->salePayload($products));
        $sale = SalesTransaction::query()->firstOrFail();
        $payment = UnpaidPayment::query()->firstOrFail();

        $this->actingAs($owner)->patch(route('unpaid.transaction.update', $sale), [
            'customer_name' => 'Updated Synthetic Customer',
            'customer_contact' => '0000000002',
            'delivery_address' => null,
            'amount_paid' => '20.00',
        ])->assertRedirect(route('unpaid.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_sales_transactions', ['id' => $sale->id, 'customer_name' => 'Updated Synthetic Customer', 'customer_contact' => '0000000002', 'delivery_address' => null, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'amount_paid' => '20.00', 'balance' => '0.00', 'status' => 'settled']);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
        $this->assertSame(2, $products[0]->fresh()->quantity);
        $this->assertSame(2, $products[1]->fresh()->quantity);
    }

    public function test_guest_and_cashier_cannot_create_or_change_unpaid_sales(): void
    {
        [$owner, $products] = $this->createOwnerAndProducts();
        $cashier = $this->createUser('cashier');
        $payload = $this->salePayload($products);

        $this->post(route('unpaid.store'), $payload)->assertRedirect(route('login'));
        $this->actingAs($cashier)->post(route('unpaid.store'), $payload)->assertForbidden();
        $this->assertDatabaseCount('tbl_sales_transactions', 0);

        $this->actingAs($owner)->post(route('unpaid.store'), $payload)->assertRedirect(route('unpaid.index'));
        $sale = SalesTransaction::query()->firstOrFail();
        $payment = UnpaidPayment::query()->firstOrFail();

        $this->actingAs($cashier)->patch(route('unpaid.payment.update', $payment), ['amount_paid' => '20.00'])->assertForbidden();
        $this->actingAs($cashier)->patch(route('unpaid.transaction.update', $sale), ['amount_paid' => '20.00'])->assertForbidden();
        $this->assertDatabaseHas('tbl_unpaid_payments', ['id' => $payment->id, 'balance' => '15.00']);
    }

    /** @return array{0: User, 1: array<int, Product>} */
    private function createOwnerAndProducts(): array
    {
        $owner = $this->createUser('store_owner');
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $products = [];

        foreach (['Synthetic Shirt', 'Synthetic Jacket'] as $index => $name) {
            $products[] = Product::query()->create([
                'product_code' => "SYN-{$index}", 'product_name' => $name,
                'category_id' => $category->id, 'description' => 'Synthetic product',
                'collection_date' => '2026-11-05', 'price' => '10.00', 'quantity' => 3,
                'low_stock_threshold' => 1, 'image' => 'products/synthetic.jpg',
                'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
            ]);
        }

        return [$owner, $products];
    }

    private function createUser(string $role): User
    {
        return User::query()->create([
            'name' => 'Synthetic '.$role, 'email' => $role.'@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => $role,
            'profile_image' => '', 'status' => 'active',
        ]);
    }

    /** @param array<int, Product> $products
     * @return array<string, mixed>
     */
    private function salePayload(array $products): array
    {
        return [
            'sale_type' => 'online',
            'online_platform' => 'Facebook',
            'products' => array_map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 1], $products),
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic address',
            'amount_paid' => '5.00',
        ];
    }
}
