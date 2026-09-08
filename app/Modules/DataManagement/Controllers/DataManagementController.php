<?php

namespace App\Modules\DataManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\ReportService;
use App\Modules\DataManagement\Models\DataArchive;
use App\Modules\DataManagement\Requests\ArchiveDataRequest;
use App\Modules\DataManagement\Requests\ExportDataRequest;
use App\Modules\DataManagement\Requests\IndexDataRequest;
use App\Modules\DataManagement\Services\DataManagementService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Data Management', description: 'Export, archive, restore, and manage removed system records')]
class DataManagementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DataManagementService $dataService,
        private readonly ReportService $reportService,
    ) {}

    #[OA\Get(path: '/api/data-management/archives', summary: 'List archived records', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Archived records')])]
    public function archives(IndexDataRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage data') || $request->user()->can('restore archived records'), 403);

        return $this->paginated($this->dataService->archives($request->only(['resource_type', 'per_page', 'page'])), null, 'Archived records retrieved.');
    }

    #[OA\Post(path: '/api/data-management/archives', summary: 'Archive a record', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Record archived'), new OA\Response(response: 422, description: 'Validation error')])]
    public function archive(ArchiveDataRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('archive records'), 403);

        return $this->success($this->dataService->archive($request->validated(), $request->user()), 'Record archived.', status: 201);
    }

    #[OA\Post(path: '/api/data-management/archives/{archive}/restore', summary: 'Restore an archived record', tags: ['Data Management'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'archive', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Record restored'), new OA\Response(response: 404, description: 'Archive not found')])]
    public function restoreArchive(DataArchive $archive, Request $request): JsonResponse
    {
        abort_unless($request->user()->can('restore archived records'), 403);
        $this->dataService->restoreArchive($archive, $request->user());

        return $this->success(null, 'Archived record restored.');
    }

    #[OA\Get(path: '/api/data-management/deleted', summary: 'List deleted records', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Deleted records')])]
    public function deleted(IndexDataRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage deleted records'), 403);

        return $this->paginated($this->dataService->deleted($request->only(['resource_type', 'per_page', 'page'])), null, 'Deleted records retrieved.');
    }

    #[OA\Post(path: '/api/data-management/deleted/{type}/{id}/restore', summary: 'Restore a deleted record', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Record restored'), new OA\Response(response: 404, description: 'Record not found')])]
    public function restoreDeleted(string $type, int $id, Request $request): JsonResponse
    {
        abort_unless($request->user()->can('restore deleted records'), 403);
        $this->dataService->restoreDeleted($type, $id, $request->user());

        return $this->success(null, 'Deleted record restored.');
    }

    #[OA\Delete(path: '/api/data-management/deleted/{type}/{id}', summary: 'Permanently delete a record', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 204, description: 'Record permanently deleted'), new OA\Response(response: 422, description: 'Record type cannot be permanently deleted')])]
    public function permanentlyDelete(string $type, int $id, Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage deleted records'), 403);
        $this->dataService->permanentlyDelete($type, $id, $request->user());

        return $this->noContent();
    }

    #[OA\Get(path: '/api/data-management/export', summary: 'Export system data as CSV', tags: ['Data Management'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'CSV export')])]
    public function export(ExportDataRequest $request): StreamedResponse
    {
        abort_unless($request->user()->can('export system data'), 403);
        $type = $request->string('type')->toString();
        $columns = $this->reportService->columns($type);
        $rows = $this->reportService->rows($type, $request->validated());
        $keys = array_keys($columns);

        return response()->streamDownload(function () use ($columns, $keys, $rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, array_values($columns));
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($key) => $this->csvCell($row[$key] ?? null), $keys));
            }
            fclose($output);
        }, "{$type}-export-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function csvCell(mixed $value): string
    {
        $cell = $value === null ? '' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);

        return $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'{$cell}" : $cell;
    }
}
