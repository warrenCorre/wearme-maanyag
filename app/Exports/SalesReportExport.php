<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
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

class SalesReportExport implements FromCollection, WithCharts, WithColumnWidths, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    private ?Collection $exportRows = null;

    /** @var array<string, array{first: int, last: int, top: int}> */
    private array $chartSections = [];

    /**
     * @param  array<string, array<string, mixed>>  $reports
     */
    public function __construct(private array $reports, private string $date) {}

    public function collection(): Collection
    {
        if ($this->exportRows !== null) {
            return $this->exportRows;
        }

        $rows = collect([
            ['Reference Date', $this->date],
            ['Periods overlap: do not add the four totals together.'],
            [''],
        ]);

        foreach ($this->reports as $period => $report) {
            $sectionTop = $rows->count() + 2;
            $range = $report['start']->format('M j, Y');
            if ($period !== 'daily') {
                $range .= ' - '.$report['end']->format('M j, Y');
            }

            $section = [
                [strtoupper($period), $range],
                ['Total Sales (PHP)', $report['summary']['total_sales']],
                ['Transactions', $report['summary']['transaction_count']],
                ['Average Sale (PHP)', $report['summary']['average_sale']],
                ['Sales Breakdown'],
                ['Time / Date', 'Transactions', 'Total (PHP)'],
            ];

            foreach ($report['rows'] as $row) {
                $section[] = [$row['label'], $row['transactions'], $row['total']];
            }

            if ($report['rows'] === []) {
                $section[] = ['No sales recorded for this period.'];
            }

            $section[] = ['TOTAL', $report['summary']['transaction_count'], $report['summary']['total_sales']];
            $section[0][4] = 'Graph bucket';
            $section[0][5] = 'Sales (PHP)';

            foreach ($report['chart']['labels'] as $index => $label) {
                $section[$index + 1] ??= [''];
                $section[$index + 1][4] = $label;
                $section[$index + 1][5] = $report['chart']['totals'][$index];
            }

            $this->chartSections[$period] = [
                'first' => $sectionTop + 1,
                'last' => $sectionTop + count($report['chart']['labels']),
                'top' => $sectionTop,
            ];

            for ($index = count($section); $index < 21; $index++) {
                $section[] = [''];
            }

            foreach ($section as $sectionRow) {
                $rows->push(array_replace(array_fill(0, 6, null), $sectionRow));
            }

            $rows->push(['']);
        }

        return $this->exportRows = $rows;
    }

    public function headings(): array
    {
        return ['Wear Me Maanyag - Sales Report'];
    }

    public function columnWidths(): array
    {
        return ['A' => 39, 'B' => 24, 'C' => 22, 'D' => 4, 'E' => 19, 'F' => 19, 'G' => 4];
    }

    public function title(): string
    {
        return 'Sales Report';
    }

    public function charts(): array
    {
        $this->collection();
        $charts = [];

        foreach ($this->chartSections as $period => $section) {
            $first = $section['first'];
            $last = $section['last'];
            $count = $last - $first + 1;
            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                [0],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Sales Report'!\$F\$".$section['top'])],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Sales Report'!\$E\${$first}:\$E\${$last}", null, $count)],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Sales Report'!\$F\${$first}:\$F\${$last}", null, $count)],
                DataSeries::DIRECTION_COL,
            );

            $chart = new Chart($period.'-sales', new Title(ucfirst($period).' Sales (PHP)'), null, new PlotArea(null, [$series]));
            $chart->setTopLeftPosition('H'.$section['top']);
            $chart->setBottomRightPosition('Q'.($section['top'] + 19));
            $charts[] = $chart;
        }

        return $charts;
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
