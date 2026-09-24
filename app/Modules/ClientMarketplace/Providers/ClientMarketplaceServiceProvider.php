<?php

namespace App\Modules\ClientMarketplace\Providers;

use App\Modules\Settings\Controllers\PlatformController;
use App\Shared\Middleware\EnsurePlatformAvailable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ClientMarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // Outside maintenance mode's reach: the app polls it to learn when
        // the platform is back.
        Route::middleware('api')
            ->prefix('api/client/v1')
            ->get('/platform', [PlatformController::class, 'show']);

        Route::middleware(['api', EnsurePlatformAvailable::class])
            ->prefix('api/client/v1')
            ->group(function (): void {
                Route::prefix('auth')->group(
                    base_path('app/Modules/ClientAuthentication/Routes/api.php'),
                );

                Route::group([], base_path('app/Modules/ClientMarketplace/Routes/api.php'));
                Route::group([], base_path('app/Modules/ClientCommunication/Routes/api.php'));
                Route::group([], base_path('app/Modules/ClientPreferences/Routes/api.php'));
                Route::group([], base_path('app/Modules/IdentityVerification/Routes/api.php'));
            });
    }
}
