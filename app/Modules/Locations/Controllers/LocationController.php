<?php

namespace App\Modules\Locations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Locations\Models\PhLocation;
use App\Modules\Locations\Requests\MatchLocationRequest;
use App\Modules\Locations\Resources\PhLocationResource;
use App\Modules\Locations\Services\PhLocationService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The Philippine address picker: Region → Province → City/Municipality →
 * Barangay, from the PSA's PSGC. Public, because sign-up needs it before an
 * account exists.
 */
#[OA\Tag(name: 'Locations', description: 'Philippine regions, provinces, cities/municipalities and barangays (PSA PSGC) for address pickers')]
class LocationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PhLocationService $locations) {}

    #[OA\Get(
        path: '/api/client/v1/locations/regions',
        summary: 'List the regions',
        description: 'The first level of the address picker, in PSGC order. No authentication.',
        tags: ['Locations'],
        responses: [
            new OA\Response(response: 200, description: 'Regions', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: ['success' => true, 'message' => 'Regions retrieved.', 'data' => [['code' => '130000000', 'name' => 'National Capital Region (NCR)', 'level' => 'region', 'parent_code' => null]], 'errors' => null, 'meta' => []],
            )),
        ],
    )]
    public function regions(): JsonResponse
    {
        return $this->success(PhLocationResource::collection($this->locations->regions()), 'Regions retrieved.');
    }

    #[OA\Get(
        path: '/api/client/v1/locations/{code}/children',
        summary: 'List the places one level below a place',
        description: "A region's children are its provinces plus any city without a province (all of NCR), so check each item's `level`: `province` leads to its cities/municipalities, `city`/`municipality` leads to barangays. A barangay has no children. Sorted by name. No authentication.",
        tags: ['Locations'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, description: '9-digit PSGC code', schema: new OA\Schema(type: 'string', pattern: '^[0-9]{9}$', example: '137404000'))],
        responses: [
            new OA\Response(response: 200, description: 'The places below', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: ['success' => true, 'message' => 'Locations retrieved.', 'data' => [['code' => '137404009', 'name' => 'Bagong Pag-asa', 'level' => 'barangay', 'parent_code' => '137404000']], 'errors' => null, 'meta' => []],
            )),
            new OA\Response(response: 404, description: 'Unknown code'),
        ],
    )]
    public function children(PhLocation $location): JsonResponse
    {
        return $this->success(PhLocationResource::collection($this->locations->children($location)), 'Locations retrieved.');
    }

    #[OA\Get(
        path: '/api/client/v1/locations/match',
        summary: 'Turn a free-text address into picker selections',
        description: 'Used after scanning a National ID: the printed address (e.g. `123 RIZAL ST, BRGY BAGONG PAG-ASA, QUEZON CITY, METRO MANILA`) is matched to its region, province, city/municipality and barangay, and the part before them is returned as `street`. Tolerates ID spellings (`STA.`, `BRGY`, `X CITY` / `CITY OF X`) and a misread character. Anything it cannot decide is `null` rather than guessed — e.g. a municipality name shared by several provinces when no province is given — and the user confirms in the picker. `province` is null for NCR cities. No authentication.',
        tags: ['Locations'],
        parameters: [new OA\Parameter(name: 'address', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 300))],
        responses: [
            new OA\Response(response: 200, description: 'Best match', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: ['success' => true, 'message' => 'Address matched.', 'data' => [
                    'region' => ['code' => '130000000', 'name' => 'National Capital Region (NCR)', 'level' => 'region', 'parent_code' => null],
                    'province' => null,
                    'city' => ['code' => '137404000', 'name' => 'Quezon City', 'level' => 'city', 'parent_code' => '130000000'],
                    'barangay' => ['code' => '137404009', 'name' => 'Bagong Pag-asa', 'level' => 'barangay', 'parent_code' => '137404000'],
                    'street' => '123 RIZAL ST',
                ], 'errors' => null, 'meta' => []],
            )),
            new OA\Response(response: 422, description: 'Missing or too long address'),
        ],
    )]
    public function match(MatchLocationRequest $request): JsonResponse
    {
        $match = $this->locations->match($request->string('address')->toString());
        $resource = fn (?PhLocation $location): ?PhLocationResource => $location ? new PhLocationResource($location) : null;

        return $this->success([
            'region' => $resource($match['region']),
            'province' => $resource($match['province']),
            'city' => $resource($match['locality']),
            'barangay' => $resource($match['barangay']),
            'street' => $match['street'],
        ], 'Address matched.');
    }
}
