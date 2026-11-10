<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\AssistantLatestUnpaidSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantLatestUnpaidSaleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_when_no_positive_pending_sale_exists(): void
    {
        $owner = $this->owner();
        $this->sale($owner, 'SYN-SETTLED', '2026-11-08 12:00:00', 'paid', '0.00', 'settled');
        $this->sale($owner, 'SYN-ZERO', '2026-11-09 12:00:00', 'unpaid', '0.00');

        $answer = app(AssistantLatestUnpaidSaleService::class)->answer();

        $this->assertSame('No pending unpaid sale with a positive balance was found.', $answer);
    }

    public function test_it_selects_one_positive_pending_sale_by_date_then_id_and_keeps_archived_product_names(): void
    {
        $owner = $this->owner();
        $category = Category::query()->create(['category_name' => 'Synthetic clothes', 'status' => 'active']);
        $activeProduct = $this->product($owner, $category, 'Synthetic Jacket');
        $archivedProduct = $this->product($owner, $category, 'Synthetic Scarf');
        $archivedProduct->delete();

        $this->sale($owner, 'SYN-OLDER', '2026-10-01 08:00:00');
        $this->sale($owner, 'SYN-TIE-1', '2026-11-07 14:30:00');
        $chosen = $this->sale($owner, 'SYN-TIE-2', '2026-11-07 14:30:00', 'partially_paid', '25.00', 'pending', '10.00', [
            'sale_type' => 'online',
            'online_platform' => 'Synthetic Live',
            'customer_name' => 'Synthetic Buyer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic Address',
        ]);
        $chosen->items()->create(['product_id' => $activeProduct->id, 'quantity' => 2, 'unit_price' => '10.00', 'subtotal' => '20.00']);
        $chosen->items()->create(['product_id' => $archivedProduct->id, 'quantity' => 1, 'unit_price' => '15.00', 'subtotal' => '15.00']);
        $this->sale($owner, 'SYN-SETTLED', '2026-11-08 08:00:00', 'paid', '0.00', 'settled');
        $this->sale($owner, 'SYN-ZERO', '2026-11-09 08:00:00', 'unpaid', '0.00');
        $this->sale($owner, 'SYN-PAID', '2026-11-10 08:00:00', 'paid');
        $this->sale($owner, 'SYN-NO-LINK', '2026-11-11 08:00:00', 'unpaid', null);

        $answer = app(AssistantLatestUnpaidSaleService::class)->answer();

        $this->assertSame(implode("\n", [
            'Latest unpaid sale',
            'Transaction: SYN-TIE-2',
            'Date/time: 2026-11-07 14:30',
            'Items:',
            '- Synthetic Jacket × 2',
            '- Synthetic Scarf × 1',
            'Sale type: Online',
            'Platform: Synthetic Live',
            'Total: ₱35.00',
            'Paid: ₱10.00',
            'Balance: ₱25.00',
            'Status: Partially paid',
            'Customer: Synthetic Buyer',
            'Contact: 0000000001',
            'Address: Synthetic Address',
        ]), $answer);
    }

    public function test_it_labels_missing_customer_and_item_fields_without_inventing_details(): void
    {
        $owner = $this->owner();
        $this->sale($owner, 'SYN-MISSING', '2026-11-07 10:00:00', 'unpaid', '35.00', 'pending', '0.00', [
            'sale_type' => 'online',
            'online_platform' => '',
            'customer_name' => null,
            'customer_contact' => null,
            'delivery_address' => null,
        ]);

        $answer = app(AssistantLatestUnpaidSaleService::class)->answer();

        $this->assertStringContainsString("Items: Not recorded\nSale type: Online\nPlatform: Not recorded", $answer);
        $this->assertStringContainsString("Customer: Not recorded\nContact: Not recorded\nAddress: Not recorded", $answer);
        $this->assertStringContainsString('Status: Unpaid', $answer);
    }

    private function owner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
    }

    private function product(User $owner, Category $category, string $name): Product
    {
        return Product::query()->create([
            'product_code' => 'SYN-'.str_replace('Synthetic ', '', $name),
            'product_name' => $name,
            'category_id' => $category->id,
            'description' => 'Synthetic item',
            'collection_date' => '2026-10-01',
            'price' => '15.00',
            'quantity' => 5,
            'low_stock_threshold' => 1,
            'image' => '',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sale(
        User $owner,
        string $number,
        string $date,
        string $paymentStatus = 'unpaid',
        ?string $balance = '35.00',
        string $unpaidStatus = 'pending',
        string $paid = '0.00',
        array $attributes = [],
    ): SalesTransaction {
        $sale = SalesTransaction::query()->create(array_merge([
            'transaction_no' => $number,
            'sale_type' => 'walk_in',
            'online_platform' => '',
            'customer_name' => 'Synthetic Buyer',
            'customer_contact' => '0000000001',
            'delivery_address' => null,
            'total_amount' => '35.00',
            'payment_status' => $paymentStatus,
            'recorded_by' => $owner->id,
            'transaction_date' => $date,
        ], $attributes));

        if ($balance !== null) {
            $sale->unpaidPayment()->create([
                'amount_paid' => $paid,
                'balance' => $balance,
                'payment_date' => null,
                'status' => $unpaidStatus,
                'updated_by' => $owner->id,
            ]);
        }

        return $sale;
    }
}
