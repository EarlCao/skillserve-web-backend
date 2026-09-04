<?php

namespace App\Modules\ReportsAndModeration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Requests\AddInvestigationNoteRequest;
use App\Modules\ReportsAndModeration\Requests\InvestigateReportRequest;
use App\Modules\ReportsAndModeration\Requests\RejectReportRequest;
use App\Modules\ReportsAndModeration\Requests\ResolveReportRequest;
use App\Modules\ReportsAndModeration\Requests\TakeModerationActionRequest;
use App\Modules\ReportsAndModeration\Resources\ReportResource;
use App\Modules\ReportsAndModeration\Services\ReportService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'Reports and moderation (view, investigate, notes, resolve, reject, take action)')]
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    /**
     * GET /api/reports — paginated, searchable, filterable report list.
     */
    #[OA\Get(
        path: '/api/reports',
        summary: 'List reports',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by report reason, description, or reporter', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', description: 'Filter by reported item type', required: false, schema: new OA\Schema(type: 'string', enum: ['user', 'service', 'review', 'message'])),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by report status', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'investigating', 'resolved', 'rejected'])),
            new OA\Parameter(name: 'reason', in: 'query', description: 'Filter by violation reason', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'updated_at', 'status'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of reports', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        $paginator = $this->reportService->index($request->only([
            'search', 'type', 'status', 'reason', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, ReportResource::class, 'Reports retrieved.');
    }

    /**
     * GET /api/reports/{report} — single report with its reported item.
     */
    #[OA\Get(
        path: '/api/reports/{report}',
        summary: 'Get a report',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report details', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        $report = $this->reportService->show($report);

        return $this->success(new ReportResource($report), 'Report retrieved.');
    }

    /**
     * PATCH /api/reports/{report}/investigate — assign and start the investigation.
     */
    #[OA\Patch(
        path: '/api/reports/{report}/investigate',
        summary: 'Investigate a report',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                example: ['note' => 'Reviewing the reported account now.'],
                properties: [
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, description: 'Optional opening note', example: 'Reviewing the reported account now.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Report investigation started', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Terminal report / validation error'),
        ],
    )]
    public function investigate(InvestigateReportRequest $request, Report $report): JsonResponse
    {
        $this->authorize('investigate', $report);

        $report = $this->reportService->investigate($report, $request->user(), $request->validated('note'));

        return $this->success(new ReportResource($report), 'Report investigation started.');
    }

    /**
     * PATCH /api/reports/{report}/notes — append an investigation note.
     */
    #[OA\Patch(
        path: '/api/reports/{report}/notes',
        summary: 'Add an investigation note',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['note'],
                example: ['note' => 'Evidence reviewed — no violation found.'],
                properties: [
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, example: 'Evidence reviewed — no violation found.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Investigation note added', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Terminal report / validation error'),
        ],
    )]
    public function addNote(AddInvestigationNoteRequest $request, Report $report): JsonResponse
    {
        $this->authorize('addNote', $report);

        $report = $this->reportService->addNote($report, $request->user(), $request->validated('note'));

        return $this->success(new ReportResource($report), 'Investigation note added.');
    }

    /**
     * PATCH /api/reports/{report}/resolve — mark a report as resolved.
     */
    #[OA\Patch(
        path: '/api/reports/{report}/resolve',
        summary: 'Resolve a report',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['resolution_note'],
                example: ['resolution_note' => 'Account suspended and report closed.'],
                properties: [
                    new OA\Property(property: 'resolution_note', type: 'string', maxLength: 2000, example: 'Account suspended and report closed.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Report resolved', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Terminal report / validation error'),
        ],
    )]
    public function resolve(ResolveReportRequest $request, Report $report): JsonResponse
    {
        $this->authorize('resolve', $report);

        $report = $this->reportService->resolve($report, $request->user(), $request->validated('resolution_note'));

        return $this->success(new ReportResource($report), 'Report resolved.');
    }

    /**
     * PATCH /api/reports/{report}/reject — mark a report as rejected (no violation).
     */
    #[OA\Patch(
        path: '/api/reports/{report}/reject',
        summary: 'Reject a report',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: ['reason' => 'No violation was found during review.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, example: 'No violation was found during review.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Report rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Terminal report / validation error'),
        ],
    )]
    public function reject(RejectReportRequest $request, Report $report): JsonResponse
    {
        $this->authorize('reject', $report);

        $report = $this->reportService->reject($report, $request->user(), $request->validated('reason'));

        return $this->success(new ReportResource($report), 'Report rejected.');
    }

    /**
     * PATCH /api/reports/{report}/action — take a moderation action on the reported item.
     */
    #[OA\Patch(
        path: '/api/reports/{report}/action',
        summary: 'Take a moderation action',
        tags: ['Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['action'],
                example: ['action' => 'suspend', 'reason' => 'Repeated policy violations.', 'note' => 'Suspended after investigation.'],
                properties: [
                    new OA\Property(property: 'action', type: 'string', enum: ['warning', 'suspend', 'ban', 'hide', 'remove'], description: 'Applicability depends on the reported item type (user: warning/suspend/ban, service: hide, review: hide/remove, message: remove)', example: 'suspend'),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, description: 'Required for suspend and ban', example: 'Repeated policy violations.', nullable: true),
                    new OA\Property(property: 'duration', type: 'string', enum: ['days', 'forever'], description: 'Required for ban', example: 'forever', nullable: true),
                    new OA\Property(property: 'days', type: 'integer', minimum: 1, maximum: 3650, description: 'Required when ban duration is days', example: 30, nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, description: 'Optional note recorded with the action', example: 'Suspended after investigation.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Moderation action taken', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Terminal report / not applicable / validation error'),
        ],
    )]
    public function takeAction(TakeModerationActionRequest $request, Report $report): JsonResponse
    {
        $this->authorize('action', $report);

        $report = $this->reportService->takeAction(
            $report,
            $request->user(),
            $request->validated(),
        );

        return $this->success(new ReportResource($report), 'Moderation action taken.');
    }
}
