<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\StoreActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private StoreActivityRecorder $activities) {}

    public function index(): View
    {
        $categories = Category::withCount('products')
            ->orderBy('category_name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_name' => ['required', 'string', 'max:100', 'unique:tbl_categories,category_name'],
        ]);

        DB::transaction(function () use ($validated): void {
            $category = Category::create([
                'category_name' => $validated['category_name'],
                'status' => 'active',
            ]);
            $this->activities->record('category_created', 'category', $category->id, (int) auth()->id());
        });

        return redirect()->route('categories.index')
            ->with('status', 'Category created successfully.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'category_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tbl_categories', 'category_name')->ignore($category->id),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($category, $validated): void {
            $category->update($validated);
            if ($category->wasChanged(['category_name', 'status'])) {
                $this->activities->record('category_updated', 'category', $category->id, (int) auth()->id());
            }
        });

        return redirect()->route('categories.index')
            ->with('status', 'Category updated successfully.');
    }

    public function toggleStatus(Category $category): RedirectResponse
    {
        DB::transaction(function () use ($category): void {
            $category->update([
                'status' => $category->status === 'active' ? 'inactive' : 'active',
            ]);
            $this->activities->record('category_status_changed', 'category', $category->id, (int) auth()->id());
        });

        return redirect()->route('categories.index')
            ->with('status', 'Category status updated successfully.');
    }
}
