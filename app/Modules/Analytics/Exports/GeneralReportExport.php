<?php

namespace App\Modules\Analytics\Exports;

use App\Modules\Analytics\Requests\ReportRequest;
use App\Modules\Analytics\Services\ReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The general report: a Summary sheet, then one sheet per report category,
 * all over the same date range. Categories come from ReportRequest::TYPES, so
 * a new report type appears here without further changes.
 */
class GeneralReportExport implements WithMultipleSheets
{
    /** @param  array<string, mixed>  $filters */
    public function __construct(
        private readonly ReportService $reports,
        private readonly array $filters,
    ) {}

    public function sheets(): array
    {
        return [
            new GeneralReportSummarySheet($this->reports, $this->filters),
            ...array_map(
                fn (string $type): ReportSheet => new ReportSheet($this->reports, $type, $this->filters),
                ReportRequest::TYPES,
            ),
        ];
    }
}
