<?php

namespace App\Modules\Analytics\Exports;

use App\Modules\Analytics\Services\ReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The first sheet of the general report: the period it covers, how many
 * records each category holds (with a status breakdown) and the commission
 * totals. Counts are complete even when a category sheet is capped.
 */
class GeneralReportSummarySheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** @param  array<string, mixed>  $filters */
    public function __construct(
        private readonly ReportService $reports,
        private readonly array $filters,
    ) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function array(): array
    {
        $summary = $this->reports->summary($this->filters);
        $timezone = config('app.business_timezone', config('app.timezone'));

        $rows = [
            ['SkillServe General Report'],
            ['Period', $this->period()],
            ['Generated', Carbon::now($timezone)->format('M j, Y g:i A')],
            [],
            ['Category', 'Records', 'Breakdown'],
        ];

        foreach ($summary['categories'] as $category) {
            $breakdown = collect($category['breakdown'])
                ->map(fn (int $count, int|string $state): string => str_replace('_', ' ', (string) $state ?: 'none').': '.$count)
                ->implode(', ');

            if ($category['total'] > ReportService::EXPORT_LIMIT) {
                $breakdown .= ' (sheet shows the latest '.number_format(ReportService::EXPORT_LIMIT).')';
            }

            $rows[] = [ReportService::label($category['type']), $category['total'], $this->reports->exportCell($breakdown)];
        }

        return [
            ...$rows,
            [],
            ['Commission (PHP)', 'Amount'],
            ['Collected (settled)', $summary['commission_totals']['settled']],
            ['Outstanding', $summary['commission_totals']['outstanding']],
            ['Waived', $summary['commission_totals']['waived']],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            5 => ['font' => ['bold' => true]],
        ];
    }

    private function period(): string
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        return match (true) {
            $from && $to => Carbon::parse($from)->format('M j, Y').' to '.Carbon::parse($to)->format('M j, Y'),
            (bool) $from => 'From '.Carbon::parse($from)->format('M j, Y'),
            (bool) $to => 'Up to '.Carbon::parse($to)->format('M j, Y'),
            default => 'All time',
        };
    }
}
