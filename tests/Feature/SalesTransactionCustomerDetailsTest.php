<?php

namespace Tests\Feature;

use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTransactionCustomerDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partially_paid_sale_persists_customer_details_on_the_sales_header(): void
    {
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier',
            'email' => 'cashier-one@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic store address',
            'role' => 'cashier',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);

        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-CUSTOMER-001',
            'sale_type' => 'online',
            'online_platform' => 'Facebook',
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic delivery address',
            'total_amount' => '150.00',
            'payment_status' => 'partially_paid',
            'cashier_id' => $cashier->id,
            'transaction_date' => '2026-11-05 10:00:00',
        ]);

        $this->assertDatabaseHas('tbl_sales_transactions', [
            'id' => $sale->id,
            'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000001',
            'delivery_address' => 'Synthetic delivery address',
        ]);
    }

    public function test_paid_walk_in_sale_can_remain_anonymous(): void
    {
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier',
            'email' => 'cashier-two@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic store address',
            'role' => 'cashier',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);

        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-ANONYMOUS-001',
            'sale_type' => 'walk_in',
            'online_platform' => 'N/A',
            'total_amount' => '50.00',
            'payment_status' => 'paid',
            'cashier_id' => $cashier->id,
            'transaction_date' => '2026-11-05 11:00:00',
        ]);

        $this->assertDatabaseHas('tbl_sales_transactions', [
            'id' => $sale->id,
            'customer_name' => null,
            'customer_contact' => null,
            'delivery_address' => null,
        ]);
    }
}
