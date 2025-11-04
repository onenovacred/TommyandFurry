<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;   
use Illuminate\Support\Facades\DB;
use DateTime;
use DateTimeZone;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
            $this->registerApiRoutes();

        // Ensure DB session timezone matches the application timezone for timestamp defaults
        $defaultConnection = config('database.default');
        if (in_array($defaultConnection, ['mysql', 'mariadb'])) {
            try {
                $appTz = config('app.timezone', 'Asia/Kolkata');
                $offset = (new DateTime('now', new DateTimeZone($appTz)))->format('P'); // e.g., +05:30
                DB::statement("SET time_zone = '" . $offset . "'");
            } catch (\Throwable $e) {
                // Silently ignore if connection not ready or driver unsupported
            }
        }

        // For SQLite, reduce write contention by enabling WAL and a busy timeout
        if ($defaultConnection === 'sqlite') {
            try {
                DB::statement('PRAGMA journal_mode=WAL');
                DB::statement('PRAGMA synchronous=NORMAL');
                DB::statement('PRAGMA busy_timeout=5000');
                DB::statement('PRAGMA temp_store=MEMORY');
            } catch (\Throwable $e) {
                // Ignore if connection not ready; pragmas are best-effort
            }
        }

    }

    protected function registerApiRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(function () {
                require base_path('routes/api.php');
                
                // Register payment service routes if enabled
                if (config('payment_service.routes.enabled', true)) {
                    require base_path('routes/payment_service.php');
                }
            });
    }
}
