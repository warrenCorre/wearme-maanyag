<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryReportExport implements FromCollection, WithHeadings
{
    public function __construct(private Collection $products) {}

    public function collection(): Collection
    {
        return $this->products->map(fn (Product $product) => [
            $product->product_code,
            $product->product_name,
            $product->category?->name ?? 'Uncategorized',
            (float) $product->price,
            (int) $product->quantity,
            (int) $product->low_stock_threshold,
            $product->quantity <= $product->low_stock_threshold ? 'Low Stock' : 'OK',
        ])->values();
    }

    public function headings(): array
    {
        return ['Code', 'Product', 'Category', 'Price (PHP)', 'Quantity', 'Reorder At', 'Stock Status'];
    }
}
