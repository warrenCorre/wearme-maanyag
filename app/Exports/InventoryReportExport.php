<?php

namespace App\Exports;

use App\Services\ReportChartImage;
use Generator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryReportExport implements FromGenerator, WithCharts, WithColumnWidths, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  array{product_count?: int, total_units?: int, stock_value?: float}  $stats
     */
    public function __construct(private Collection $products, private iterable $adjustments = [], private array $stats = []) {}

    public function generator(): Generator
    {
        $chartProducts = ReportChartImage::topStockProducts($this->products);
        yield array_replace(array_fill(0, 11, null), [0 => 'Top 10 Active Products by Units in Stock', 9 => 'Product', 10 => 'Units']);

        foreach ($chartProducts as $product) {
            yield array_replace(array_fill(0, 11, null), [
                9 => $this->safeText($product->product_code.' - '.$product->product_name),
                10 => (int) $product->quantity,
            ]);
        }

        if ($chartProducts->isEmpty()) {
            yield array_replace(array_fill(0, 11, null), [9 => 'No active products', 10 => 0]);
        }

        for ($row = max(1, $chartProducts->count()); $row < 10; $row++) {
            yield [''];
        }

        for ($row = 0; $row < 8; $row++) {
            yield [''];
        }

        yield ['Graph shows up to 10 active products; full inventory follows in the tables below.'];
        yield [''];

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
        return ['A' => 28, 'B' => 34, 'C' => 24, 'D' => 20, 'E' => 17, 'F' => 17, 'G' => 46, 'H' => 25, 'I' => 4, 'J' => 36, 'K' => 14];
    }

    public function title(): string
    {
        return 'Inventory Report';
    }

    public function charts(): array
    {
        $count = max(1, ReportChartImage::topStockProducts($this->products)->count());
        $last = 2 + $count;
        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Inventory Report'!\$K\$2")],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Inventory Report'!\$J\$3:\$J\${$last}", null, $count)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Inventory Report'!\$K\$3:\$K\${$last}", null, $count)],
            DataSeries::DIRECTION_BAR,
        );

        $chart = new Chart('top-stock', new Title('Top 10 Active Products by Units in Stock'), null, new PlotArea(null, [$series]));
        $chart->setTopLeftPosition('A3');
        $chart->setBottomRightPosition('H20');

        return [$chart];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'E11D48']]],
            2 => ['font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '0F172A']]],
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
