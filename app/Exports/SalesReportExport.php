<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesReportExport implements FromCollection, WithHeadings
{
    /**
     * @param  array<int, array{0: string, 1: int|string, 2: float|int|string}>  $rows
     */
    public function __construct(private array $rows) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function headings(): array
    {
        return ['Period', 'Transactions', 'Total (PHP)'];
    }
}
