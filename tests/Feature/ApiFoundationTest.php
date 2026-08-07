<?php

namespace Tests\Feature;

use App\Shared\Exceptions\ApiException;
use App\Shared\Services\ApiResponder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    /**
     * Register throwaway routes so the shared infrastructure can be tested
     * without adding feature routes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_foundation/success', fn () => ApiResponder::success(['name' => 'test']));
        Route::middleware('api')->get('/api/_foundation/error', fn () => throw new ApiException('Custom failure', 422, errors: ['name' => ['The name field is required.']]));
        Route::middleware('api')->get('/api/_foundation/validation', fn () => throw ValidationException::withMessages(['email' => ['The email field is required.']]));
        Route::middleware('api')->get('/api/_foundation/auth', fn () => throw new AuthenticationException());
        Route::middleware('api')->get('/api/_foundation/forbidden', fn () => throw new AuthorizationException());
        Route::middleware('api')->get('/api/_foundation/not-found', fn () => throw new ModelNotFoundException());
    }

    public function test_success_uses_standard_envelope(): void
    {
        $this->getJson('/api/_foundation/success')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Request successful.',
                'data' => ['name' => 'test'],
                'errors' => null,
            ])
            ->assertJsonStructure(['success', 'message', 'data', 'errors', 'meta']);
    }

    public function test_api_exception_returns_standard_envelope(): void
    {
        $this->getJson('/api/_foundation/error')
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Custom failure',
                'data' => [],
                'errors' => ['name' => ['The name field is required.']],
            ]);
    }

    public function test_validation_exception_returns_422_envelope(): void
    {
        $this->getJson('/api/_foundation/validation')
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'errors' => ['email' => ['The email field is required.']],
            ]);
    }

    public function test_authentication_exception_returns_401_envelope(): void
    {
        $this->getJson('/api/_foundation/auth')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_authorization_exception_returns_403_envelope(): void
    {
        $this->getJson('/api/_foundation/forbidden')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_model_not_found_returns_404_envelope(): void
    {
        $this->getJson('/api/_foundation/not-found')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found.']);
    }
}
