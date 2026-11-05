<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAdjustmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_increase_stock_and_read_the_audit_history(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();

        $this->actingAs($owner)->get(route('products.index'))->assertSee('Adjust Stock');
        $this->actingAs($owner)->get(route('products.adjustments.create', $product))
            ->assertOk()->assertSee('No stock adjustments have been recorded');

        $this->actingAs($owner)
            ->post(route('products.adjustments.store', $product), $this->adjustmentPayload())
            ->assertRedirect(route('products.adjustments.create', $product));

        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertDatabaseHas('tbl_inventory_adjustments', [
            'product_id' => $product->id,
            'adjusted_by' => $owner->id,
            'direction' => 'increase',
            'quantity' => 2,
            'quantity_before' => 5,
            'quantity_after' => 7,
            'reason' => 'Found two units during stock count.',
        ]);

        $this->actingAs($owner)->get(route('products.adjustments.create', $product))
            ->assertOk()->assertSee('Found two units during stock count.')->assertSee($owner->name);
    }

    public function test_damaged_items_can_only_decrease_stock(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();
        $invalid = array_replace($this->adjustmentPayload(), ['reason_kind' => 'damaged']);

        $this->actingAs($owner)->post(route('products.adjustments.store', $product), $invalid)
            ->assertSessionHasErrors('direction');
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertDatabaseCount('tbl_inventory_adjustments', 0);

        $valid = array_replace($invalid, ['direction' => 'decrease', 'reason' => 'Torn fabric.']);
        $this->actingAs($owner)->post(route('products.adjustments.store', $product), $valid)
            ->assertRedirect(route('products.adjustments.create', $product));

        $this->assertSame(3, $product->fresh()->quantity);
        $this->assertDatabaseHas('tbl_inventory_adjustments', [
            'product_id' => $product->id,
            'direction' => 'decrease',
            'quantity_before' => 5,
            'quantity_after' => 3,
            'reason' => 'Damaged item: Torn fabric.',
        ]);
    }

    public function test_invalid_adjustments_leave_stock_and_history_unchanged(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();

        foreach ([
            [['direction' => 'replace'], 'direction'],
            [['quantity' => 0], 'quantity'],
            [['quantity' => -1], 'quantity'],
            [['quantity' => 2147483648], 'quantity'],
            [['reason_kind' => 'unknown'], 'reason_kind'],
            [['reason' => ''], 'reason'],
            [['reason' => str_repeat('x', 241)], 'reason'],
            [['direction' => 'decrease', 'quantity' => 6], 'quantity'],
            [['direction' => 'increase', 'quantity' => 2147483647], 'quantity'],
        ] as [$change, $errorField]) {
            $this->actingAs($owner)
                ->post(route('products.adjustments.store', $product), array_replace($this->adjustmentPayload(), $change))
                ->assertSessionHasErrors($errorField);
            $this->assertSame(5, $product->fresh()->quantity);
            $this->assertDatabaseCount('tbl_inventory_adjustments', 0);
        }
    }

    public function test_guest_and_cashier_cannot_adjust_stock(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();
        $cashier = User::query()->create([
            'name' => 'Synthetic Cashier',
            'email' => 'cashier@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'cashier',
            'profile_image' => '',
            'status' => 'active',
        ]);

        $this->get(route('products.adjustments.create', $product))->assertRedirect(route('login'));
        $this->post(route('products.adjustments.store', $product), $this->adjustmentPayload())->assertRedirect(route('login'));
        $this->actingAs($cashier)->get(route('products.adjustments.create', $product))->assertForbidden();
        $this->actingAs($cashier)->post(route('products.adjustments.store', $product), $this->adjustmentPayload())->assertForbidden();
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertDatabaseCount('tbl_inventory_adjustments', 0);
    }

    public function test_archived_products_cannot_be_adjusted(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();
        $product->update(['status' => 'archived']);
        $product->delete();

        $this->actingAs($owner)->get(route('products.adjustments.create', $product->id))->assertNotFound();
        $this->actingAs($owner)->post(route('products.adjustments.store', $product->id), $this->adjustmentPayload())->assertNotFound();
        $this->assertDatabaseCount('tbl_inventory_adjustments', 0);
    }

    public function test_product_with_adjustment_history_cannot_be_permanently_removed(): void
    {
        [$owner, $product] = $this->createOwnerAndProduct();
        $this->actingAs($owner)->post(route('products.adjustments.store', $product), $this->adjustmentPayload())
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->patch(route('products.archive', $product))
            ->assertRedirect(route('products.index'));
        $this->actingAs($owner)->from(route('products.archive.index'))
            ->delete(route('products.permanent', $product->id))
            ->assertRedirect(route('products.archive.index'))
            ->assertSessionHasErrors('product');

        $this->assertNotNull(Product::withTrashed()->find($product->id));
        $this->assertDatabaseCount('tbl_inventory_adjustments', 1);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function createOwnerAndProduct(): array
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
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-001',
            'product_name' => 'Synthetic Pants',
            'category_id' => $category->id,
            'description' => 'Synthetic product.',
            'collection_date' => '2026-10-15',
            'price' => 100,
            'quantity' => 5,
            'low_stock_threshold' => 1,
            'image' => 'products/synthetic.jpg',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        return [$owner, $product];
    }

    /**
     * @return array{direction: string, quantity: int, reason_kind: string, reason: string}
     */
    private function adjustmentPayload(): array
    {
        return [
            'direction' => 'increase',
            'quantity' => 2,
            'reason_kind' => 'other',
            'reason' => 'Found two units during stock count.',
        ];
    }
}
