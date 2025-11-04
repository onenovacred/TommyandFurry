<?php

namespace App\PaymentService\Providers;

use Illuminate\Support\ServiceProvider;
use App\PaymentService\Services\RazorpayService;
use App\PaymentService\Interfaces\PaymentServiceInterface;
use Illuminate\Support\Facades\Route;

/**
 * Payment Service Provider
 * 
 * Registers the payment service in the Laravel service container.
 * This allows the payment service to be injected anywhere in the application.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind the PaymentServiceInterface to RazorpayService implementation
        $this->app->singleton(PaymentServiceInterface::class, function ($app) {
            return new RazorpayService();
        });

        // Also bind RazorpayService directly for convenience
        $this->app->singleton(RazorpayService::class, function ($app) {
            return new RazorpayService();
        });

        // Register payment service configuration
        $configPath = file_exists(config_path('payment_service.php'))
            ? config_path('payment_service.php')
            : __DIR__ . '/../config/payment_service.php';
        
        $this->mergeConfigFrom($configPath, 'payment_service');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../config/payment_service.php' => config_path('payment_service.php'),
        ], 'payment-service-config');

        // Register payment service routes if enabled
        if (config('payment_service.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    /**
     * Register payment service routes
     */
    protected function registerRoutes(): void
    {
        $prefix = config('payment_service.routes.prefix', 'payment-service');
        $middleware = config('payment_service.routes.middleware', ['api']);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->namespace('App\PaymentService\Http\Controllers')
            ->group(function () {
                // Order endpoints
                Route::post('/orders', 'PaymentServiceController@createOrder');
                Route::get('/orders/{orderId}', 'PaymentServiceController@fetchOrder');
                
                // Payment endpoints
                Route::post('/verify', 'PaymentServiceController@verifyPayment');
                Route::get('/payments/{paymentId}', 'PaymentServiceController@fetchPayment');
                
                // Payment Link endpoints
                Route::post('/payment-links', 'PaymentServiceController@createPaymentLink');
                Route::get('/payment-links/{linkId}', 'PaymentServiceController@fetchPaymentLink');
                Route::put('/payment-links/{linkId}', 'PaymentServiceController@updatePaymentLink');
                Route::delete('/payment-links/{linkId}', 'PaymentServiceController@cancelPaymentLink');
                Route::post('/payment-links/{linkId}/send-sms', 'PaymentServiceController@sendPaymentLinkSMS');
                Route::post('/payment-links/{linkId}/send-email', 'PaymentServiceController@sendPaymentLinkEmail');
                
                // Health check
                Route::get('/health', 'PaymentServiceController@health');
            });
    }
}

