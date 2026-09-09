<?php

namespace App\Modules\ClientMarketplace\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ClientMarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/client/v1')
            ->group(function (): void {
                Route::prefix('auth')->group(
                    base_path('app/Modules/ClientAuthentication/Routes/api.php'),
                );

                Route::group([], base_path('app/Modules/ClientMarketplace/Routes/api.php'));
                Route::group([], base_path('app/Modules/ClientCommunication/Routes/api.php'));
            });
    }
}
