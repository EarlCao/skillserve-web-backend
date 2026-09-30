<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Exports\GeneralReportExport;
use App\Modules\Analytics\Requests\GeneralReportRequest;
use App\Modules\Analytics\Requests\ReportRequest;
use App\Modules\Analytics\Services\ReportService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Reports and Analytics', description: 'Generate and export user, provider, service, booking, review, and system activity reports')]
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $reportService) {}

    #[OA\Get(
        path: '/api/analytics/reports',
        summary: 'Generate a report',
        tags: ['Reports and Analytics'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ReportRequest::TYPES)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'id'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report rows', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function index(ReportRequest $request): JsonResponse
    {
        $this->authorize('view analytics');

        return $this->paginated(
            $this->reportService->generate($request->string('type'), $request->validated()),
            null,
            'Report generated.',
        );
    }

    #[OA\Get(
        path: '/api/analytics/reports/export',
        summary: 'Export a report to CSV',
        tags: ['Reports and Analytics'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ReportRequest::TYPES)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'id'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function export(ReportRequest $request): StreamedResponse
    {
        $this->authorize('export analytics');

        $type = $request->string('type');
        $columns = $this->reportService->columns($type);
        $rows = $this->reportService->rows($type, $request->validated());
        $keys = array_keys($columns);
        $filename = $type.'-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns, $keys, $rows): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, array_values($columns));

            foreach ($rows as $row) {
                fputcsv($output, array_map(
                    fn ($key) => $this->reportService->exportCell($row[$key] ?? null),
                    $keys,
                ));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    #[OA\Get(
        path: '/api/analytics/reports/general/export',
        summary: 'Export a general report of every category to Excel',
        description: 'One .xlsx workbook: a Summary sheet (record counts per category with a status breakdown, and commission totals in pesos), then one sheet per report category (Users, Providers, Services, Bookings, Reviews, Activity, Commissions). The optional date range filters every category by creation date. Each category sheet is capped at 5,000 rows; the Summary sheet always shows the full counts.',
        tags: ['Reports and Analytics'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excel workbook download', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the export analytics permission'),
            new OA\Response(response: 422, description: 'Invalid date range'),
        ],
    )]
    public function generalExport(GeneralReportRequest $request): BinaryFileResponse
    {
        $this->authorize('export analytics');

        return Excel::download(
            new GeneralReportExport($this->reportService, $request->validated()),
            'general-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
