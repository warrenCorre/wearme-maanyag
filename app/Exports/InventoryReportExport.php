<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryReportExport implements FromCollection, WithHeadings
{
    public function __construct(private Collection $products, private ?Collection $adjustments = null) {}

    public function collection(): Collection
    {
        $rows = $this->products->map(fn (Product $product) => [
            $this->safeText($product->product_code),
            $this->safeText($product->product_name),
            $this->safeText($product->category?->category_name ?? 'Uncategorized'),
            $product->collection_date?->toDateString() ?? 'Not recorded',
            (float) $product->price,
            (int) $product->quantity,
            (int) $product->low_stock_threshold,
            $product->quantity <= $product->low_stock_threshold ? 'Low Stock' : 'OK',
        ])->values();

        $rows->push(['']);
        $rows->push(['Stock Adjustment History']);
        $rows->push(['Recorded', 'Product', 'Direction', 'Quantity', 'Before', 'After', 'Reason', 'Owner']);

        if ($this->adjustments === null || $this->adjustments->isEmpty()) {
            $rows->push(['No non-sales stock adjustments recorded yet.']);

            return $rows;
        }

        foreach ($this->adjustments as $adjustment) {
            $rows->push([
                $adjustment->created_at->format('Y-m-d H:i:s'),
                $this->safeText($adjustment->product?->product_name ?? 'Unknown product'),
                ucfirst($adjustment->direction),
                (int) $adjustment->quantity,
                (int) $adjustment->quantity_before,
                (int) $adjustment->quantity_after,
                $this->safeText($adjustment->reason),
                $this->safeText($adjustment->adjustedBy?->name ?? 'Unknown owner'),
            ]);
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Code', 'Product', 'Category', 'Collection Date', 'Price (PHP)', 'Quantity', 'Reorder At', 'Stock Status'];
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
