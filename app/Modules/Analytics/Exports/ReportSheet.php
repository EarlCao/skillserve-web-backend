<?php

namespace App\Modules\Analytics\Exports;

use App\Modules\Analytics\Services\ReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One report category as a sheet: the same columns and rows as that
 * category's CSV export, capped at the same 5,000 rows.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param  array<string, mixed>  $filters */
    public function __construct(
        private readonly ReportService $reports,
        private readonly string $type,
        private readonly array $filters,
    ) {}

    public function title(): string
    {
        return ReportService::label($this->type);
    }

    public function headings(): array
    {
        return array_values($this->reports->columns($this->type));
    }

    public function array(): array
    {
        $keys = array_keys($this->reports->columns($this->type));

        return array_map(
            fn (array $row): array => array_map(fn (string $key): string => $this->reports->exportCell($row[$key] ?? null), $keys),
            $this->reports->rows($this->type, $this->filters),
        );
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
