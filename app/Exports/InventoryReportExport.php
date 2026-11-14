<?php

namespace App\Exports;

use Generator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryReportExport implements FromGenerator, WithColumnWidths, WithHeadings, WithStrictNullComparison, WithStyles
{
    /**
     * @param  array{product_count?: int, total_units?: int, stock_value?: float}  $stats
     */
    public function __construct(private Collection $products, private iterable $adjustments = [], private array $stats = []) {}

    public function generator(): Generator
    {
        $lowStockProducts = $this->products->filter(fn ($product): bool => $product->quantity <= $product->low_stock_threshold);

        yield ['Generated', now()->format('Y-m-d H:i')];
        yield ['Inventory Overview'];
        yield ['Active Products', $this->stats['product_count'] ?? $this->products->count()];
        yield ['Units in Stock', $this->stats['total_units'] ?? $this->products->sum('quantity')];
        yield ['Stock Value (PHP)', $this->stats['stock_value'] ?? $this->products->sum(fn ($product): float => $product->quantity * (float) $product->price)];
        yield ['Low Stock Products', $lowStockProducts->count()];
        yield [''];
        yield ['Low-Stock Details'];
        yield ['Code', 'Product', 'Category', 'Collection Date', 'Quantity', 'Reorder At'];

        foreach ($lowStockProducts as $product) {
            yield [
                $this->safeText($product->product_code),
                $this->safeText($product->product_name),
                $this->safeText($product->category?->category_name ?? 'Uncategorized'),
                $product->collection_date?->toDateString() ?? 'Not recorded',
                (int) $product->quantity,
                (int) $product->low_stock_threshold,
            ];
        }

        if ($lowStockProducts->isEmpty()) {
            yield ['No low-stock products.'];
        }

        yield [''];
        yield ['All Active Products'];
        yield ['Code', 'Product', 'Category', 'Collection Date', 'Price (PHP)', 'Quantity', 'Reorder At', 'Stock Status'];

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

        if ($this->products->isEmpty()) {
            yield ['No active products yet.'];
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
        return ['Wear Me Maanyag - Inventory Report'];
    }

    public function columnWidths(): array
    {
        return ['A' => 28, 'B' => 34, 'C' => 24, 'D' => 20, 'E' => 17, 'F' => 17, 'G' => 46, 'H' => 25];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'E11D48']]],
        ];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $label = $sheet->getCell('A'.$row)->getValue();

            if (in_array($label, ['Inventory Overview', 'Low-Stock Details', 'All Active Products', 'Stock Adjustment History'], true)) {
                $styles[$row] = ['font' => ['bold' => true, 'color' => ['rgb' => '0F172A']], 'fill' => [
                    'fillType' => 'solid', 'startColor' => ['rgb' => 'FFE4E6'],
                ]];
            }

            if ($label === 'Stock Adjustment History') {
                $styles[$row + 1] = ['font' => ['bold' => true]];

                break;
            }

            if ($label === 'Code') {
                $styles[$row] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
