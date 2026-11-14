<?php

namespace App\Exports;

use Generator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryReportExport implements FromGenerator, WithHeadings
{
    public function __construct(private Collection $products, private iterable $adjustments = []) {}

    public function generator(): Generator
    {
        foreach ($this->products as $product) {
            yield [
                $this->safeText($product->product_code),
                $this->safeText($product->product_name),
                $this->safeText($product->category?->category_name ?? 'Uncategorized'),
                $product->collection_date?->toDateString() ?? 'Not recorded',
                (float) $product->price,
                (int) $product->quantity,
                (int) $product->low_stock_threshold,
                $product->quantity <= $product->low_stock_threshold ? 'Low Stock' : 'OK',
            ];
        }

        yield [''];
        yield ['Stock Adjustment History'];
        yield ['Recorded', 'Product', 'Direction', 'Quantity', 'Before', 'After', 'Reason', 'Owner'];

        $hasAdjustments = false;
        foreach ($this->adjustments as $adjustment) {
            $hasAdjustments = true;
            yield [
                $adjustment->created_at->format('Y-m-d H:i:s'),
                $this->safeText($adjustment->product?->product_name ?? 'Unknown product'),
                ucfirst($adjustment->direction),
                (int) $adjustment->quantity,
                (int) $adjustment->quantity_before,
                (int) $adjustment->quantity_after,
                $this->safeText($adjustment->reason),
                $this->safeText($adjustment->adjustedBy?->name ?? 'Unknown owner'),
            ];
        }

        if (! $hasAdjustments) {
            yield ['No non-sales stock adjustments recorded yet.'];
        }
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
