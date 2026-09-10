<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        $products = Product::with('category')
            ->where('status', 'active')
            ->orderBy('product_name')
            ->get();

        $lowStockCount = $products
            ->filter(fn (Product $product) => $product->quantity <= $product->low_stock_threshold)
            ->count();

        return view('products.index', compact('products', 'lowStockCount'));
    }

    public function archiveIndex(): View
    {
        $products = Product::onlyTrashed()
            ->with('category')
            ->orderByDesc('deleted_at')
            ->get();

        return view('products.archive', compact('products'));
    }

    public function create(): View
    {
        return view('products.form', [
            'product' => new Product(),
            'categories' => $this->activeCategories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $validated['image'] = $request->file('image')->store('products', 'public');
        $validated['status'] = 'active';
        $validated['created_by'] = Auth::id();
        $validated['updated_by'] = Auth::id();

        Product::create($validated);

        return redirect()->route('products.index')
            ->with('status', 'Product created successfully.');
    }

    public function edit(Product $product): View
    {
        return view('products.form', [
            'product' => $product,
            'categories' => $this->activeCategories(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validateProduct($request, $product);
        $oldImage = $product->image;

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($validated['image']);
        }

        $validated['updated_by'] = Auth::id();
        $product->update($validated);

        if (isset($validated['image']) && $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

        return redirect()->route('products.index')
            ->with('status', 'Product updated successfully.');
    }

    public function archive(Product $product): RedirectResponse
    {
        if ($product->status !== 'active') {
            return back()->withErrors([
                'product' => 'Only active products can be archived.',
            ]);
        }

        $product->update([
            'status' => 'archived',
            'updated_by' => Auth::id(),
        ]);
        $product->delete();

        return redirect()->route('products.index')
            ->with('status', 'Product moved to the Archive.');
    }

    public function restore(int $productId): RedirectResponse
    {
        $product = Product::withTrashed()->findOrFail($productId);

        if (!$product->trashed()) {
            return back()->withErrors([
                'product' => 'Only archived products can be restored.',
            ]);
        }

        $product->restore();
        $product->update([
            'status' => 'active',
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('products.archive.index')
            ->with('status', 'Product restored successfully.');
    }

    public function permanentDelete(int $productId): RedirectResponse
    {
        $product = Product::withTrashed()->findOrFail($productId);

        if (!$product->trashed()) {
            return back()->withErrors([
                'product' => 'Only archived products can be permanently removed.',
            ]);
        }

        if ($product->salesTransactionItems()->exists() || $product->unpaidPayments()->exists()) {
            return back()->withErrors([
                'product' => 'This product cannot be permanently removed because it is referenced by sales or unpaid-payment records.',
            ]);
        }

        $image = $product->image;
        $product->forceDelete();

        if ($image) {
            Storage::disk('public')->delete($image);
        }

        return redirect()->route('products.archive.index')
            ->with('status', 'Product permanently removed.');
    }

    private function activeCategories()
    {
        return Category::where('status', 'active')
            ->orderBy('category_name')
            ->get();
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $imageRule = $product?->exists
            ? ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']
            : ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

        return $request->validate([
            'product_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tbl_products', 'product_code')->ignore($product?->id),
            ],
            'product_name' => ['required', 'string', 'max:150'],
            'category_id' => [
                'required',
                Rule::exists('tbl_categories', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'image' => $imageRule,
        ]);
    }
}
