<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_recorded_and_unknown_collection_dates_in_the_product_list(): void
    {
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-001',
            'product_name' => 'Synthetic Pants',
            'category_id' => $category->id,
            'description' => 'Synthetic product.',
            'collection_date' => '2026-10-15',
            'price' => 100,
            'quantity' => 2,
            'low_stock_threshold' => 1,
            'image' => 'products/synthetic.jpg',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $legacyProduct = $product->replicate();
        $legacyProduct->product_code = 'SYN-002';
        $legacyProduct->collection_date = null;
        $legacyProduct->save();

        $response = $this->actingAs($owner)->get(route('products.index'));

        $response->assertSee('Collection Date');
        $response->assertSee('2026-10-15');
        $response->assertSee('Not recorded');
    }

    public function test_owner_sees_the_collection_date_field_on_the_product_form(): void
    {
        $owner = $this->createOwner();

        $response = $this->actingAs($owner)->get(route('products.create'));

        $response->assertSee('Collection Date');
        $response->assertSee('name="collection_date"', false);
    }

    public function test_owner_creates_a_product_with_its_collection_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00'));
        Storage::fake('public');
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);

        $response = $this->actingAs($owner)->post(route('products.store'), $this->productPayload($category, '2026-10-15'));

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('tbl_products', [
            'product_code' => 'SYN-001',
        ]);
        $this->assertSame('2026-10-15', Product::query()->firstOrFail()->collection_date->toDateString());
    }

    public function test_owner_cannot_create_a_product_without_a_collection_date(): void
    {
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);
        $payload = $this->productPayload($category, '2026-10-15');
        unset($payload['collection_date']);

        $response = $this->actingAs($owner)->post(route('products.store'), $payload);

        $response->assertSessionHasErrors('collection_date');
        $this->assertDatabaseCount('tbl_products', 0);
    }

    public function test_owner_cannot_create_a_product_with_a_future_collection_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00'));
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);

        $response = $this->actingAs($owner)->post(route('products.store'), $this->productPayload($category, '2026-11-06'));

        $response->assertSessionHasErrors('collection_date');
        $this->assertDatabaseCount('tbl_products', 0);
    }

    public function test_owner_cannot_create_a_product_with_an_invalid_collection_date(): void
    {
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);

        $response = $this->actingAs($owner)->post(route('products.store'), $this->productPayload($category, 'not-a-date'));

        $response->assertSessionHasErrors('collection_date');
        $this->assertDatabaseCount('tbl_products', 0);
    }

    public function test_owner_records_a_real_date_when_editing_a_legacy_product(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00'));
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Pants', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-001',
            'product_name' => 'Synthetic Pants',
            'category_id' => $category->id,
            'description' => 'Synthetic product.',
            'collection_date' => null,
            'price' => 100,
            'quantity' => 2,
            'low_stock_threshold' => 1,
            'image' => 'products/legacy.jpg',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $payload = $this->productPayload($category, '2026-10-15');
        unset($payload['image']);
        unset($payload['quantity']);

        $response = $this->actingAs($owner)->put(route('products.update', $product), $payload);

        $response->assertRedirect(route('products.index'));
        $this->assertSame('2026-10-15', $product->fresh()->collection_date->toDateString());
    }

    public function test_owner_cannot_change_stock_through_the_product_edit_form(): void
    {
        $owner = $this->createOwner();
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

        $this->actingAs($owner)
            ->get(route('products.edit', $product))
            ->assertSee('Adjust Stock')
            ->assertDontSee('name="quantity"', false);

        $payload = $this->productPayload($category, '2026-10-15');
        unset($payload['image']);
        $payload['quantity'] = 10;

        $this->actingAs($owner)
            ->put(route('products.update', $product), $payload)
            ->assertSessionHasErrors('quantity');

        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertDatabaseCount('tbl_inventory_adjustments', 0);

        $payload['quantity'] = '';
        $this->actingAs($owner)
            ->put(route('products.update', $product), $payload)
            ->assertRedirect(route('products.index'));

        $this->assertSame(5, $product->fresh()->quantity);
    }

    public function test_owner_cannot_permanently_delete_a_product_referenced_by_an_unpaid_sale_item(): void
    {
        $owner = $this->createOwner();
        $category = Category::query()->create(['category_name' => 'Synthetic category', 'status' => 'active']);
        $product = Product::query()->create([
            'product_code' => 'SYN-HISTORY', 'product_name' => 'Synthetic Jacket',
            'category_id' => $category->id, 'description' => 'Synthetic product',
            'collection_date' => '2026-11-05', 'price' => '10.00', 'quantity' => 2,
            'low_stock_threshold' => 1, 'image' => 'products/synthetic.jpg',
            'status' => 'active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $sale = SalesTransaction::query()->create([
            'transaction_no' => 'SYN-UNPAID-HISTORY', 'sale_type' => 'walk_in',
            'online_platform' => 'N/A', 'customer_name' => 'Synthetic Customer',
            'total_amount' => '10.00', 'payment_status' => 'unpaid',
            'recorded_by' => $owner->id, 'transaction_date' => '2026-11-05 10:00:00',
        ]);
        $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00']);
        $sale->unpaidPayment()->create(['amount_paid' => '0.00', 'balance' => '10.00', 'status' => 'pending', 'updated_by' => $owner->id]);
        $product->update(['status' => 'archived']);
        $product->delete();

        $this->actingAs($owner)->delete(route('products.permanent', $product->id))->assertSessionHasErrors('product');

        $this->assertDatabaseHas('tbl_products', ['id' => $product->id, 'status' => 'archived']);
        $this->assertDatabaseCount('tbl_sales_transaction_items', 1);
        $this->assertDatabaseCount('tbl_unpaid_payments', 1);
    }

    private function createOwner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner',
            'email' => 'owner@example.test',
            'password' => 'synthetic-password',
            'phone' => '0000000000',
            'address' => 'Synthetic address',
            'role' => 'store_owner',
            'profile_image' => '',
            'status' => 'active',
        ]);
    }

    private function productPayload(Category $category, string $collectionDate): array
    {
        return [
            'product_code' => 'SYN-001',
            'product_name' => 'Synthetic Pants',
            'category_id' => $category->id,
            'description' => 'Synthetic product.',
            'collection_date' => $collectionDate,
            'price' => '100.00',
            'quantity' => 2,
            'low_stock_threshold' => 1,
            'image' => UploadedFile::fake()->image('product.jpg'),
        ];
    }
}
