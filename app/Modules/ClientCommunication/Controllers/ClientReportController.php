<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Policies\ClientReportPolicy;
use App\Modules\ClientCommunication\Requests\ClientReportIndexRequest;
use App\Modules\ClientCommunication\Requests\StoreClientReportRequest;
use App\Modules\ClientCommunication\Resources\ClientReportResource;
use App\Modules\ClientCommunication\Services\ClientReportService;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Models\ReportAttachment;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Client Reports', description: 'Complaints filed from the mobile app')]
class ClientReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientReportService $reportService,
        private readonly ClientReportPolicy $reportPolicy,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/reports',
        summary: 'List the reports the signed-in account has filed',
        tags: ['Client Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'investigating', 'resolved', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reports filed by the account', content: new OA\JsonContent(ref: '#/components/schemas/ClientReportListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active customer or provider account required'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ],
    )]
    public function index(ClientReportIndexRequest $request): JsonResponse
    {
        $this->ensure($this->reportPolicy->viewAny($request->user()));

        return $this->paginated(
            $this->reportService->index($request->user(), $request->validated()),
            ClientReportResource::class,
            'Reports retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/reports',
        summary: 'Report a person, a review or a message',
        description: 'Send JSON, or multipart/form-data to attach up to 5 photos as proof in `images[]` (JPG, PNG or WebP, 5 MB each); moderators see them with the report. Send exactly one subject. `booking_id` reports the other party on a booking the caller took part in; `review_id` reports a published review (not the caller\'s own); `message_id` reports a message the caller received; `service_id` reports a service listed in the marketplace (not the caller\'s own); `provider_id` reports a provider listed in the marketplace, from their public profile (not the caller themself) — the report is about the provider\'s account. Moderators review every report from the admin console. One open report per subject per reporter.',
        tags: ['Client Reports'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\JsonContent(
                required: ['reason', 'description'],
                properties: [
                    new OA\Property(property: 'booking_id', type: 'integer', nullable: true, description: 'Report the other party on this booking'),
                    new OA\Property(property: 'review_id', type: 'integer', nullable: true, description: 'Report this published review'),
                    new OA\Property(property: 'message_id', type: 'integer', nullable: true, description: 'Report this message the caller received'),
                    new OA\Property(property: 'service_id', type: 'integer', nullable: true, description: 'Report this service listing'),
                    new OA\Property(property: 'provider_id', type: 'integer', nullable: true, description: 'Report this provider (a provider profile id from the public catalog)'),
                    new OA\Property(property: 'reason', type: 'string', enum: Report::REASONS),
                    new OA\Property(property: 'description', type: 'string', minLength: 10, maxLength: 2000),
                ],
            ),
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
                required: ['reason', 'description'],
                properties: [
                    new OA\Property(property: 'booking_id', type: 'integer'),
                    new OA\Property(property: 'review_id', type: 'integer'),
                    new OA\Property(property: 'message_id', type: 'integer'),
                    new OA\Property(property: 'service_id', type: 'integer'),
                    new OA\Property(property: 'provider_id', type: 'integer'),
                    new OA\Property(property: 'reason', type: 'string', enum: Report::REASONS),
                    new OA\Property(property: 'description', type: 'string', minLength: 10, maxLength: 2000),
                    new OA\Property(property: 'images[]', type: 'array', maxItems: 5, items: new OA\Items(type: 'string', format: 'binary')),
                ],
            )),
        ]),
        responses: [
            new OA\Response(response: 201, description: 'Report filed', content: new OA\JsonContent(ref: '#/components/schemas/ClientReportEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active customer or provider account required'),
            new OA\Response(response: 404, description: 'Subject not found, or not one the caller may report'),
            new OA\Response(response: 409, description: 'An earlier report about this subject is still open'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreClientReportRequest $request): JsonResponse
    {
        $this->ensure($this->reportPolicy->create($request->user()));

        $report = $this->reportService->create($request->user(), $request->validated(), $request->file('images', []));

        return $this->success(
            // Re-read so the subject loads with the same narrow shape the list uses.
            new ClientReportResource($this->reportService->show($request->user(), $report)),
            'Report filed. Our support team will review it.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/reports/{report}',
        summary: 'Get a report the signed-in account filed',
        tags: ['Client Reports'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Report details', content: new OA\JsonContent(ref: '#/components/schemas/ClientReportEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not filed by the caller'),
            new OA\Response(response: 404, description: 'Report not found'),
        ],
    )]
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->ensure($this->reportPolicy->view($request->user(), $report));

        return $this->success(
            new ClientReportResource($this->reportService->show($request->user(), $report)),
            'Report retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/reports/{report}/attachments/{attachment}',
        summary: 'A photo the caller attached to their report',
        description: 'Streamed from private storage to the reporter only. The attachment ids and URLs come from the report\'s `attachments`.',
        tags: ['Client Reports'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The photo', content: new OA\MediaType(mediaType: 'image/*')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not the caller\'s report, or no such photo'),
        ],
    )]
    public function attachment(Request $request, Report $report, ReportAttachment $attachment): StreamedResponse
    {
        return $this->reportService->attachment($request->user(), $report, $attachment);
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this report.');
        }
    }
}
