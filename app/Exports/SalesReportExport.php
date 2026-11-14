<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesReportExport implements FromCollection, WithColumnWidths, WithHeadings, WithStrictNullComparison, WithStyles
{
    /**
     * @param  array<string, array<string, mixed>>  $reports
     */
    public function __construct(private array $reports, private string $date) {}

    public function collection(): Collection
    {
        $rows = collect([
            ['Reference Date', $this->date],
            ['Periods overlap: do not add the four totals together.'],
            [''],
        ]);

        foreach ($this->reports as $period => $report) {
            $range = $report['start']->format('M j, Y');
            if ($period !== 'daily') {
                $range .= ' - '.$report['end']->format('M j, Y');
            }

            $rows->push([strtoupper($period), $range]);
            $rows->push(['Total Sales (PHP)', $report['summary']['total_sales']]);
            $rows->push(['Transactions', $report['summary']['transaction_count']]);
            $rows->push(['Average Sale (PHP)', $report['summary']['average_sale']]);
            $rows->push(['Sales Breakdown']);
            $rows->push(['Time / Date', 'Transactions', 'Total (PHP)']);

            foreach ($report['rows'] as $row) {
                $rows->push([$row['label'], $row['transactions'], $row['total']]);
            }

            if ($report['rows'] === []) {
                $rows->push(['No sales recorded for this period.']);
            }

            $rows->push(['TOTAL', $report['summary']['transaction_count'], $report['summary']['total_sales']]);
            $rows->push(['']);
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Wear Me Maanyag - Sales Report'];
    }

    public function columnWidths(): array
    {
        return ['A' => 39, 'B' => 24, 'C' => 22];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'E11D48']]],
        ];

        foreach ($sheet->getRowIterator(2) as $row) {
            $rowNumber = $row->getRowIndex();
            $label = $sheet->getCell('A'.$rowNumber)->getValue();

            if (in_array($label, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY', 'Sales Breakdown', 'Time / Date', 'TOTAL'], true)) {
                $styles[$rowNumber] = ['font' => ['bold' => true, 'color' => ['rgb' => '0F172A']], 'fill' => [
                    'fillType' => 'solid', 'startColor' => ['rgb' => 'FFE4E6'],
                ]];
            }
        }

        return $styles;
    }
}
