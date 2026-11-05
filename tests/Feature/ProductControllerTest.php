<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
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

        $response = $this->actingAs($owner)->put(route('products.update', $product), $payload);

        $response->assertRedirect(route('products.index'));
        $this->assertSame('2026-10-15', $product->fresh()->collection_date->toDateString());
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
