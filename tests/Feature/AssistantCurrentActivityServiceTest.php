<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AssistantCurrentActivityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantCurrentActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_zero_recorded_activity_without_claiming_a_complete_audit_trail(): void
    {
        $answer = app(AssistantCurrentActivityService::class)->answer(
            CarbonImmutable::parse('2026-11-16 16:30:00', 'UTC'),
        );

        $this->assertStringContainsString('November 17, 2026 (Philippine time)', $answer);
        $this->assertStringContainsString('Sales recorded: 0 (transaction value PHP 0.00', $answer);
        $this->assertStringContainsString('Non-sales stock adjustments: 0', $answer);
        $this->assertStringContainsString('Products added in current records: 0', $answer);
        $this->assertStringContainsString('Scheduled product collection dates today: 0', $answer);
        $this->assertStringContainsString('Previously unpaid sales settled: 0', $answer);
        $this->assertStringContainsString('Other tracked changes: none', $answer);
        $this->assertStringContainsString('Older edits, partial payments, and account changes', $answer);
    }

    public function test_philippine_day_includes_utc_evening_and_excludes_the_next_local_day(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-activity-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-ACTIVITY', 'product_name' => 'Synthetic Jacket',
            'category_id' => $category->id, 'description' => 'Synthetic item',
            'collection_date' => '2026-11-17', 'price' => '15.00',
            'quantity' => 10, 'low_stock_threshold' => 1, 'image' => '',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $product->timestamps = false;
        $product->created_at = '2026-11-16 16:15:00';
        $product->save();

        $sale = DB::table('tbl_sales_transactions')->insertGetId([
            'transaction_no' => 'SYN-ACT-1', 'sale_type' => 'walk_in',
            'online_platform' => '', 'customer_name' => 'Synthetic Customer',
            'customer_contact' => '0000000000', 'total_amount' => '45.00',
            'payment_status' => 'paid', 'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-16 16:05:00',
        ]);
        DB::table('tbl_sales_transactions')->insert([
            'transaction_no' => 'SYN-ACT-2', 'sale_type' => 'walk_in',
            'online_platform' => '', 'customer_name' => 'Synthetic Other',
            'customer_contact' => '0000000000', 'total_amount' => '99.00',
            'payment_status' => 'paid', 'recorded_by' => $owner->id,
            'transaction_date' => '2026-11-17 16:00:00',
        ]);
        DB::table('tbl_inventory_adjustments')->insert([
            'product_id' => $product->id, 'adjusted_by' => $owner->id,
            'direction' => 'increase', 'quantity' => 2,
            'quantity_before' => 10, 'quantity_after' => 12,
            'reason' => 'Synthetic private reason', 'created_at' => '2026-11-16 16:20:00',
        ]);
        DB::table('tbl_unpaid_payments')->insert([
            'transaction_id' => $sale, 'amount_paid' => '45.00',
            'balance' => '0.00', 'payment_date' => '2026-11-16 16:25:00',
            'status' => 'settled', 'updated_by' => $owner->id,
        ]);

        $answer = app(AssistantCurrentActivityService::class)->answer(
            CarbonImmutable::parse('2026-11-16 18:00:00', 'UTC'),
        );

        $this->assertStringContainsString('Sales recorded: 1 (transaction value PHP 45.00', $answer);
        $this->assertStringContainsString('Non-sales stock adjustments: 1', $answer);
        $this->assertStringContainsString('Products added in current records: 1', $answer);
        $this->assertStringContainsString('Scheduled product collection dates today: 1', $answer);
        $this->assertStringContainsString('Previously unpaid sales settled: 1', $answer);
        $this->assertStringNotContainsString('Synthetic', $answer);
        $this->assertStringNotContainsString('99.00', $answer);
    }

    public function test_it_counts_new_app_changes_and_shows_only_anonymous_recent_actions_with_philippine_times(): void
    {
        $owner = User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'synthetic-activity-owner@example.test',
            'password' => 'synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
        DB::table('tbl_store_activity_events')->insert([
            ['event_type' => 'product_updated', 'subject_type' => 'product', 'subject_id' => 91,
                'actor_id' => $owner->id, 'created_at' => '2026-11-16 16:15:00'],
            ['event_type' => 'payment_partial', 'subject_type' => 'sale', 'subject_id' => 92,
                'actor_id' => $owner->id, 'created_at' => '2026-11-16 16:30:00'],
            ['event_type' => 'cashier_updated', 'subject_type' => 'cashier', 'subject_id' => 93,
                'actor_id' => $owner->id, 'created_at' => '2026-11-17 16:00:00'],
        ]);

        $answer = app(AssistantCurrentActivityService::class)->answer(
            CarbonImmutable::parse('2026-11-16 18:00:00', 'UTC'),
        );

        $this->assertStringContainsString('product edits: 1; partial payments: 1', $answer);
        $this->assertStringContainsString('Partial payment recorded (12:30 AM Philippine time)', $answer);
        $this->assertStringContainsString('Product edited (12:15 AM Philippine time)', $answer);
        $this->assertStringNotContainsString('cashier account changes: 1', $answer);
        $this->assertStringNotContainsString('Synthetic Owner', $answer);
        $this->assertStringNotContainsString('91', $answer);
    }
}
