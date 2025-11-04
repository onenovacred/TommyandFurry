<?php

namespace App\PaymentService\Helpers;

use App\PaymentService\Services\RazorpayService;

/**
 * Payment Helper
 * 
 * Convenience helper class for common payment operations.
 * This can be used throughout the application for quick payment operations.
 */
class PaymentHelper
{
    protected static $service = null;

    /**
     * Get payment service instance
     */
    protected static function getService(): RazorpayService
    {
        if (self::$service === null) {
            self::$service = app(RazorpayService::class);
        }
        return self::$service;
    }

    /**
     * Quick order creation
     */
    public static function createOrder(float $amount, string $currency = 'INR', array $options = []): array
    {
        $orderData = array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => 'order_' . time(),
            'payment_capture' => 1,
        ], $options);

        return self::getService()->createOrder($orderData);
    }

    /**
     * Quick payment verification
     */
    public static function verifyPayment(string $orderId, string $paymentId, string $signature): array
    {
        return self::getService()->verifyPayment($orderId, $paymentId, $signature);
    }

    /**
     * Quick payment link creation
     */
    public static function createPaymentLink(float $amount, array $customer = [], array $options = []): array
    {
        $linkData = array_merge([
            'amount' => $amount,
            'currency' => 'INR',
            'description' => 'Payment Link',
            'customer' => $customer,
        ], $options);

        return self::getService()->createPaymentLink($linkData);
    }

    /**
     * Check if in demo mode
     */
    public static function isDemoMode(): bool
    {
        return self::getService()->isDemoMode();
    }

    /**
     * Get Razorpay key
     */
    public static function getKeyId(): ?string
    {
        return self::getService()->getKeyId();
    }
}

