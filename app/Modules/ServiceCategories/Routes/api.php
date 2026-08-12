<?php

use App\Modules\ServiceCategories\Controllers\ServiceCategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service Category Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the ServiceCategoryPolicy.
|
| Subcategory routes are nested under the parent category so the frontend
| always operates within a category; the service/actions re-verify the
| subcategory belongs to the category in the URL.
|
*/

Route::prefix('service-categories')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [ServiceCategoryController::class, 'index']);
    Route::post('/', [ServiceCategoryController::class, 'store']);
    Route::get('/{serviceCategory}', [ServiceCategoryController::class, 'show']);
    Route::put('/{serviceCategory}', [ServiceCategoryController::class, 'update']);
    Route::patch('/{serviceCategory}', [ServiceCategoryController::class, 'update']);
    Route::patch('/{serviceCategory}/status', [ServiceCategoryController::class, 'updateStatus']);
    Route::delete('/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);

    // Nested subcategory management.
    Route::post('/{serviceCategory}/subcategories', [ServiceCategoryController::class, 'storeSubcategory']);
    Route::put('/{serviceCategory}/subcategories/{serviceSubcategory}', [ServiceCategoryController::class, 'updateSubcategory']);
    Route::patch('/{serviceCategory}/subcategories/{serviceSubcategory}', [ServiceCategoryController::class, 'updateSubcategory']);
    Route::delete('/{serviceCategory}/subcategories/{serviceSubcategory}', [ServiceCategoryController::class, 'destroySubcategory']);
});
