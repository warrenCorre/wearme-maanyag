<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
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

        Category::create([
            'category_name' => $validated['category_name'],
            'status' => 'active',
        ]);

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

        $category->update($validated);

        return redirect()->route('categories.index')
            ->with('status', 'Category updated successfully.');
    }

    public function toggleStatus(Category $category): RedirectResponse
    {
        $category->update([
            'status' => $category->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()->route('categories.index')
            ->with('status', 'Category status updated successfully.');
    }
}
