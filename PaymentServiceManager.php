<?php

namespace App\PaymentService\Services;

use App\PaymentService\Interfaces\PaymentServiceInterface;

/**
 * Payment Service Manager
 * 
 * A manager class that provides a unified interface for payment operations.
 * This makes it easier to switch between different payment providers in the future.
 */
class PaymentServiceManager
{
    protected $paymentService;

    public function __construct(PaymentServiceInterface $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Get the underlying payment service instance
     */
    public function getService(): PaymentServiceInterface
    {
        return $this->paymentService;
    }

    /**
     * Create a payment order
     */
    public function createOrder(array $orderData): array
    {
        return $this->paymentService->createOrder($orderData);
    }

    /**
     * Verify payment signature
     */
    public function verifyPayment(string $orderId, string $paymentId, string $signature): array
    {
        return $this->paymentService->verifyPayment($orderId, $paymentId, $signature);
    }

    /**
     * Fetch payment details
     */
    public function fetchPayment(string $paymentId): array
    {
        return $this->paymentService->fetchPayment($paymentId);
    }

    /**
     * Fetch order details
     */
    public function fetchOrder(string $orderId): array
    {
        return $this->paymentService->fetchOrder($orderId);
    }

    /**
     * Create a payment link
     */
    public function createPaymentLink(array $linkData): array
    {
        return $this->paymentService->createPaymentLink($linkData);
    }

    /**
     * Fetch payment link
     */
    public function fetchPaymentLink(string $linkId): array
    {
        return $this->paymentService->fetchPaymentLink($linkId);
    }

    /**
     * Check if service is in demo mode (if supported)
     */
    public function isDemoMode(): bool
    {
        if (method_exists($this->paymentService, 'isDemoMode')) {
            return $this->paymentService->isDemoMode();
        }
        return false;
    }

    /**
     * Get provider key ID (if supported)
     */
    public function getKeyId(): ?string
    {
        if (method_exists($this->paymentService, 'getKeyId')) {
            return $this->paymentService->getKeyId();
        }
        return null;
    }
}

